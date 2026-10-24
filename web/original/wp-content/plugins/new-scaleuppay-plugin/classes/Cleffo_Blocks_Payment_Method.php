<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * WooCommerce Blocks / Cart & Checkout block integration for Cleffo.
 */
class Cleffo_Blocks_Payment_Method extends AbstractPaymentMethodType {

	/**
	 * Payment method name — must match WC gateway id.
	 *
	 * @var string
	 */
	protected $name = 'suap_wordpresspayplugin';

	/**
	 * Load gateway settings from the options table.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_suap_wordpresspayplugin_settings', array() );
	}

	/**
	 * Only load block scripts when the gateway is enabled.
	 *
	 * @return bool
	 */
	public function is_active() {
		return filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'wc-payment-method-cleffo',
			SUA_Payments_PUBLIC . 'js/cleffo-blocks-checkout.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			'1.0.2',
			true
		);

		return array( 'wc-payment-method-cleffo' );
	}

	/**
	 * @return string[]
	 */
	public function get_payment_method_script_handles_for_admin() {
		return $this->get_payment_method_script_handles();
	}

	/**
	 * Data exposed client-side via wc.wcSettings.getSetting( 'suap_wordpresspayplugin_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$test_mode  = $this->get_setting( 'test_mode', 'test' );
		$client_key = $this->get_setting( 'agency7_client_key', '' );
		$iframe_url = add_query_arg(
			array(
				'mode'       => $test_mode,
				'client_key' => $client_key,
			),
			PAYMENTS_API_BASE_URL . '/card-element'
		);

		$default_description = __( 'Pay securely using a credit or debit card (Visa/MC/Amex). Powered by Cleffo', 'woo_mup_wordpresspayplugin' );
		$description         = trim( (string) $this->get_setting( 'description', '' ) );

		return array(
			'title'       => $this->get_setting( 'title', __( 'Credit Card (secure)', 'woo_mup_wordpresspayplugin' ) ),
			'description' => ( '' !== $description ) ? $description : $default_description,
			'supports'    => $this->get_supported_features(),
			'testMode'    => $test_mode,
			'clientKey'   => $client_key,
			'apiBaseUrl'  => PAYMENTS_API_BASE_URL,
			'iframeUrl'   => esc_url_raw( $iframe_url ),
		);
	}
}
