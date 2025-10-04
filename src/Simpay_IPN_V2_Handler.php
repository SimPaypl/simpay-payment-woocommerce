<?php

class SimPay_IPN_V2_Handler {

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
		if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
			$this->error( 'Method not allowed' );
		}

		if ( $validateIp ) {
			$request = wp_remote_get( 'https://api.simpay.pl/ip' );
			if ( is_wp_error( $request ) ) {
				$this->error( 'Could not validate IP address' );
			}
			$json = json_decode( $request['body'] );
			if ( ! in_array( $this->get_ip(), $json->data ) ) {
				$this->error( 'Invalid IP address: ' . $this->get_ip() );
			}
		}

		$payload = json_decode( @file_get_contents( 'php://input' ), true );
		if ( empty( $payload ) ) {
			$this->error( 'Cannot read payload' );
		}

		if ( empty( $payload['type'] ) ||
		     empty( $payload['notification_id'] ) ||
		     empty( $payload['date'] ) ||
		     empty( $payload['data'] ) ||
		     empty( $payload['signature'] )
		) {
			$this->error( 'Invalid payload - missing required fields' );
		}

		if ( ! hash_equals( $this->calculate_signature( $payload, $serviceHash ), $payload['signature'] ) ) {
			$this->error( 'Invalid signature' );
		}

		$data = $payload['data'];
		if ( isset( $data['service_id'] ) && $data['service_id'] !== $serviceId ) {
			$this->error( 'Invalid service_id' );
		}

		switch ( $payload['type'] ) {
			case 'transaction:status_changed':
				$this->handle_transaction_status_changed( $data );
				break;
			case 'transaction_refund:status_changed':
				$this->handle_refund_status_changed( $data );
				break;
			case 'ipn:test':
				$this->handle_test_notification( $data );
				break;
			case 'transaction_blik_level0:code_status_changed':
				$this->handle_blik_code_status_changed( $data );
				break;
			default:
				$this->error( 'Unknown event type: ' . $payload['type'] );
		}

		header( 'Content-Type: text/plain', true, 200 );
		echo 'OK';
		die();
	}

	/**
	 * Handle transaction status changed event
	 */
	private function handle_transaction_status_changed( array $data ) {
		if ( $data['status'] !== 'transaction_paid' ) {
			return;
		}

		if ( empty( $data['control'] ) ) {
			return;
		}

		$order = wc_get_order( (int) $data['control'] );
		if ( ! $order ) {
			$this->error( 'Order not found: ' . $data['control'] );
		}

		if ( in_array( $order->get_status(), [ 'completed', 'processing' ] ) ) {
			return;
		}

		$paid_amount = (float) $data['amount']['final_value'];
		$order_total = (float) $order->get_total();

		if ( $paid_amount < $order_total ) {
			$order->add_order_note( sprintf(
				'SimPay: Nieprawidłowa kwota płatności. Wymagano %s %s - otrzymano %s %s',
				$order_total,
				$order->get_currency(),
				$paid_amount,
				$data['amount']['final_currency']
			) );
			$this->error( 'Invalid payment amount' );
		}

		$order->payment_complete( $data['id'] );
		$order->add_order_note( sprintf(
			'SimPay: Płatność zakończona sukcesem. Kwota: %s %s, Kanał: %s, ID transakcji: %s',
			$paid_amount,
			$data['amount']['final_currency'],
			$data['payment']['channel'] ?? 'unknown',
			$data['id']
		) );
	}

	/**
	 * Handle refund status changed event
	 */
	private function handle_refund_status_changed( array $data ) {
		if ( $data['status'] !== 'refund_completed' ) {
			return;
		}

		$transaction_id = $data['transaction']['id'] ?? null;
		if ( ! $transaction_id ) {
			return;
		}

		$orders = wc_get_orders( [
			'meta_key'   => '_transaction_id',
			'meta_value' => $transaction_id,
			'limit'      => 1,
		] );

		if ( empty( $orders ) ) {
			return;
		}

		$order = $orders[0];
		$order->add_order_note( sprintf(
			'SimPay: Zwrot środków zakończony sukcesem. Kwota: %s %s, ID zwrotu: %s',
			$data['amount']['value'],
			$data['amount']['currency'],
			$data['id']
		) );
	}

	/**
	 * Handle test notification
	 */
	private function handle_test_notification( array $data ) {
		error_log( 'SimPay IPN v2 Test notification received for service: ' . $data['service_id'] );
	}

	/**
	 * Handle BLIK Level 0 code status changed event
	 */
	private function handle_blik_code_status_changed( array $data ) {
		$transaction = $data['transaction'] ?? [];
		
		if ( empty( $transaction['control'] ) || $transaction['status'] !== 'transaction_paid' ) {
			return;
		}

		$order = wc_get_order( (int) $transaction['control'] );
		if ( ! $order ) {
			return;
		}

		$order->add_order_note( sprintf(
			'SimPay BLIK: Status kodu zmieniony na %s. Status transakcji: %s',
			$data['ticket_status'],
			$transaction['status']
		) );
	}

	/**
	 * Calculate IPN v2 signature
	 */
	private function calculate_signature( array $payload, string $serviceHash ): string {
		$signature_data = [
			$payload['type'],
			$payload['notification_id'],
			$payload['date']
		];

		$data_values = $this->extract_data_values( $payload['type'], $payload['data'] );
		$signature_data = array_merge( $signature_data, $data_values );
		$signature_data[] = $serviceHash;

		return hash( 'sha256', implode( '|', $signature_data ) );
	}

	/**
	 * Extract data values in correct order for signature calculation
	 */
	private function extract_data_values( string $event_type, array $data ): array {
		$values = [];

		switch ( $event_type ) {
			case 'transaction:status_changed':
				$values[] = $data['id'];
				$values[] = $data['payer_transaction_id'];
				$values[] = $data['service_id'];
				$values[] = $data['status'];
				
				$values[] = $data['amount']['final_currency'];
				$values[] = $data['amount']['final_value'];
				$values[] = $data['amount']['original_currency'];
				$values[] = $data['amount']['original_value'];
				
				if ( isset( $data['amount']['commission_system'] ) ) {
					$values[] = $data['amount']['commission_system'];
				}
				if ( isset( $data['amount']['commission_partner'] ) ) {
					$values[] = $data['amount']['commission_partner'];
				}
				if ( isset( $data['amount']['commission_currency'] ) ) {
					$values[] = $data['amount']['commission_currency'];
				}
				
				if ( isset( $data['control'] ) ) {
					$values[] = $data['control'];
				}
				
				$values[] = $data['payment']['channel'];
				$values[] = $data['payment']['type'];
				
				if ( isset( $data['customer']['country_code'] ) ) {
					$values[] = $data['customer']['country_code'];
				}
				
				if ( isset( $data['paid_at'] ) ) {
					$values[] = $data['paid_at'];
				}
				
				$values[] = $data['created_at'];
				break;

			case 'transaction_refund:status_changed':
				$values[] = $data['id'];
				$values[] = $data['service_id'];
				$values[] = $data['status'];
				
				$values[] = $data['amount']['currency'];
				$values[] = $data['amount']['value'];
				$values[] = $data['amount']['wallet_currency'];
				$values[] = $data['amount']['wallet_value'];
				
				$values[] = $data['transaction']['id'];
				$values[] = $data['transaction']['payment_channel'];
				$values[] = $data['transaction']['payment_type'];
				break;

			case 'ipn:test':
				$values[] = $data['service_id'];
				$values[] = $data['nonce'];
				break;

			case 'transaction_blik_level0:code_status_changed':
				$values[] = $data['ticket_status'];
				
				$transaction = $data['transaction'];
				$values[] = $transaction['id'];
				$values[] = $transaction['payer_transaction_id'];
				$values[] = $transaction['service_id'];
				$values[] = $transaction['status'];
				
				$values[] = $transaction['amount']['final_currency'];
				$values[] = $transaction['amount']['final_value'];
				$values[] = $transaction['amount']['original_currency'];
				$values[] = $transaction['amount']['original_value'];
				
				if ( isset( $transaction['amount']['commission_system'] ) ) {
					$values[] = $transaction['amount']['commission_system'];
				}
				if ( isset( $transaction['amount']['commission_partner'] ) ) {
					$values[] = $transaction['amount']['commission_partner'];
				}
				if ( isset( $transaction['amount']['commission_currency'] ) ) {
					$values[] = $transaction['amount']['commission_currency'];
				}
				
				if ( isset( $transaction['control'] ) ) {
					$values[] = $transaction['control'];
				}
				break;
		}

		return $values;
	}

	/**
	 * Send error response and exit
	 */
	private function error( string $message ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/plain', true, 400 );
		}

		echo $message;
		die();
	}

	/**
	 * Get client IP address
	 */
	private function get_ip(): string {
		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$ip = $_SERVER['REMOTE_ADDR'];
		}

		return apply_filters( 'wpb_get_ip', $ip );
	}
}