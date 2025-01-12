<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SimPay_Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'simpay_payment_gateway';
		$this->has_fields         = false;
		$this->method_title       = 'SimPay.pl';
		$this->method_description = 'Wygodne przyjmowanie płatności online i BLIK';
		$this->init_form_fields();
		$this->init_settings();
		$this->title = $this->get_option( 'title', 'SimPay.pl' );
		$this->description =$this->get_option( 'description', 'Zapłać przez SimPay.pl' );

		$this->supports = array(
			'products'
		);

		add_action( 'woocommerce_api_' . $this->id, array(
			$this,
			'simpay_handle_ipn',
		) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options',
		) );
	}

	public function simpay_handle_ipn() {
		return ( new SimPay_IPN_Handler() )->handle(
			$this->get_option( 'simpay_service_hash' ),
			$this->get_option( 'simpay_service_id' ),
			$this->get_option( 'simpay_bearer' ),
            $this->get_option('simpay_ipn_check_ip') === 'yes',
		);
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'             => array(
				'title'       => __( 'Włącz / Wyłącz', 'simpay_woocommerce_payment' ),
				'description' => __( 'Włącz płatności SimPay', 'simpay_woocommerce_payment' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
			),
			'title'               => array(
				'title'       => __( 'Tytuł metody płatności', 'simpay_woocommerce_payment' ),
				'description' => __( 'Ten tytuł będzie widoczny dla kupującego w momencie wybierania metody płatności', 'simpay_woocommerce_payment' ),
				'type'        => 'text',
				'default'     => __( 'SimPay.pl', 'woocommerce' ),
			),
			'description'               => array(
				'title'       => __( 'Opis metody płatności', 'simpay_woocommerce_payment' ),
				'description' => __( 'Ten opis będzie widoczny dla kupującego w momencie wybierania metody płatności', 'simpay_woocommerce_payment' ),
				'type'        => 'text',
				'default'     => __( 'Przelewy Online i BLIK', 'woocommerce' ),
			),
			'simpay_bearer'       => array(
				'title'       => __( 'Hasło / Bearer Token', 'simpay_woocommerce_payment' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Hasło / Bearer Token znajdziesz w Panelu Klienta w zakładce "Konto Klienta" > "API" > {WYBÓR KLUCZA} > "Szczegóły"', 'simpay_woocommerce_payment' ),
			),
			'simpay_service_id'   => array(
				'title'       => __( 'ID usługi', 'simpay_woocommerce_payment' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'ID usługi znajdziesz w Panelu Klienta w zakładce "Płatności online" > "Usługi" > {WYBÓR USŁUGI} > "Szczegóły" > "ID"', 'simpay_woocommerce_payment' ),
			),
			'simpay_service_hash' => array(
				'title'       => __( 'Klucz do sygnatury IPN usługi', 'simpay_woocommerce_payment' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Klucz do sygnatury IPN usługi znajdziesz w Panelu Klienta w zakładce "Płatności online" > "Usługi" > {WYBÓR USŁUGI} > "Szczegóły" > "Ustawienia usługi"', 'simpay_woocommerce_payment' ),
			),
			'simpay_ipn_check_ip' => array(
				'title'       => __( 'Walidacja adresu IP', 'simpay_woocommerce_payment' ),
				'description' => __( 'Włącz walidację adresu IP przy przychodzącym IPN. (Jeżeli Twój sklep stoi za CloudFlare radzimy wyłączyć)', 'simpay_woocommerce_payment' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
			),
		);
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$payload = array(
			'amount'      => (float) $order->get_total(),
			'currency'    => $order->get_currency(),
			'description' => 'Zamówienie ' . $order->get_order_number(),
			'control'     => (string) $order_id,
			'customer'    => array(
				'name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'email' => $order->get_billing_email(),
				'ip'    => $order->get_customer_ip_address(),
			),
			'antifraud'   => array(
				'useragent' => $order->get_customer_user_agent(),
			),
			'billing'     => array(
				'name'       => $order->get_billing_first_name(),
				'surname'    => $order->get_billing_last_name(),
				'street'     => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
				'city'       => $order->get_billing_city(),
				'postalCode' => $order->get_billing_postcode(),
				'country'    => $order->get_billing_country(),
				'company'    => $order->get_billing_company(),
			),
			'shipping'    => array(
				'name'       => $order->get_shipping_first_name(),
				'surname'    => $order->get_shipping_last_name(),
				'street'     => $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2(),
				'city'       => $order->get_shipping_city(),
				'postalCode' => $order->get_shipping_postcode(),
				'country'    => $order->get_shipping_country(),
				'company'    => $order->get_shipping_company(),
			),
			'returns'     => array(
				'success' => $this->get_return_url( $order ),
				'failure' => $this->get_return_url( $order ),
			)
		);

		$request = wp_remote_post( sprintf( 'https://api.simpay.pl/payment/%s/transactions', $this->get_option( 'simpay_service_id' ) ), array(
			'body'        => json_encode( $payload ),
			'method'      => 'POST',
			'headers'     => array(
				'Accept'        => 'application/json; charset=utf-8',
				'Content-Type'  => 'application/json; charset=utf-8',
				'Authorization' => 'Bearer ' . $this->get_option( 'simpay_bearer' ),
			),
			'data_format' => 'body',
		) );

		$response = json_decode( $request['body'] );

		if ( empty( $response->data->redirectUrl ) ) {
			wc_add_notice( 'SimPay init error: ' . json_encode( $response ), 'error' );

			return array(
				'result' => 'failure',
			);
		}

		$order->add_order_note( 'SimPay ID: ' . $response->data->transactionId, 1 );

		return array(
			'result'   => 'success',
			'redirect' => $response->data->redirectUrl,
		);
	}

	public function admin_options() {
		?>
        <h2><?php esc_html_e( 'SimPay.pl Płatności Online / Blik', 'simpay_woocommerce_payment' ); ?></h2>

        <div style="margin-bottom:40px;">
            <h4>Adres IPN do ustawienia w Panelu SimPay:</h4>
            <code>
				<?= str_replace( 'http:', 'https:', add_query_arg( 'wc-api', $this->id, home_url( '/' ) ) ) ?>
            </code>
        </div>

        <table class="form-table">
			<?php $this->generate_settings_html(); ?>
        </table>
		<?php
	}
}