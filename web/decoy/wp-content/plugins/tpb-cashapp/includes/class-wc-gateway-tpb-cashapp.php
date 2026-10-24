<?php
/**
 * Cash App gateway. Checkout places the order on hold until an admin confirms it.
 *
 * @package Peak_Cashapp
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_Tpb_Cashapp extends WC_Payment_Gateway {

	/**
	 * Set up the gateway.
	 */
	public function __construct() {
		$this->id                 = 'tpb_cashapp';
		$this->icon               = '';
		$this->has_fields         = true;
		$this->method_title       = __( 'Cash App', 'tpb-cashapp' );
		$this->method_description = __( 'Customers pay Peak with Cash App. The order stays on hold until an admin confirms the payment, then it moves to processing and the customer is emailed.', 'tpb-cashapp' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title   = $this->get_option( 'title', 'Cash App' );
		$this->enabled = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * WooCommerce → Settings → Payments → Cash App.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'      => array(
				'title'   => __( 'Enable Cash App', 'tpb-cashapp' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept Cash App at checkout', 'tpb-cashapp' ),
				'default' => 'yes',
			),
			'title'        => array(
				'title'       => __( 'Title', 'tpb-cashapp' ),
				'type'        => 'text',
				'description' => __( 'Label shown next to the payment method.', 'tpb-cashapp' ),
				'default'     => 'Cash App',
			),
			'account_name' => array(
				'title'       => __( 'Account name', 'tpb-cashapp' ),
				'type'        => 'text',
				'description' => __( 'Name customers should see on the Cash App profile.', 'tpb-cashapp' ),
				'default'     => 'Peak',
			),
			'cashtag'      => array(
				'title'       => __( '$Cashtag', 'tpb-cashapp' ),
				'type'        => 'text',
				'description' => __( 'Store cashtag payments are sent to.', 'tpb-cashapp' ),
				'default'     => '$PEAK',
			),
			'qr_url'       => array(
				'title'       => __( 'QR image URL', 'tpb-cashapp' ),
				'type'        => 'text',
				'description' => __( 'Optional. Leave blank to use the bundled Peak Cash App card.', 'tpb-cashapp' ),
				'default'     => '',
			),
		);
	}

	/**
	 * Normalize the cashtag before it is stored.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		$tag   = tpb_cashapp_sanitize_cashtag( (string) $this->get_option( 'cashtag', '' ) );
		if ( $tag === '' ) {
			$tag = '$PEAK';
		}
		$this->update_option( 'cashtag', $tag );
		return $saved;
	}

	/**
	 * Green Cash App mark beside the radio label.
	 */
	public function get_icon(): string {
		$icon = '<span class="tpb-cashapp-mark" aria-hidden="true">Cash App</span>';
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * Checkout instructions and the customer's cashtag.
	 */
	public function payment_fields(): void {
		$account = tpb_cashapp_account();
		$total  = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_total() : '';
		$sender = function_exists( 'tpb_cashapp_posted_sender' ) ? tpb_cashapp_posted_sender() : '';

		echo '<div class="tpb-cashapp">';
		echo '<p class="tpb-cashapp__lead">';
		if ( $total !== '' ) {
			echo wp_kses(
				sprintf(
					/* translators: 1: cart total HTML, 2: cashtag */
					__( 'Place the order, then send %1$s to %2$s on Cash App. The order stays on hold until we confirm it.', 'tpb-cashapp' ),
					'<strong>' . wp_kses_post( $total ) . '</strong>',
					'<strong>' . esc_html( $account['cashtag'] ) . '</strong>'
				),
				array( 'strong' => array() )
			);
		} else {
			echo esc_html(
				sprintf(
					/* translators: %s: cashtag */
					__( 'Place the order, then send the total to %s on Cash App. The order stays on hold until we confirm it.', 'tpb-cashapp' ),
					$account['cashtag']
				)
			);
		}
		echo '</p>';

		echo '<ul class="tpb-cashapp__details">';
		echo '<li><span>' . esc_html__( 'Cash App name', 'tpb-cashapp' ) . '</span><strong>' . esc_html( $account['name'] ) . '</strong></li>';
		echo '<li><span>' . esc_html__( '$Cashtag', 'tpb-cashapp' ) . '</span>';
		echo '<span class="tpb-cashapp__copy-row"><strong data-tpb-cashapp-value>' . esc_html( $account['cashtag'] ) . '</strong>';
		echo '<button type="button" class="tpb-cashapp__copy" data-tpb-cashapp-copy>' . esc_html__( 'Copy', 'tpb-cashapp' ) . '</button></span></li>';
		echo '</ul>';

		if ( $account['qr'] !== '' ) {
			echo '<img class="tpb-cashapp__qr" src="' . esc_url( $account['qr'] ) . '" alt="' . esc_attr(
				sprintf(
					/* translators: %s: cashtag */
					__( 'Cash App QR code for %s', 'tpb-cashapp' ),
					$account['cashtag']
				)
			) . '" width="736" height="1012" />';
		}

		echo '<p class="form-row form-row-wide tpb-cashapp__sender" id="tpb_cashapp_sender_field">';
		echo '<label for="tpb_cashapp_sender">' . esc_html__( 'Your $Cashtag', 'tpb-cashapp' ) . '&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'tpb-cashapp' ) . '">*</abbr></label>';
		echo '<span class="woocommerce-input-wrapper"><input type="text" class="input-text" name="tpb_cashapp_sender" id="tpb_cashapp_sender" placeholder="$YourCashtag" autocomplete="off" value="' . esc_attr( $sender ) . '" /></span>';
		echo '</p>';
		echo '<p class="tpb-cashapp__confirm">' . esc_html__( 'After checkout, use your order number as the Cash App note. We email you when the order is on hold, and again when it moves to processing.', 'tpb-cashapp' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Require the cashtag the customer will pay from.
	 */
	public function validate_fields(): bool {
		$sender = tpb_cashapp_posted_sender();
		if ( $sender === '' ) {
			wc_add_notice( __( 'Enter the $Cashtag you will send the Cash App payment from.', 'tpb-cashapp' ), 'error' );
			return false;
		}
		return true;
	}

	/**
	 * Hold the order until an admin confirms the Cash App payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string,string>|void
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'We could not place this order. Please try again.', 'tpb-cashapp' ), 'error' );
			return;
		}

		$sender = tpb_cashapp_posted_sender();
		if ( $sender === '' ) {
			wc_add_notice( __( 'Enter the $Cashtag you will send the Cash App payment from.', 'tpb-cashapp' ), 'error' );
			return;
		}

		$account = tpb_cashapp_account();
		$order->update_meta_data( '_tpb_cashapp_sender', $sender );
		$order->update_meta_data( '_tpb_cashapp_cashtag', $account['cashtag'] );
		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: 1: store cashtag, 2: customer cashtag */
				__( 'Awaiting Cash App payment to %1$s from %2$s.', 'tpb-cashapp' ),
				$account['cashtag'],
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
