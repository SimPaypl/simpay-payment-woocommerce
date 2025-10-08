<?php

class SimPay_IPN_V2_Handler
{

	private function isValid($ipnKey): bool
	{
		$payload = json_decode(@file_get_contents('php://input'), true);
		if (empty($payload)) {
			return false;
		}

		$data = $this->flattenArray($payload);
		$data[] = $ipnKey;

		$signature = hash('sha256', implode('|', $data));

		return hash_equals($signature, $payload['signature']);
	}

	private function flattenArray(array $array): array
	{
		unset($array['signature']);

		$return = [];

		array_walk_recursive($array, function ($a) use (&$return) {
			$return[] = $a;
		});

		return $return;
	}

	/**
	 * Handle IPN v2 notifications
	 *
	 * @param string $serviceHash Service hash key from SimPay panel
	 * @param string $serviceId   Service ID
	 * @param bool   $validateIp  Whether to validate IP address
	 */
	public function handle(
		string $serviceHash,
		string $serviceId,
		bool $validateIp
	) {
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			$this->error('Method not allowed');
		}

		if ($validateIp) {
			$request = wp_remote_get('https://api.simpay.pl/ip');
			if (is_wp_error($request)) {
				$this->error('Could not validate IP address');
			}
			$json = json_decode($request['body']);
			if (! in_array($this->get_ip(), $json->data)) {
				$this->error('Invalid IP address: ' . $this->get_ip());
			}
		}

		$payload = json_decode(@file_get_contents('php://input'), true);
		if (empty($payload)) {
			$this->error('Cannot read payload');
		}

		if (
			empty($payload['type']) ||
			empty($payload['notification_id']) ||
			empty($payload['date']) ||
			empty($payload['data']) ||
			empty($payload['signature'])
		) {
			$this->error('Invalid payload - missing required fields');
		}

		if (!$this->isValid($serviceHash)) {
			$this->error('Invalid signature');
		}

		$data = $payload['data'];
		if (isset($data['service_id']) && $data['service_id'] !== $serviceId) {
			$this->error('Invalid service_id');
		}

		switch ($payload['type']) {
			case 'transaction:status_changed':
				$this->handle_transaction_status_changed($data);
				break;
			case 'transaction_refund:status_changed':
				$this->handle_refund_status_changed($data);
				break;
			case 'ipn:test':
				$this->handle_test_notification($data);
				break;
			case 'transaction_blik_level0:code_status_changed':
				break;
			default:
				$this->error('Unknown event type: ' . $payload['type']);
		}

		header('Content-Type: text/plain', true, 200);
		echo 'OK';
		die();
	}

	/**
	 * Handle transaction status changed event
	 */
	private function handle_transaction_status_changed(array $data)
	{
		if ($data['status'] !== 'transaction_paid') {
			return;
		}

		if (empty($data['control'])) {
			return;
		}

		$order = wc_get_order((int) $data['control']);
		if (! $order) {
			$this->error('Order not found: ' . $data['control']);
		}

		if (in_array($order->get_status(), ['completed', 'processing'])) {
			return;
		}

		$paid_amount = (float) $data['amount']['final_value'];
		$order_total = (float) $order->get_total();

		if ($paid_amount < $order_total) {
			$order->add_order_note(sprintf(
				'SimPay: Nieprawidłowa kwota płatności. Wymagano %s %s - otrzymano %s %s',
				$order_total,
				$order->get_currency(),
				$paid_amount,
				$data['amount']['final_currency']
			));
			$this->error('Invalid payment amount');
		}

		$order->payment_complete($data['id']);
		$order->add_order_note(sprintf(
			'SimPay: Płatność zakończona sukcesem. Kwota: %s %s, Kanał: %s, ID transakcji: %s',
			$paid_amount,
			$data['amount']['final_currency'],
			$data['payment']['channel'] ?? 'unknown',
			$data['id']
		));
	}

	/**
	 * Handle refund status changed event
	 */
	private function handle_refund_status_changed(array $data)
	{
		if ($data['status'] !== 'refund_completed') {
			return;
		}

		$transaction_id = $data['transaction']['id'] ?? null;
		if (! $transaction_id) {
			return;
		}

		$orders = wc_get_orders([
			'meta_key'   => '_transaction_id',
			'meta_value' => $transaction_id,
			'limit'      => 1,
		]);

		if (empty($orders)) {
			return;
		}

		$order = $orders[0];
		$refund_amount = (float) $data['amount']['value'];
		
		$existing_refunds = $order->get_refunds();
		$simpay_refund_id = $data['id'];
		
		foreach ($existing_refunds as $existing_refund) {
			if ($existing_refund->get_meta('_simpay_refund_id') === $simpay_refund_id) {
				$order->add_order_note(sprintf(
					'SimPay: Zwrot środków już został przetworzony. Kwota: %s %s, ID zwrotu: %s',
					$refund_amount,
					$data['amount']['currency'],
					$simpay_refund_id
				));
				return;
			}
		}

		$refund = wc_create_refund([
			'amount'   => $refund_amount,
			'reason'   => sprintf('SimPay - automatyczny zwrot środków (ID: %s)', $simpay_refund_id),
			'order_id' => $order->get_id(),
		]);

		if (is_wp_error($refund)) {
			$order->add_order_note(sprintf(
				'SimPay: Błąd podczas tworzenia zwrotu w WooCommerce. Kwota: %s %s, ID zwrotu: %s, Błąd: %s',
				$refund_amount,
				$data['amount']['currency'],
				$simpay_refund_id,
				$refund->get_error_message()
			));
			return;
		}

		$refund->update_meta_data('_simpay_refund_id', $simpay_refund_id);
		$refund->save();

		$order->add_order_note(sprintf(
			'SimPay: Zwrot środków zakończony sukcesem. Kwota: %s %s, ID zwrotu: %s, ID zwrotu WooCommerce: %s',
			$refund_amount,
			$data['amount']['currency'],
			$simpay_refund_id,
			$refund->get_id()
		));
	}

	/**
	 * Handle test notification
	 */
	private function handle_test_notification(array $data)
	{
		error_log('SimPay IPN v2 Test notification received for service: ' . $data['service_id']);
	}

	/**
	 * Handle BLIK Level 0 code status changed event
	 */
	private function handle_blik_code_status_changed(array $data)
	{
		return; // Currently no action needed
	}

	/**
	 * Send error response and exit
	 */
	private function error(string $message)
	{
		if (! headers_sent()) {
			header('Content-Type: text/plain', true, 400);
		}

		echo $message;
		die();
	}

	/**
	 * Get client IP address
	 */
	private function get_ip(): string
	{
		if (! empty($_SERVER['HTTP_CLIENT_IP'])) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$ip = $_SERVER['REMOTE_ADDR'];
		}

		return apply_filters('wpb_get_ip', $ip);
	}
}
