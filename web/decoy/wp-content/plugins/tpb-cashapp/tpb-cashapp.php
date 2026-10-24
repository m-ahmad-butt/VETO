<?php
/**
 * Plugin Name: Peak Cash App
 * Description: Cash App checkout for Peak. The order stays on hold until an admin confirms the payment, then it moves to processing and the customer is emailed.
 * Version: 1.0.0
 * Author: Peak
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: tpb-cashapp
 *
 * @package Peak_Cashapp
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TPB_CASHAPP_FILE', __FILE__ );
define( 'TPB_CASHAPP_DIR', plugin_dir_path( __FILE__ ) );
define( 'TPB_CASHAPP_URL', plugin_dir_url( __FILE__ ) );
define( 'TPB_CASHAPP_VERSION', '1.0.0' );

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TPB_CASHAPP_FILE, true );
		}
	}
);

/**
 * Bundled Cash App card (cashtag + QR).
 */
function tpb_cashapp_qr_url(): string {
	$file = TPB_CASHAPP_DIR . 'assets/cashapp-card.jpg';
	if ( ! is_readable( $file ) ) {
		return '';
	}
	return TPB_CASHAPP_URL . 'assets/cashapp-card.jpg';
}

/**
 * Store Cash App account. Settings override the defaults from the business card.
 *
 * @return array{name:string,cashtag:string,qr:string,pay_url:string}
 */
function tpb_cashapp_account(): array {
	$settings = (array) get_option( 'woocommerce_tpb_cashapp_settings', array() );
	$name     = isset( $settings['account_name'] ) ? trim( (string) $settings['account_name'] ) : '';
	$cashtag  = isset( $settings['cashtag'] ) ? tpb_cashapp_sanitize_cashtag( (string) $settings['cashtag'] ) : '';
	$qr       = isset( $settings['qr_url'] ) ? esc_url_raw( (string) $settings['qr_url'] ) : '';

	if ( $name === '' ) {
		$name = 'Peak';
	}
	if ( $cashtag === '' ) {
		$cashtag = '$PEAK';
	}
	if ( $qr === '' ) {
		$qr = tpb_cashapp_qr_url();
	}

	return array(
		'name'    => $name,
		'cashtag' => $cashtag,
		'qr'      => $qr,
		'pay_url' => 'https://cash.app/' . $cashtag,
	);
}

/**
 * Keep a customer or store cashtag as $name.
 */
function tpb_cashapp_sanitize_cashtag( string $raw ): string {
	$raw = trim( $raw );
	$raw = ltrim( $raw, '$' );
	$raw = preg_replace( '/[^A-Za-z0-9]/', '', $raw ) ?? '';
	if ( $raw === '' || strlen( $raw ) > 20 ) {
		return '';
	}
	return '$' . $raw;
}

/**
 * Cashtag typed at checkout, including order-review refreshes.
 */
function tpb_cashapp_posted_sender(): string {
	if ( isset( $_POST['tpb_cashapp_sender'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return tpb_cashapp_sanitize_cashtag( (string) wp_unslash( $_POST['tpb_cashapp_sender'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}
	if ( isset( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$data = array();
		wp_parse_str( (string) wp_unslash( $_POST['post_data'] ), $data ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $data['tpb_cashapp_sender'] ) ) {
			return tpb_cashapp_sanitize_cashtag( (string) $data['tpb_cashapp_sender'] );
		}
	}
	return '';
}

/**
 * True when the order was placed with this gateway.
 */
function tpb_cashapp_is_payment_method( string $method ): bool {
	return sanitize_key( $method ) === 'tpb_cashapp';
}

/**
 * Keep the gateway enabled once WooCommerce has loaded its settings.
 */
function tpb_cashapp_ensure_enabled(): void {
	$settings = (array) get_option( 'woocommerce_tpb_cashapp_settings', array() );
	if ( ( $settings['enabled'] ?? '' ) === 'yes' && ( $settings['cashtag'] ?? '' ) !== '' ) {
		return;
	}
	$settings['enabled']      = 'yes';
	$settings['title']        = ( isset( $settings['title'] ) && $settings['title'] !== '' ) ? (string) $settings['title'] : 'Cash App';
	$settings['account_name'] = ( isset( $settings['account_name'] ) && $settings['account_name'] !== '' ) ? (string) $settings['account_name'] : 'Peak';
	if ( tpb_cashapp_sanitize_cashtag( (string) ( $settings['cashtag'] ?? '' ) ) === '' ) {
		$settings['cashtag'] = '$PEAK';
	}
	update_option( 'woocommerce_tpb_cashapp_settings', $settings );
}

/**
 * Move a held Cash App order to processing and email the customer.
 */
function tpb_cashapp_confirm_payment( WC_Order $order ): bool {
	if ( ! tpb_cashapp_is_payment_method( (string) $order->get_payment_method() ) ) {
		return false;
	}
	if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
		return false;
	}

	$user = wp_get_current_user();
	$who  = ( $user instanceof WP_User && $user->exists() && $user->display_name !== '' )
		? $user->display_name
		: __( 'an admin', 'tpb-cashapp' );

	if ( ! $order->get_date_paid() ) {
		$order->set_date_paid( time() );
	}
	$order->update_meta_data( '_tpb_cashapp_confirmed', 'yes' );
	$order->update_meta_data( '_tpb_cashapp_confirmed_at', gmdate( 'c' ) );
	$order->update_meta_data( '_tpb_cashapp_confirmed_by', (string) get_current_user_id() );
	$order->update_status(
		'processing',
		sprintf(
			/* translators: %s: admin display name */
			__( 'Cash App payment confirmed by %s.', 'tpb-cashapp' ),
			$who
		),
		true
	);

	return true;
}

/**
 * Thank-you and account instructions: amount, cashtag, QR, order number for the note.
 */
function tpb_cashapp_render_thankyou_instructions( WC_Order $order ): void {
	$account = tpb_cashapp_account();
	$number  = $order->get_order_number();
	$total   = $order->get_formatted_order_total();
	?>
	<section class="tpb-thankyou__pay tpb-cashapp--thankyou" aria-labelledby="tpb-cashapp-pay-title">
		<h2 id="tpb-cashapp-pay-title" class="tpb-thankyou__panel-title"><?php esc_html_e( 'Complete your Cash App payment', 'tpb-cashapp' ); ?></h2>
		<p class="tpb-cashapp__lead">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: formatted order total, 2: cashtag */
					__( 'Send %1$s to %2$s on Cash App. Put your order number in the payment note so we can match it.', 'tpb-cashapp' ),
					'<strong>' . wp_kses_post( $total ) . '</strong>',
					'<strong>' . esc_html( $account['cashtag'] ) . '</strong>'
				),
				array( 'strong' => array() )
			);
			?>
		</p>
		<ul class="tpb-cashapp__details">
			<li>
				<span><?php esc_html_e( 'Order number', 'tpb-cashapp' ); ?></span>
				<span class="tpb-cashapp__copy-row">
					<strong data-tpb-cashapp-value><?php echo esc_html( $number ); ?></strong>
					<button type="button" class="tpb-cashapp__copy" data-tpb-cashapp-copy><?php esc_html_e( 'Copy', 'tpb-cashapp' ); ?></button>
				</span>
			</li>
			<li>
				<span><?php esc_html_e( 'Cash App name', 'tpb-cashapp' ); ?></span>
				<strong><?php echo esc_html( $account['name'] ); ?></strong>
			</li>
			<li>
				<span><?php esc_html_e( '$Cashtag', 'tpb-cashapp' ); ?></span>
				<span class="tpb-cashapp__copy-row">
					<strong data-tpb-cashapp-value><?php echo esc_html( $account['cashtag'] ); ?></strong>
					<button type="button" class="tpb-cashapp__copy" data-tpb-cashapp-copy><?php esc_html_e( 'Copy', 'tpb-cashapp' ); ?></button>
				</span>
			</li>
		</ul>
		<?php if ( $account['qr'] !== '' ) : ?>
			<img class="tpb-cashapp__qr" src="<?php echo esc_url( $account['qr'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: cashtag */ __( 'Cash App QR code for %s', 'tpb-cashapp' ), $account['cashtag'] ) ); ?>" width="736" height="1012" />
		<?php endif; ?>
		<p class="tpb-cashapp__confirm">
			<a href="<?php echo esc_url( $account['pay_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Cash App', 'tpb-cashapp' ); ?></a>
			<?php esc_html_e( 'This order stays on hold until we confirm the transfer. You will get an email now, and another when the order moves to processing.', 'tpb-cashapp' ); ?>
		</p>
	</section>
	<?php
}

/**
 * Payment steps inside the customer on-hold email.
 *
 * @param WC_Order $order         Order.
 * @param bool     $sent_to_admin Whether the email is for the store.
 * @param bool     $plain_text    Plain-text email.
 * @param WC_Email $email         Email object.
 */
function tpb_cashapp_email_instructions( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
	if ( ! $order instanceof WC_Order || ! tpb_cashapp_is_payment_method( (string) $order->get_payment_method() ) ) {
		return;
	}

	$email_id = ( is_object( $email ) && isset( $email->id ) ) ? (string) $email->id : '';
	$account  = tpb_cashapp_account();
	$sender   = (string) $order->get_meta( '_tpb_cashapp_sender' );
	$number   = $order->get_order_number();
	$total    = wp_strip_all_tags( (string) $order->get_formatted_order_total() );

	if ( $sent_to_admin && $email_id === 'new_order' ) {
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Cash App order. It stays on hold until you confirm the payment in WooCommerce.', 'tpb-cashapp' ) . "\n";
			if ( $sender !== '' ) {
				echo esc_html( sprintf( /* translators: %s: customer cashtag */ __( 'Customer $Cashtag: %s', 'tpb-cashapp' ), $sender ) ) . "\n\n";
			}
			return;
		}
		echo '<p style="margin:0 0 16px;padding:12px 14px;background:#f5f4ee;border:1px solid #e8e6dc;border-radius:8px;">';
		echo esc_html__( 'Cash App order. It stays on hold until you confirm the payment. Confirming moves it to Processing and emails the customer.', 'tpb-cashapp' );
		if ( $sender !== '' ) {
			echo '<br><strong>' . esc_html( sprintf( /* translators: %s: customer cashtag */ __( 'Customer $Cashtag: %s', 'tpb-cashapp' ), $sender ) ) . '</strong>';
		}
		echo '</p>';
		return;
	}

	if ( $sent_to_admin || ( $email_id !== '' && $email_id !== 'customer_on_hold_order' ) ) {
		return;
	}
	if ( $email_id === '' && ! $order->has_status( 'on-hold' ) ) {
		return;
	}

	if ( $plain_text ) {
		echo "\n";
		echo esc_html( sprintf( /* translators: 1: total, 2: cashtag, 3: account name */ __( 'Send %1$s to %2$s (%3$s) on Cash App.', 'tpb-cashapp' ), $total, $account['cashtag'], $account['name'] ) ) . "\n";
		echo esc_html( sprintf( /* translators: %s: order number */ __( 'Put order number %s in the Cash App note.', 'tpb-cashapp' ), $number ) ) . "\n";
		echo esc_html( $account['pay_url'] ) . "\n";
		echo esc_html__( 'Your order stays on hold until we confirm the payment. You will get another email when it moves to processing.', 'tpb-cashapp' ) . "\n\n";
		return;
	}

	echo '<div style="margin:0 0 20px;padding:16px;background:#f5f4ee;border:1px solid #e8e6dc;border-radius:10px;">';
	echo '<p style="margin:0 0 8px;font-size:16px;font-weight:700;color:#1c1917;">' . esc_html__( 'Pay with Cash App', 'tpb-cashapp' ) . '</p>';
	echo '<p style="margin:0 0 8px;color:#44403c;">';
	echo esc_html( sprintf( /* translators: 1: total, 2: cashtag */ __( 'Send %1$s to %2$s.', 'tpb-cashapp' ), $total, $account['cashtag'] ) );
	echo ' ' . esc_html( sprintf( /* translators: %s: order number */ __( 'Put order number %s in the note.', 'tpb-cashapp' ), $number ) );
	echo '</p>';
	echo '<p style="margin:0 0 8px;color:#44403c;"><strong>' . esc_html( $account['name'] ) . '</strong> · <a href="' . esc_url( $account['pay_url'] ) . '">' . esc_html( $account['cashtag'] ) . '</a></p>';
	if ( $account['qr'] !== '' ) {
		echo '<p style="margin:12px 0;"><img src="' . esc_url( $account['qr'] ) . '" alt="' . esc_attr( $account['cashtag'] ) . '" width="220" style="display:block;width:220px;max-width:100%;height:auto;border-radius:12px;" /></p>';
	}
	echo '<p style="margin:0;color:#44403c;">' . esc_html__( 'This order stays on hold until we confirm the transfer. You will get another email when it moves to processing.', 'tpb-cashapp' ) . '</p>';
	echo '</div>';
}
add_action( 'woocommerce_email_before_order_table', 'tpb_cashapp_email_instructions', 10, 4 );

/**
 * Held orders still need the payment card on the account order screen.
 *
 * @param int $order_id Order ID.
 */
function tpb_cashapp_view_order( $order_id ): void {
	$order = wc_get_order( (int) $order_id );
	if ( ! $order instanceof WC_Order || ! tpb_cashapp_is_payment_method( (string) $order->get_payment_method() ) ) {
		return;
	}
	if ( ! $order->has_status( 'on-hold' ) ) {
		return;
	}
	tpb_cashapp_render_thankyou_instructions( $order );
}
add_action( 'woocommerce_view_order', 'tpb_cashapp_view_order', 5 );

/**
 * Fallback thank-you output when the theme does not render its own card.
 *
 * @param int $order_id Order ID.
 */
function tpb_cashapp_thankyou( $order_id ): void {
	$order = wc_get_order( (int) $order_id );
	if ( $order instanceof WC_Order ) {
		tpb_cashapp_render_thankyou_instructions( $order );
	}
}
add_action( 'woocommerce_thankyou_tpb_cashapp', 'tpb_cashapp_thankyou' );

/**
 * Confirm action in the order actions menu.
 *
 * @param array<string,string> $actions Actions.
 * @param WC_Order|null        $order   Order.
 * @return array<string,string>
 */
function tpb_cashapp_order_actions( array $actions, $order = null ): array {
	if ( ! $order instanceof WC_Order ) {
		return $actions;
	}
	if ( tpb_cashapp_is_payment_method( (string) $order->get_payment_method() ) && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
		$actions['tpb_cashapp_confirm'] = __( 'Confirm Cash App payment', 'tpb-cashapp' );
	}
	return $actions;
}
add_filter( 'woocommerce_order_actions', 'tpb_cashapp_order_actions', 10, 2 );

/**
 * @param WC_Order $order Order.
 */
function tpb_cashapp_order_action_confirm( $order ): void {
	if ( $order instanceof WC_Order ) {
		tpb_cashapp_confirm_payment( $order );
	}
}
add_action( 'woocommerce_order_action_tpb_cashapp_confirm', 'tpb_cashapp_order_action_confirm' );

/**
 * Confirm from the orders list or the order screen button.
 */
function tpb_cashapp_admin_post_confirm(): void {
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_die( esc_html__( 'You cannot confirm this payment.', 'tpb-cashapp' ) );
	}
	$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
	check_admin_referer( 'tpb_cashapp_confirm_' . $order_id );

	$order = wc_get_order( $order_id );
	$url   = admin_url( 'admin.php?page=wc-orders' );
	if ( $order instanceof WC_Order ) {
		$url = $order->get_edit_order_url();
		if ( tpb_cashapp_confirm_payment( $order ) ) {
			$url = add_query_arg( 'tpb_cashapp_confirmed', '1', $url );
		} else {
			$url = add_query_arg( 'tpb_cashapp_confirmed', '0', $url );
		}
	}
	wp_safe_redirect( $url );
	exit;
}
add_action( 'admin_post_tpb_cashapp_confirm', 'tpb_cashapp_admin_post_confirm' );

/**
 * Orders list shortcut.
 *
 * @param array<string,array<string,string>> $actions Actions.
 * @param WC_Order                           $order   Order.
 * @return array<string,array<string,string>>
 */
function tpb_cashapp_admin_list_action( array $actions, $order ): array {
	if ( ! $order instanceof WC_Order ) {
		return $actions;
	}
	if ( ! tpb_cashapp_is_payment_method( (string) $order->get_payment_method() ) || ! $order->has_status( 'on-hold' ) ) {
		return $actions;
	}
	$actions['tpb_cashapp_confirm'] = array(
		'url'    => wp_nonce_url(
			admin_url( 'admin-post.php?action=tpb_cashapp_confirm&order_id=' . $order->get_id() ),
			'tpb_cashapp_confirm_' . $order->get_id()
		),
		'name'   => __( 'Confirm Cash App', 'tpb-cashapp' ),
		'action' => 'processing',
	);
	return $actions;
}
add_filter( 'woocommerce_admin_order_actions', 'tpb_cashapp_admin_list_action', 20, 2 );

/**
 * Cashtag plus confirm button on the order edit screen.
 *
 * @param WC_Order $order Order.
 */
function tpb_cashapp_admin_order_panel( $order ): void {
	if ( ! $order instanceof WC_Order || ! tpb_cashapp_is_payment_method( (string) $order->get_payment_method() ) ) {
		return;
	}
	$sender = (string) $order->get_meta( '_tpb_cashapp_sender' );
	$paid   = (string) $order->get_meta( '_tpb_cashapp_cashtag' );
	echo '<div class="tpb-cashapp-admin" style="margin-top:12px;padding:12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">';
	echo '<p style="margin:0 0 8px;"><strong>' . esc_html__( 'Cash App', 'tpb-cashapp' ) . '</strong></p>';
	if ( $paid !== '' ) {
		echo '<p style="margin:0 0 8px;">' . esc_html__( 'Pay to:', 'tpb-cashapp' ) . ' <code>' . esc_html( $paid ) . '</code></p>';
	}
	if ( $sender !== '' ) {
		echo '<p style="margin:0 0 8px;">' . esc_html__( 'Customer $Cashtag:', 'tpb-cashapp' ) . ' <code>' . esc_html( $sender ) . '</code></p>';
	}
	if ( $order->has_status( array( 'pending', 'on-hold' ) ) ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=tpb_cashapp_confirm&order_id=' . $order->get_id() ),
			'tpb_cashapp_confirm_' . $order->get_id()
		);
		echo '<p style="margin:0 0 8px;">' . esc_html__( 'This order stays on hold until you confirm the Cash App payment. Confirming moves it to Processing and emails the customer.', 'tpb-cashapp' ) . '</p>';
		echo '<p style="margin:0;"><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Confirm Cash App payment', 'tpb-cashapp' ) . '</a></p>';
	} elseif ( $order->has_status( array( 'processing', 'completed' ) ) ) {
		echo '<p style="margin:0;">' . esc_html__( 'Cash App payment confirmed. The customer is emailed when the order moves to processing.', 'tpb-cashapp' ) . '</p>';
	}
	echo '</div>';
}
add_action( 'woocommerce_admin_order_data_after_billing_address', 'tpb_cashapp_admin_order_panel' );

/**
 * Result notice after the confirm button.
 */
function tpb_cashapp_admin_notice(): void {
	if ( ! is_admin() || ! isset( $_GET['tpb_cashapp_confirmed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$ok = sanitize_text_field( wp_unslash( (string) $_GET['tpb_cashapp_confirmed'] ) ) === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $ok ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cash App payment confirmed. The order is processing and the customer has been emailed.', 'tpb-cashapp' ) . '</p></div>';
		return;
	}
	echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'This Cash App order was not moved to processing. It may already be confirmed.', 'tpb-cashapp' ) . '</p></div>';
}
add_action( 'admin_notices', 'tpb_cashapp_admin_notice' );

/**
 * @param array<int,string> $links Plugin links.
 * @return array<int,string>
 */
function tpb_cashapp_plugin_links( array $links ): array {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=tpb_cashapp' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'tpb-cashapp' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( TPB_CASHAPP_FILE ), 'tpb_cashapp_plugin_links' );

/**
 * Register the gateway after WooCommerce is available.
 */
function tpb_cashapp_boot(): void {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	require_once TPB_CASHAPP_DIR . 'includes/class-wc-gateway-tpb-cashapp.php';
	add_filter(
		'woocommerce_payment_gateways',
		static function ( array $methods ): array {
			$methods[] = 'WC_Gateway_Tpb_Cashapp';
			return $methods;
		}
	);
}

if ( did_action( 'plugins_loaded' ) ) {
	tpb_cashapp_boot();
} else {
	add_action( 'plugins_loaded', 'tpb_cashapp_boot', 11 );
}
