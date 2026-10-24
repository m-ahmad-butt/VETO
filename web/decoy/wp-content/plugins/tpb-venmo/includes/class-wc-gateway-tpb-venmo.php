<?php
/**
 * Venmo gateway. Checkout places the order on hold until an admin confirms it.
 *
 * @package Peak_Venmo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_Tpb_Venmo extends WC_Payment_Gateway {

	/**
	 * Set up the gateway.
	 */
	public function __construct() {
		$this->id                 = 'tpb_venmo';
		$this->icon               = '';
		$this->has_fields         = true;
		$this->method_title       = __( 'Venmo', 'tpb-venmo' );
		$this->method_description = __( 'Customers pay Peak with Venmo. The order stays on hold until an admin confirms the payment, then it moves to processing and the customer is emailed.', 'tpb-venmo' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title   = $this->get_option( 'title', 'Venmo' );
		$this->enabled = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'      => array(
				'title'   => __( 'Enable Venmo', 'tpb-venmo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept Venmo at checkout', 'tpb-venmo' ),
				'default' => 'yes',
			),
			'title'        => array(
				'title'   => __( 'Title', 'tpb-venmo' ),
				'type'    => 'text',
				'default' => 'Venmo',
			),
			'account_name' => array(
				'title'   => __( 'Account name', 'tpb-venmo' ),
				'type'    => 'text',
				'default' => 'Peak',
			),
			'username'     => array(
				'title'   => __( 'Venmo username', 'tpb-venmo' ),
				'type'    => 'text',
				'default' => '@PEAK',
			),
			'qr_url'       => array(
				'title'       => __( 'QR image URL', 'tpb-venmo' ),
				'type'        => 'text',
				'description' => __( 'Optional. Leave blank to use the bundled QR card.', 'tpb-venmo' ),
				'default'     => '',
			),
		);
	}

	/**
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		$user  = tpb_venmo_sanitize_username( (string) $this->get_option( 'username', '' ) );
		$this->update_option( 'username', $user !== '' ? $user : '@PEAK' );
		return $saved;
	}

	/**
	 * Blue Venmo mark.
	 */
	public function get_icon(): string {
		$icon = '<span class="tpb-venmo-mark" aria-hidden="true">Venmo</span>';
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * Checkout fields.
	 */
	public function payment_fields(): void {
		$account = tpb_venmo_account();
		$total   = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_total() : '';
		$sender  = tpb_venmo_posted_sender();

		echo '<div class="tpb-venmo">';
		echo '<p class="tpb-venmo__lead">';
		if ( $total !== '' ) {
			echo wp_kses(
				sprintf(
					/* translators: 1: cart total, 2: username */
					__( 'Place the order, then send %1$s to %2$s on Venmo. The order stays on hold until we confirm it.', 'tpb-venmo' ),
					'<strong>' . wp_kses_post( $total ) . '</strong>',
					'<strong>' . esc_html( $account['username'] ) . '</strong>'
				),
				array( 'strong' => array() )
			);
		} else {
			echo esc_html( sprintf( /* translators: %s: username */ __( 'Place the order, then send the total to %s on Venmo.', 'tpb-venmo' ), $account['username'] ) );
		}
		echo '</p>';
		echo '<ul class="tpb-venmo__details">';
		echo '<li><span>' . esc_html__( 'Venmo name', 'tpb-venmo' ) . '</span><strong>' . esc_html( $account['name'] ) . '</strong></li>';
		echo '<li><span>' . esc_html__( 'Username', 'tpb-venmo' ) . '</span><span class="tpb-venmo__copy-row"><strong data-tpb-venmo-value>' . esc_html( $account['username'] ) . '</strong>';
		echo '<button type="button" class="tpb-venmo__copy" data-tpb-venmo-copy>' . esc_html__( 'Copy', 'tpb-venmo' ) . '</button></span></li>';
		echo '</ul>';
		if ( $account['qr'] !== '' ) {
			echo '<img class="tpb-venmo__qr" src="' . esc_url( $account['qr'] ) . '" alt="' . esc_attr( $account['username'] ) . '" width="640" height="900" />';
		}
		echo '<p class="form-row form-row-wide tpb-venmo__sender" id="tpb_venmo_sender_field">';
		echo '<label for="tpb_venmo_sender">' . esc_html__( 'Your Venmo username', 'tpb-venmo' ) . '&nbsp;<abbr class="required" title="required">*</abbr></label>';
		echo '<span class="woocommerce-input-wrapper"><input type="text" class="input-text" name="tpb_venmo_sender" id="tpb_venmo_sender" placeholder="@YourUsername" autocomplete="off" value="' . esc_attr( $sender ) . '" /></span>';
		echo '</p>';
		echo '<p class="tpb-venmo__confirm">' . esc_html__( 'After checkout, use your order number as the Venmo note. We email you when the order is on hold, and again when it moves to processing.', 'tpb-venmo' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Require sender username.
	 */
	public function validate_fields(): bool {
		if ( tpb_venmo_posted_sender() === '' ) {
			wc_add_notice( __( 'Enter the Venmo username you will send the payment from.', 'tpb-venmo' ), 'error' );
			return false;
		}
		return true;
	}

	/**
	 * @param int $order_id Order ID.
	 * @return array<string,string>|void
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'We could not place this order. Please try again.', 'tpb-venmo' ), 'error' );
			return;
		}

		$sender = tpb_venmo_posted_sender();
		if ( $sender === '' ) {
			wc_add_notice( __( 'Enter the Venmo username you will send the payment from.', 'tpb-venmo' ), 'error' );
			return;
		}

		$account = tpb_venmo_account();
		$order->update_meta_data( '_tpb_venmo_sender', $sender );
		$order->update_meta_data( '_tpb_venmo_username', $account['username'] );
		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: 1: store username, 2: customer username */
				__( 'Awaiting Venmo payment to %1$s from %2$s.', 'tpb-venmo' ),
				$account['username'],
				$sender
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
