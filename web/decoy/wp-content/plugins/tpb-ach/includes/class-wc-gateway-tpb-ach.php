<?php
/**
 * ACH / bank transfer gateway. Orders stay on hold until an admin confirms payment.
 *
 * @package Peak_Ach
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_Tpb_Ach extends WC_Payment_Gateway {

	/**
	 * Set up the gateway.
	 */
	public function __construct() {
		$this->id                 = 'tpb_ach';
		$this->icon               = '';
		$this->has_fields         = true;
		$this->method_title       = __( 'ACH / Bank Transfer', 'tpb-ach' );
		$this->method_description = __( 'Customers pay by ACH or bank transfer. The order stays on hold until an admin confirms the payment, then it moves to processing and the customer is emailed.', 'tpb-ach' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title   = $this->get_option( 'title', 'ACH / Bank Transfer' );
		$this->enabled = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'      => array(
				'title'   => __( 'Enable ACH', 'tpb-ach' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept ACH / bank transfer at checkout', 'tpb-ach' ),
				'default' => 'yes',
			),
			'title'        => array(
				'title'   => __( 'Title', 'tpb-ach' ),
				'type'    => 'text',
				'default' => 'ACH / Bank Transfer',
			),
			'account_name' => array(
				'title'   => __( 'Account name', 'tpb-ach' ),
				'type'    => 'text',
				'default' => 'Peak',
			),
			'bank_name'    => array(
				'title'   => __( 'Bank name', 'tpb-ach' ),
				'type'    => 'text',
				'default' => 'Example Bank',
			),
			'account_type' => array(
				'title'   => __( 'Account type', 'tpb-ach' ),
				'type'    => 'text',
				'default' => 'Business checking',
			),
			'routing'      => array(
				'title'   => __( 'Routing number', 'tpb-ach' ),
				'type'    => 'text',
				'default' => '021000021',
			),
			'account'      => array(
				'title'   => __( 'Account number', 'tpb-ach' ),
				'type'    => 'text',
				'default' => '000123456789',
			),
		);
	}

	/**
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		$this->update_option( 'routing', tpb_ach_digits( (string) $this->get_option( 'routing', '' ) ) );
		$this->update_option( 'account', tpb_ach_digits( (string) $this->get_option( 'account', '' ) ) );
		return $saved;
	}

	/**
	 * Mark beside the radio.
	 */
	public function get_icon(): string {
		$icon = '<span class="tpb-ach-mark" aria-hidden="true">ACH</span>';
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * Checkout instructions.
	 */
	public function payment_fields(): void {
		$account = tpb_ach_account();
		$total   = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_total() : '';

		echo '<div class="tpb-ach">';
		echo '<p class="tpb-ach__lead">';
		if ( $total !== '' ) {
			echo wp_kses(
				sprintf(
					/* translators: %s: cart total */
					__( 'Place the order, then send %s by ACH or bank transfer. The order stays on hold until we confirm it.', 'tpb-ach' ),
					'<strong>' . wp_kses_post( $total ) . '</strong>'
				),
				array( 'strong' => array() )
			);
		} else {
			echo esc_html__( 'Place the order, then send the total by ACH or bank transfer. The order stays on hold until we confirm it.', 'tpb-ach' );
		}
		echo '</p>';
		echo '<ul class="tpb-ach__details">';
		echo '<li><span>' . esc_html__( 'Account name', 'tpb-ach' ) . '</span><strong>' . esc_html( $account['name'] ) . '</strong></li>';
		echo '<li><span>' . esc_html__( 'Bank', 'tpb-ach' ) . '</span><strong>' . esc_html( $account['bank'] ) . '</strong></li>';
		echo '<li><span>' . esc_html__( 'Routing number', 'tpb-ach' ) . '</span><span class="tpb-ach__copy-row"><strong data-tpb-ach-value>' . esc_html( $account['routing'] ) . '</strong>';
		echo '<button type="button" class="tpb-ach__copy" data-tpb-ach-copy>' . esc_html__( 'Copy', 'tpb-ach' ) . '</button></span></li>';
		echo '<li><span>' . esc_html__( 'Account number', 'tpb-ach' ) . '</span><span class="tpb-ach__copy-row"><strong data-tpb-ach-value>' . esc_html( $account['account'] ) . '</strong>';
		echo '<button type="button" class="tpb-ach__copy" data-tpb-ach-copy>' . esc_html__( 'Copy', 'tpb-ach' ) . '</button></span></li>';
		echo '</ul>';
		echo '<p class="tpb-ach__confirm">' . esc_html__( 'After checkout, use your order number as the ACH memo / reference. We email you when the order is on hold, and again when it moves to processing.', 'tpb-ach' ) . '</p>';
		echo '</div>';
	}

	/**
	 * @param int $order_id Order ID.
	 * @return array<string,string>|void
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'We could not place this order. Please try again.', 'tpb-ach' ), 'error' );
			return;
		}

		$account = tpb_ach_account();
		$order->update_meta_data( '_tpb_ach_routing', $account['routing'] );
		$order->update_meta_data( '_tpb_ach_account_last4', substr( $account['account'], -4 ) );
		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: 1: bank, 2: last 4 of account */
				__( 'Awaiting ACH / bank transfer to %1$s account ending %2$s.', 'tpb-ach' ),
				$account['bank'],
				substr( $account['account'], -4 )
			)
		);

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
