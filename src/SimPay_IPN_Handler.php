<?php

class SimPay_IPN_Handler {

	public function handle(
		string $serviceHash,
		string $serviceId,
		string $bearerToken,
		bool $validateIp,
	) {
		if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
			$this->error( 'Method not allowed' );
		}

		if ( $validateIp ) {
			$request = wp_remote_get( 'https://api.simpay.pl/ip' );
			$json    = json_decode( $request['body'] );
			if ( ! in_array( $this->get_ip(), $json->data ) ) {
				$this->error( 'invalid ip address: ' . $this->get_ip() );
			}
		}

		$payload = json_decode( @file_get_contents( 'php://input' ), true );
		if ( empty( $payload ) ) {
			$this->error( 'cannot read payload' );
		}

		if ( empty( $payload['id'] ) ||
		     empty( $payload['service_id'] ) ||
		     empty( $payload['status'] ) ||
		     empty( $payload['amount']['value'] ) ||
		     empty( $payload['amount']['currency'] ) ||
		     empty( $payload['control'] ) ||
		     empty( $payload['channel'] ) ||
		     empty( $payload['environment'] ) ||
		     empty( $payload['signature'] )
		) {
			$this->error( 'invalid payload' );
		}

		if ( ! hash_equals( $this->calculate_signature( $payload, $serviceHash ), $payload['signature'] ) ) {
			$this->error( 'invalid signature' );
		}

		if ( $payload['status'] !== 'transaction_paid' ) {
			header( 'Content-Type: text/plain', true, 200 );
			echo 'OK';
			die();
		}

		$order = wc_get_order( (int) $payload['control'] );
		if ( ! in_array( $order->get_status(), [ 'completed', 'processing' ] ) ) {
			if ( (float) $order->get_total() > (float) $payload['amount']['value'] ) {
				$order->add_order_note( sprintf(
					'Nie udało się przyjąć wpłaty do zamówienia nr %s - nieprawidłowa kwota (wymagano %s - dostano %s)',
					$order->get_id(),
					$order->get_total(),
					$payload['amount']['value'],
				) );
				$this->error( 'invalid order total' );
			}

			$order->payment_complete( $payload['id'] );
			$order->add_order_note( sprintf( 'Płatność SimPay na kwotę %s została przyjęta.', $payload['amount']['value'] ) );
		}

		header( 'Content-Type: text/plain', true, 200 );
		echo 'OK';
		die();
	}

	private function error( string $message ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/plain', true, 400 );
		}

		echo $message;
		die();
	}

	private function get_ip(): string {
		// credits: https://wpengine.com/resources/get-user-ip-wordpress/
		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$ip = $_SERVER['REMOTE_ADDR'];
		}

		return apply_filters( 'wpb_get_ip', $ip );
	}

	private function calculate_signature( array $payload, string $serviceHash ): string {
		unset( $payload['signature'] );

		$data   = $this->flatten_array( $payload );
		$data[] = $serviceHash;

		return hash( 'sha256', implode( '|', $data ) );
	}

	private function flatten_array( array $array ): array {
		$return = array();

		array_walk_recursive( $array, function ( $a ) use ( &$return ) {
			$return[] = $a;
		} );

		return $return;
	}
}