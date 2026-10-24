<?php
/**
 * Plugin Name: Peak Venmo
 * Description: Venmo checkout for Peak. The order stays on hold until an admin confirms the payment, then it moves to processing and the customer is emailed.
 * Version: 1.0.0
 * Author: Peak
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: tpb-venmo
 *
 * @package Peak_Venmo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TPB_VENMO_FILE', __FILE__ );
define( 'TPB_VENMO_DIR', plugin_dir_path( __FILE__ ) );
define( 'TPB_VENMO_URL', plugin_dir_url( __FILE__ ) );
define( 'TPB_VENMO_VERSION', '1.0.0' );

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TPB_VENMO_FILE, true );
		}
	}
);

/**
 * Bundled Venmo QR card.
 */
function tpb_venmo_qr_url(): string {
	$file = TPB_VENMO_DIR . 'assets/venmo-card.png';
	return is_readable( $file ) ? TPB_VENMO_URL . 'assets/venmo-card.png' : '';
}

/**
 * @return array{name:string,username:string,qr:string,pay_url:string}
 */
function tpb_venmo_account(): array {
	$settings = (array) get_option( 'woocommerce_tpb_venmo_settings', array() );
	$name     = isset( $settings['account_name'] ) ? trim( (string) $settings['account_name'] ) : '';
	$user     = isset( $settings['username'] ) ? tpb_venmo_sanitize_username( (string) $settings['username'] ) : '';
	$qr       = isset( $settings['qr_url'] ) ? esc_url_raw( (string) $settings['qr_url'] ) : '';

	if ( $name === '' ) {
		$name = 'Peak';
	}
	if ( $user === '' ) {
		$user = '@PEAK';
	}
	if ( $qr === '' ) {
		$qr = tpb_venmo_qr_url();
	}

	return array(
		'name'     => $name,
		'username' => $user,
		'qr'       => $qr,
		'pay_url'  => 'https://venmo.com/' . ltrim( $user, '@' ),
	);
}

/**
 * Keep a Venmo username as @handle.
 */
function tpb_venmo_sanitize_username( string $raw ): string {
	$raw = trim( $raw );
	$raw = ltrim( $raw, '@' );
	$raw = preg_replace( '/[^A-Za-z0-9_-]/', '', $raw ) ?? '';
	if ( $raw === '' || strlen( $raw ) > 30 ) {
		return '';
	}
	return '@' . $raw;
}

/**
 * Sender username from checkout POST / order review.
 */
function tpb_venmo_posted_sender(): string {
	if ( isset( $_POST['tpb_venmo_sender'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return tpb_venmo_sanitize_username( (string) wp_unslash( $_POST['tpb_venmo_sender'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}
	if ( isset( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$data = array();
		wp_parse_str( (string) wp_unslash( $_POST['post_data'] ), $data ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $data['tpb_venmo_sender'] ) ) {
			return tpb_venmo_sanitize_username( (string) $data['tpb_venmo_sender'] );
		}
	}
	return '';
}

/**
 * True when the order used this gateway.
 */
function tpb_venmo_is_payment_method( string $method ): bool {
	return sanitize_key( $method ) === 'tpb_venmo';
}

/**
 * Keep Venmo enabled with store defaults.
 */
function tpb_venmo_ensure_enabled(): void {
	$settings = (array) get_option( 'woocommerce_tpb_venmo_settings', array() );
	if ( ( $settings['enabled'] ?? '' ) === 'yes' && tpb_venmo_sanitize_username( (string) ( $settings['username'] ?? '' ) ) !== '' ) {
		return;
	}
	$settings['enabled']      = 'yes';
	$settings['title']        = ( isset( $settings['title'] ) && $settings['title'] !== '' ) ? (string) $settings['title'] : 'Venmo';
	$settings['account_name'] = ( isset( $settings['account_name'] ) && $settings['account_name'] !== '' ) ? (string) $settings['account_name'] : 'Peak';
	if ( tpb_venmo_sanitize_username( (string) ( $settings['username'] ?? '' ) ) === '' ) {
		$settings['username'] = '@PEAK';
	}
	update_option( 'woocommerce_tpb_venmo_settings', $settings );
}

/**
 * Move a held Venmo order to processing and email the customer.
 */
function tpb_venmo_confirm_payment( WC_Order $order ): bool {
	if ( ! tpb_venmo_is_payment_method( (string) $order->get_payment_method() ) ) {
		return false;
	}
	if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
		return false;
	}

	$user = wp_get_current_user();
	$who  = ( $user instanceof WP_User && $user->exists() && $user->display_name !== '' )
		? $user->display_name
		: __( 'an admin', 'tpb-venmo' );

	if ( ! $order->get_date_paid() ) {
		$order->set_date_paid( time() );
	}
	$order->update_meta_data( '_tpb_venmo_confirmed', 'yes' );
	$order->update_meta_data( '_tpb_venmo_confirmed_at', gmdate( 'c' ) );
	$order->update_meta_data( '_tpb_venmo_confirmed_by', (string) get_current_user_id() );
	$order->update_status(
		'processing',
		sprintf(
			/* translators: %s: admin display name */
			__( 'Venmo payment confirmed by %s.', 'tpb-venmo' ),
			$who
		),
		true
	);

	return true;
}

/**
 * Thank-you / account payment card.
 */
function tpb_venmo_render_thankyou_instructions( WC_Order $order ): void {
	$account = tpb_venmo_account();
	$number  = $order->get_order_number();
	$total   = $order->get_formatted_order_total();
	?>
	<section class="tpb-thankyou__pay tpb-venmo--thankyou" aria-labelledby="tpb-venmo-pay-title">
		<h2 id="tpb-venmo-pay-title" class="tpb-thankyou__panel-title"><?php esc_html_e( 'Complete your Venmo payment', 'tpb-venmo' ); ?></h2>
		<p class="tpb-venmo__lead">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: formatted order total, 2: username */
					__( 'Send %1$s to %2$s on Venmo. Put your order number in the payment note so we can match it.', 'tpb-venmo' ),
					'<strong>' . wp_kses_post( $total ) . '</strong>',
					'<strong>' . esc_html( $account['username'] ) . '</strong>'
				),
				array( 'strong' => array() )
			);
			?>
		</p>
		<ul class="tpb-venmo__details">
			<li>
				<span><?php esc_html_e( 'Order number', 'tpb-venmo' ); ?></span>
				<span class="tpb-venmo__copy-row">
					<strong data-tpb-venmo-value><?php echo esc_html( $number ); ?></strong>
					<button type="button" class="tpb-venmo__copy" data-tpb-venmo-copy><?php esc_html_e( 'Copy', 'tpb-venmo' ); ?></button>
				</span>
			</li>
			<li>
				<span><?php esc_html_e( 'Venmo name', 'tpb-venmo' ); ?></span>
				<strong><?php echo esc_html( $account['name'] ); ?></strong>
			</li>
			<li>
				<span><?php esc_html_e( 'Venmo username', 'tpb-venmo' ); ?></span>
				<span class="tpb-venmo__copy-row">
					<strong data-tpb-venmo-value><?php echo esc_html( $account['username'] ); ?></strong>
					<button type="button" class="tpb-venmo__copy" data-tpb-venmo-copy><?php esc_html_e( 'Copy', 'tpb-venmo' ); ?></button>
				</span>
			</li>
		</ul>
		<?php if ( $account['qr'] !== '' ) : ?>
			<img class="tpb-venmo__qr" src="<?php echo esc_url( $account['qr'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: username */ __( 'Venmo QR code for %s', 'tpb-venmo' ), $account['username'] ) ); ?>" width="640" height="900" />
		<?php endif; ?>
		<p class="tpb-venmo__confirm">
			<a href="<?php echo esc_url( $account['pay_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Venmo', 'tpb-venmo' ); ?></a>
			<?php esc_html_e( 'This order stays on hold until we confirm the transfer. You will get an email now, and another when the order moves to processing.', 'tpb-venmo' ); ?>
		</p>
	</section>
	<?php
}

/**
 * @param WC_Order $order         Order.
 * @param bool     $sent_to_admin Admin flag.
 * @param bool     $plain_text    Plain text.
 * @param WC_Email $email         Email.
 */
function tpb_venmo_email_instructions( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
	if ( ! $order instanceof WC_Order || ! tpb_venmo_is_payment_method( (string) $order->get_payment_method() ) ) {
		return;
	}

	$email_id = ( is_object( $email ) && isset( $email->id ) ) ? (string) $email->id : '';
	$account  = tpb_venmo_account();
	$sender   = (string) $order->get_meta( '_tpb_venmo_sender' );
	$number   = $order->get_order_number();
	$total    = wp_strip_all_tags( (string) $order->get_formatted_order_total() );

	if ( $sent_to_admin && $email_id === 'new_order' ) {
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Venmo order. Confirm the payment in WooCommerce to move it to processing.', 'tpb-venmo' ) . "\n";
			if ( $sender !== '' ) {
				echo esc_html( sprintf( /* translators: %s: username */ __( 'Customer Venmo: %s', 'tpb-venmo' ), $sender ) ) . "\n\n";
			}
			return;
		}
		echo '<p style="margin:0 0 16px;padding:12px 14px;background:#f5f4ee;border:1px solid #e8e6dc;border-radius:8px;">';
		echo esc_html__( 'Venmo order. Confirm the payment to move it to Processing and email the customer.', 'tpb-venmo' );
		if ( $sender !== '' ) {
			echo '<br><strong>' . esc_html( sprintf( /* translators: %s: username */ __( 'Customer Venmo: %s', 'tpb-venmo' ), $sender ) ) . '</strong>';
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
		echo "\n" . esc_html( sprintf( /* translators: 1: total, 2: username */ __( 'Send %1$s to %2$s on Venmo.', 'tpb-venmo' ), $total, $account['username'] ) ) . "\n";
		echo esc_html( sprintf( /* translators: %s: order number */ __( 'Put order number %s in the note.', 'tpb-venmo' ), $number ) ) . "\n";
		echo esc_html( $account['pay_url'] ) . "\n\n";
		return;
	}

	echo '<div style="margin:0 0 20px;padding:16px;background:#f5f4ee;border:1px solid #e8e6dc;border-radius:10px;">';
	echo '<p style="margin:0 0 8px;font-size:16px;font-weight:700;color:#1c1917;">' . esc_html__( 'Pay with Venmo', 'tpb-venmo' ) . '</p>';
	echo '<p style="margin:0 0 8px;color:#44403c;">' . esc_html( sprintf( /* translators: 1: total, 2: username */ __( 'Send %1$s to %2$s.', 'tpb-venmo' ), $total, $account['username'] ) );
	echo ' ' . esc_html( sprintf( /* translators: %s: order number */ __( 'Put order number %s in the note.', 'tpb-venmo' ), $number ) ) . '</p>';
	echo '<p style="margin:0 0 8px;color:#44403c;"><strong>' . esc_html( $account['name'] ) . '</strong> · <a href="' . esc_url( $account['pay_url'] ) . '">' . esc_html( $account['username'] ) . '</a></p>';
	if ( $account['qr'] !== '' ) {
		echo '<p style="margin:12px 0;"><img src="' . esc_url( $account['qr'] ) . '" alt="' . esc_attr( $account['username'] ) . '" width="220" style="display:block;width:220px;max-width:100%;height:auto;border-radius:12px;" /></p>';
	}
	echo '<p style="margin:0;color:#44403c;">' . esc_html__( 'This order stays on hold until we confirm the transfer. You will get another email when it moves to processing.', 'tpb-venmo' ) . '</p>';
	echo '</div>';
}
add_action( 'woocommerce_email_before_order_table', 'tpb_venmo_email_instructions', 10, 4 );

/**
 * @param int $order_id Order ID.
 */
function tpb_venmo_view_order( $order_id ): void {
	$order = wc_get_order( (int) $order_id );
	if ( $order instanceof WC_Order && tpb_venmo_is_payment_method( (string) $order->get_payment_method() ) && $order->has_status( 'on-hold' ) ) {
		tpb_venmo_render_thankyou_instructions( $order );
	}
}
add_action( 'woocommerce_view_order', 'tpb_venmo_view_order', 5 );

/**
 * @param int $order_id Order ID.
 */
function tpb_venmo_thankyou( $order_id ): void {
	$order = wc_get_order( (int) $order_id );
	if ( $order instanceof WC_Order ) {
		tpb_venmo_render_thankyou_instructions( $order );
	}
}
add_action( 'woocommerce_thankyou_tpb_venmo', 'tpb_venmo_thankyou' );

/**
 * @param array<string,string> $actions Actions.
 * @param WC_Order|null        $order   Order.
 * @return array<string,string>
 */
function tpb_venmo_order_actions( array $actions, $order = null ): array {
	if ( $order instanceof WC_Order && tpb_venmo_is_payment_method( (string) $order->get_payment_method() ) && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
		$actions['tpb_venmo_confirm'] = __( 'Confirm Venmo payment', 'tpb-venmo' );
	}
	return $actions;
}
add_filter( 'woocommerce_order_actions', 'tpb_venmo_order_actions', 10, 2 );

/**
 * @param WC_Order $order Order.
 */
function tpb_venmo_order_action_confirm( $order ): void {
	if ( $order instanceof WC_Order ) {
		tpb_venmo_confirm_payment( $order );
	}
}
add_action( 'woocommerce_order_action_tpb_venmo_confirm', 'tpb_venmo_order_action_confirm' );

/**
 * Confirm from admin button.
 */
function tpb_venmo_admin_post_confirm(): void {
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_die( esc_html__( 'You cannot confirm this payment.', 'tpb-venmo' ) );
	}
	$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
	check_admin_referer( 'tpb_venmo_confirm_' . $order_id );
	$order = wc_get_order( $order_id );
	$url   = admin_url( 'admin.php?page=wc-orders' );
	if ( $order instanceof WC_Order ) {
		$url = add_query_arg( 'tpb_venmo_confirmed', tpb_venmo_confirm_payment( $order ) ? '1' : '0', $order->get_edit_order_url() );
	}
	wp_safe_redirect( $url );
	exit;
}
add_action( 'admin_post_tpb_venmo_confirm', 'tpb_venmo_admin_post_confirm' );

/**
 * @param array<string,array<string,string>> $actions Actions.
 * @param WC_Order                           $order   Order.
 * @return array<string,array<string,string>>
 */
function tpb_venmo_admin_list_action( array $actions, $order ): array {
	if ( ! $order instanceof WC_Order || ! tpb_venmo_is_payment_method( (string) $order->get_payment_method() ) || ! $order->has_status( 'on-hold' ) ) {
		return $actions;
	}
	$actions['tpb_venmo_confirm'] = array(
		'url'    => wp_nonce_url( admin_url( 'admin-post.php?action=tpb_venmo_confirm&order_id=' . $order->get_id() ), 'tpb_venmo_confirm_' . $order->get_id() ),
		'name'   => __( 'Confirm Venmo', 'tpb-venmo' ),
		'action' => 'processing',
	);
	return $actions;
}
add_filter( 'woocommerce_admin_order_actions', 'tpb_venmo_admin_list_action', 20, 2 );

/**
 * @param WC_Order $order Order.
 */
function tpb_venmo_admin_order_panel( $order ): void {
	if ( ! $order instanceof WC_Order || ! tpb_venmo_is_payment_method( (string) $order->get_payment_method() ) ) {
		return;
	}
	$sender = (string) $order->get_meta( '_tpb_venmo_sender' );
	$paid   = (string) $order->get_meta( '_tpb_venmo_username' );
	echo '<div class="tpb-venmo-admin" style="margin-top:12px;padding:12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">';
	echo '<p style="margin:0 0 8px;"><strong>' . esc_html__( 'Venmo', 'tpb-venmo' ) . '</strong></p>';
	if ( $paid !== '' ) {
		echo '<p style="margin:0 0 8px;">' . esc_html__( 'Pay to:', 'tpb-venmo' ) . ' <code>' . esc_html( $paid ) . '</code></p>';
	}
	if ( $sender !== '' ) {
		echo '<p style="margin:0 0 8px;">' . esc_html__( 'Customer Venmo:', 'tpb-venmo' ) . ' <code>' . esc_html( $sender ) . '</code></p>';
	}
	if ( $order->has_status( array( 'pending', 'on-hold' ) ) ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=tpb_venmo_confirm&order_id=' . $order->get_id() ), 'tpb_venmo_confirm_' . $order->get_id() );
		echo '<p style="margin:0;"><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Confirm Venmo payment', 'tpb-venmo' ) . '</a></p>';
	}
	echo '</div>';
}
add_action( 'woocommerce_admin_order_data_after_billing_address', 'tpb_venmo_admin_order_panel' );

/**
 * Admin notice after confirm.
 */
function tpb_venmo_admin_notice(): void {
	if ( ! is_admin() || ! isset( $_GET['tpb_venmo_confirmed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$ok = sanitize_text_field( wp_unslash( (string) $_GET['tpb_venmo_confirmed'] ) ) === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	echo $ok
		? '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Venmo payment confirmed. The order is processing and the customer has been emailed.', 'tpb-venmo' ) . '</p></div>'
		: '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'This Venmo order was not moved to processing.', 'tpb-venmo' ) . '</p></div>';
}
add_action( 'admin_notices', 'tpb_venmo_admin_notice' );

/**
 * @param array<int,string> $links Links.
 * @return array<int,string>
 */
function tpb_venmo_plugin_links( array $links ): array {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=tpb_venmo' ) ) . '">' . esc_html__( 'Settings', 'tpb-venmo' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( TPB_VENMO_FILE ), 'tpb_venmo_plugin_links' );

/**
 * Boot gateway.
 */
function tpb_venmo_boot(): void {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	require_once TPB_VENMO_DIR . 'includes/class-wc-gateway-tpb-venmo.php';
	add_filter(
		'woocommerce_payment_gateways',
		static function ( array $methods ): array {
			$methods[] = 'WC_Gateway_Tpb_Venmo';
			return $methods;
		}
	);
}

if ( did_action( 'plugins_loaded' ) ) {
	tpb_venmo_boot();
} else {
	add_action( 'plugins_loaded', 'tpb_venmo_boot', 11 );
}
