<?php
/**
 * Plugin Name: Peak ACH / Bank Transfer
 * Description: Manual ACH / bank transfer checkout. The order stays on hold until an admin confirms the payment, then it moves to processing and the customer is emailed.
 * Version: 1.0.0
 * Author: Peak
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: tpb-ach
 *
 * @package Peak_Ach
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TPB_ACH_FILE', __FILE__ );
define( 'TPB_ACH_DIR', plugin_dir_path( __FILE__ ) );
define( 'TPB_ACH_URL', plugin_dir_url( __FILE__ ) );
define( 'TPB_ACH_VERSION', '1.0.0' );

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TPB_ACH_FILE, true );
		}
	}
);

/**
 * Read env / $_ENV / $_SERVER for ACH bank details.
 */
function tpb_ach_env( string $name ): string {
	$value = getenv( $name );
	if ( false === $value || null === $value || '' === trim( (string) $value ) ) {
		if ( isset( $_ENV[ $name ] ) && is_string( $_ENV[ $name ] ) ) {
			$value = $_ENV[ $name ];
		} elseif ( isset( $_SERVER[ $name ] ) && is_string( $_SERVER[ $name ] ) ) {
			$value = $_SERVER[ $name ];
		} else {
			return '';
		}
	}
	return trim( (string) $value );
}

/**
 * Digits only.
 */
function tpb_ach_digits( string $raw ): string {
	return preg_replace( '/\D+/', '', $raw ) ?? '';
}

/**
 * @return array{name:string,bank:string,routing:string,account:string,account_type:string}
 */
function tpb_ach_account(): array {
	$settings = (array) get_option( 'woocommerce_tpb_ach_settings', array() );
	$name     = isset( $settings['account_name'] ) ? trim( (string) $settings['account_name'] ) : '';
	$bank     = isset( $settings['bank_name'] ) ? trim( (string) $settings['bank_name'] ) : '';
	$routing  = isset( $settings['routing'] ) ? tpb_ach_digits( (string) $settings['routing'] ) : '';
	$account  = isset( $settings['account'] ) ? tpb_ach_digits( (string) $settings['account'] ) : '';
	$type     = isset( $settings['account_type'] ) ? trim( (string) $settings['account_type'] ) : '';

	if ( $name === '' ) {
		$name = 'Peak';
	}
	if ( $bank === '' ) {
		$bank = tpb_ach_env( 'ACH_BANK_NAME' );
		$bank = $bank !== '' ? $bank : 'Example Bank';
	}
	if ( $routing === '' ) {
		$routing = tpb_ach_digits( tpb_ach_env( 'ACH_ROUTING' ) );
		if ( $routing === '' ) {
			$routing = '021000021';
		}
	}
	if ( $account === '' ) {
		$account = tpb_ach_digits( tpb_ach_env( 'ACH_ACCOUNT' ) );
		if ( $account === '' ) {
			$account = '000123456789';
		}
	}
	if ( $type === '' ) {
		$type = 'Business checking';
	}

	return array(
		'name'         => $name,
		'bank'         => $bank,
		'routing'      => $routing,
		'account'      => $account,
		'account_type' => $type,
	);
}

/**
 * True when the order used this gateway.
 */
function tpb_ach_is_payment_method( string $method ): bool {
	return sanitize_key( $method ) === 'tpb_ach';
}

/**
 * Keep ACH enabled with store defaults.
 */
function tpb_ach_ensure_enabled(): void {
	$settings = (array) get_option( 'woocommerce_tpb_ach_settings', array() );
	$account  = tpb_ach_account();
	$needs    = ( $settings['enabled'] ?? '' ) !== 'yes'
		|| tpb_ach_digits( (string) ( $settings['routing'] ?? '' ) ) === ''
		|| tpb_ach_digits( (string) ( $settings['account'] ?? '' ) ) === '';
	if ( ! $needs ) {
		return;
	}
	$settings['enabled']       = 'yes';
	$settings['title']         = ( isset( $settings['title'] ) && $settings['title'] !== '' ) ? (string) $settings['title'] : 'ACH / Bank Transfer';
	$settings['account_name']  = ( isset( $settings['account_name'] ) && $settings['account_name'] !== '' ) ? (string) $settings['account_name'] : $account['name'];
	$settings['bank_name']     = ( isset( $settings['bank_name'] ) && $settings['bank_name'] !== '' ) ? (string) $settings['bank_name'] : $account['bank'];
	$settings['routing']       = $account['routing'];
	$settings['account']       = $account['account'];
	$settings['account_type']  = $account['account_type'];
	update_option( 'woocommerce_tpb_ach_settings', $settings );
}

/**
 * Confirm ACH and move to processing.
 */
function tpb_ach_confirm_payment( WC_Order $order ): bool {
	if ( ! tpb_ach_is_payment_method( (string) $order->get_payment_method() ) ) {
		return false;
	}
	if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
		return false;
	}

	$user = wp_get_current_user();
	$who  = ( $user instanceof WP_User && $user->exists() && $user->display_name !== '' )
		? $user->display_name
		: __( 'an admin', 'tpb-ach' );

	if ( ! $order->get_date_paid() ) {
		$order->set_date_paid( time() );
	}
	$order->update_meta_data( '_tpb_ach_confirmed', 'yes' );
	$order->update_meta_data( '_tpb_ach_confirmed_at', gmdate( 'c' ) );
	$order->update_meta_data( '_tpb_ach_confirmed_by', (string) get_current_user_id() );
	$order->update_status(
		'processing',
		sprintf(
			/* translators: %s: admin display name */
			__( 'ACH payment confirmed by %s.', 'tpb-ach' ),
			$who
		),
		true
	);

	return true;
}

/**
 * Thank-you / account bank instructions.
 */
function tpb_ach_render_thankyou_instructions( WC_Order $order ): void {
	$account = tpb_ach_account();
	$number  = $order->get_order_number();
	$total   = $order->get_formatted_order_total();
	?>
	<section class="tpb-thankyou__pay tpb-ach--thankyou" aria-labelledby="tpb-ach-pay-title">
		<h2 id="tpb-ach-pay-title" class="tpb-thankyou__panel-title"><?php esc_html_e( 'Complete your ACH / bank transfer', 'tpb-ach' ); ?></h2>
		<p class="tpb-ach__lead">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: formatted total */
					__( 'Send %s by ACH or bank transfer. Put your order number in the memo / reference so we can match it.', 'tpb-ach' ),
					'<strong>' . wp_kses_post( $total ) . '</strong>'
				),
				array( 'strong' => array() )
			);
			?>
		</p>
		<ul class="tpb-ach__details">
			<li>
				<span><?php esc_html_e( 'Order number', 'tpb-ach' ); ?></span>
				<span class="tpb-ach__copy-row">
					<strong data-tpb-ach-value><?php echo esc_html( $number ); ?></strong>
					<button type="button" class="tpb-ach__copy" data-tpb-ach-copy><?php esc_html_e( 'Copy', 'tpb-ach' ); ?></button>
				</span>
			</li>
			<li>
				<span><?php esc_html_e( 'Account name', 'tpb-ach' ); ?></span>
				<strong><?php echo esc_html( $account['name'] ); ?></strong>
			</li>
			<li>
				<span><?php esc_html_e( 'Bank', 'tpb-ach' ); ?></span>
				<strong><?php echo esc_html( $account['bank'] ); ?></strong>
			</li>
			<li>
				<span><?php esc_html_e( 'Account type', 'tpb-ach' ); ?></span>
				<strong><?php echo esc_html( $account['account_type'] ); ?></strong>
			</li>
			<li>
				<span><?php esc_html_e( 'Routing number', 'tpb-ach' ); ?></span>
				<span class="tpb-ach__copy-row">
					<strong data-tpb-ach-value><?php echo esc_html( $account['routing'] ); ?></strong>
					<button type="button" class="tpb-ach__copy" data-tpb-ach-copy><?php esc_html_e( 'Copy', 'tpb-ach' ); ?></button>
				</span>
			</li>
			<li>
				<span><?php esc_html_e( 'Account number', 'tpb-ach' ); ?></span>
				<span class="tpb-ach__copy-row">
					<strong data-tpb-ach-value><?php echo esc_html( $account['account'] ); ?></strong>
					<button type="button" class="tpb-ach__copy" data-tpb-ach-copy><?php esc_html_e( 'Copy', 'tpb-ach' ); ?></button>
				</span>
			</li>
		</ul>
		<p class="tpb-ach__confirm">
			<?php esc_html_e( 'This order stays on hold until we confirm the transfer. You will get an email now, and another when the order moves to processing.', 'tpb-ach' ); ?>
		</p>
	</section>
	<?php
}

/**
 * @param WC_Order $order         Order.
 * @param bool     $sent_to_admin Admin.
 * @param bool     $plain_text    Plain.
 * @param WC_Email $email         Email.
 */
function tpb_ach_email_instructions( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
	if ( ! $order instanceof WC_Order || ! tpb_ach_is_payment_method( (string) $order->get_payment_method() ) ) {
		return;
	}

	$email_id = ( is_object( $email ) && isset( $email->id ) ) ? (string) $email->id : '';
	$account  = tpb_ach_account();
	$number   = $order->get_order_number();
	$total    = wp_strip_all_tags( (string) $order->get_formatted_order_total() );

	if ( $sent_to_admin && $email_id === 'new_order' ) {
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'ACH order. Confirm the bank transfer in WooCommerce to move it to processing.', 'tpb-ach' ) . "\n\n";
			return;
		}
		echo '<p style="margin:0 0 16px;padding:12px 14px;background:#f5f4ee;border:1px solid #e8e6dc;border-radius:8px;">';
		echo esc_html__( 'ACH / bank transfer order. Confirm the payment to move it to Processing and email the customer.', 'tpb-ach' );
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
		echo "\n" . esc_html( sprintf( /* translators: %s: total */ __( 'Send %s by ACH / bank transfer.', 'tpb-ach' ), $total ) ) . "\n";
		echo esc_html( sprintf( /* translators: %s: order number */ __( 'Memo / reference: order %s', 'tpb-ach' ), $number ) ) . "\n";
		echo esc_html( sprintf( /* translators: %s: name */ __( 'Account name: %s', 'tpb-ach' ), $account['name'] ) ) . "\n";
		echo esc_html( sprintf( /* translators: %s: bank */ __( 'Bank: %s', 'tpb-ach' ), $account['bank'] ) ) . "\n";
		echo esc_html( sprintf( /* translators: %s: routing */ __( 'Routing: %s', 'tpb-ach' ), $account['routing'] ) ) . "\n";
		echo esc_html( sprintf( /* translators: %s: account */ __( 'Account: %s', 'tpb-ach' ), $account['account'] ) ) . "\n\n";
		return;
	}

	echo '<div style="margin:0 0 20px;padding:16px;background:#f5f4ee;border:1px solid #e8e6dc;border-radius:10px;">';
	echo '<p style="margin:0 0 8px;font-size:16px;font-weight:700;color:#1c1917;">' . esc_html__( 'Pay by ACH / bank transfer', 'tpb-ach' ) . '</p>';
	echo '<p style="margin:0 0 8px;color:#44403c;">' . esc_html( sprintf( /* translators: 1: total, 2: order */ __( 'Send %1$s. Use order number %2$s as the memo.', 'tpb-ach' ), $total, $number ) ) . '</p>';
	echo '<p style="margin:0;color:#44403c;line-height:1.6;">';
	echo esc_html__( 'Account name:', 'tpb-ach' ) . ' <strong>' . esc_html( $account['name'] ) . '</strong><br>';
	echo esc_html__( 'Bank:', 'tpb-ach' ) . ' <strong>' . esc_html( $account['bank'] ) . '</strong><br>';
	echo esc_html__( 'Routing:', 'tpb-ach' ) . ' <strong>' . esc_html( $account['routing'] ) . '</strong><br>';
	echo esc_html__( 'Account:', 'tpb-ach' ) . ' <strong>' . esc_html( $account['account'] ) . '</strong>';
	echo '</p></div>';
}
add_action( 'woocommerce_email_before_order_table', 'tpb_ach_email_instructions', 10, 4 );

/**
 * @param int $order_id Order ID.
 */
function tpb_ach_view_order( $order_id ): void {
	$order = wc_get_order( (int) $order_id );
	if ( $order instanceof WC_Order && tpb_ach_is_payment_method( (string) $order->get_payment_method() ) && $order->has_status( 'on-hold' ) ) {
		tpb_ach_render_thankyou_instructions( $order );
	}
}
add_action( 'woocommerce_view_order', 'tpb_ach_view_order', 5 );

/**
 * @param int $order_id Order ID.
 */
function tpb_ach_thankyou( $order_id ): void {
	$order = wc_get_order( (int) $order_id );
	if ( $order instanceof WC_Order ) {
		tpb_ach_render_thankyou_instructions( $order );
	}
}
add_action( 'woocommerce_thankyou_tpb_ach', 'tpb_ach_thankyou' );

/**
 * @param array<string,string> $actions Actions.
 * @param WC_Order|null        $order   Order.
 * @return array<string,string>
 */
function tpb_ach_order_actions( array $actions, $order = null ): array {
	if ( $order instanceof WC_Order && tpb_ach_is_payment_method( (string) $order->get_payment_method() ) && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
		$actions['tpb_ach_confirm'] = __( 'Confirm ACH payment', 'tpb-ach' );
	}
	return $actions;
}
add_filter( 'woocommerce_order_actions', 'tpb_ach_order_actions', 10, 2 );

/**
 * @param WC_Order $order Order.
 */
function tpb_ach_order_action_confirm( $order ): void {
	if ( $order instanceof WC_Order ) {
		tpb_ach_confirm_payment( $order );
	}
}
add_action( 'woocommerce_order_action_tpb_ach_confirm', 'tpb_ach_order_action_confirm' );

/**
 * Admin confirm endpoint.
 */
function tpb_ach_admin_post_confirm(): void {
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_die( esc_html__( 'You cannot confirm this payment.', 'tpb-ach' ) );
	}
	$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
	check_admin_referer( 'tpb_ach_confirm_' . $order_id );
	$order = wc_get_order( $order_id );
	$url   = admin_url( 'admin.php?page=wc-orders' );
	if ( $order instanceof WC_Order ) {
		$url = add_query_arg( 'tpb_ach_confirmed', tpb_ach_confirm_payment( $order ) ? '1' : '0', $order->get_edit_order_url() );
	}
	wp_safe_redirect( $url );
	exit;
}
add_action( 'admin_post_tpb_ach_confirm', 'tpb_ach_admin_post_confirm' );

/**
 * @param array<string,array<string,string>> $actions Actions.
 * @param WC_Order                           $order   Order.
 * @return array<string,array<string,string>>
 */
function tpb_ach_admin_list_action( array $actions, $order ): array {
	if ( ! $order instanceof WC_Order || ! tpb_ach_is_payment_method( (string) $order->get_payment_method() ) || ! $order->has_status( 'on-hold' ) ) {
		return $actions;
	}
	$actions['tpb_ach_confirm'] = array(
		'url'    => wp_nonce_url( admin_url( 'admin-post.php?action=tpb_ach_confirm&order_id=' . $order->get_id() ), 'tpb_ach_confirm_' . $order->get_id() ),
		'name'   => __( 'Confirm ACH', 'tpb-ach' ),
		'action' => 'processing',
	);
	return $actions;
}
add_filter( 'woocommerce_admin_order_actions', 'tpb_ach_admin_list_action', 20, 2 );

/**
 * @param WC_Order $order Order.
 */
function tpb_ach_admin_order_panel( $order ): void {
	if ( ! $order instanceof WC_Order || ! tpb_ach_is_payment_method( (string) $order->get_payment_method() ) ) {
		return;
	}
	echo '<div class="tpb-ach-admin" style="margin-top:12px;padding:12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">';
	echo '<p style="margin:0 0 8px;"><strong>' . esc_html__( 'ACH / Bank Transfer', 'tpb-ach' ) . '</strong></p>';
	if ( $order->has_status( array( 'pending', 'on-hold' ) ) ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=tpb_ach_confirm&order_id=' . $order->get_id() ), 'tpb_ach_confirm_' . $order->get_id() );
		echo '<p style="margin:0;"><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Confirm ACH payment', 'tpb-ach' ) . '</a></p>';
	}
	echo '</div>';
}
add_action( 'woocommerce_admin_order_data_after_billing_address', 'tpb_ach_admin_order_panel' );

/**
 * Notice after confirm.
 */
function tpb_ach_admin_notice(): void {
	if ( ! is_admin() || ! isset( $_GET['tpb_ach_confirmed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$ok = sanitize_text_field( wp_unslash( (string) $_GET['tpb_ach_confirmed'] ) ) === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	echo $ok
		? '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'ACH payment confirmed. The order is processing and the customer has been emailed.', 'tpb-ach' ) . '</p></div>'
		: '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'This ACH order was not moved to processing.', 'tpb-ach' ) . '</p></div>';
}
add_action( 'admin_notices', 'tpb_ach_admin_notice' );

/**
 * @param array<int,string> $links Links.
 * @return array<int,string>
 */
function tpb_ach_plugin_links( array $links ): array {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=tpb_ach' ) ) . '">' . esc_html__( 'Settings', 'tpb-ach' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( TPB_ACH_FILE ), 'tpb_ach_plugin_links' );

/**
 * Boot gateway.
 */
function tpb_ach_boot(): void {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	require_once TPB_ACH_DIR . 'includes/class-wc-gateway-tpb-ach.php';
	add_filter(
		'woocommerce_payment_gateways',
		static function ( array $methods ): array {
			$methods[] = 'WC_Gateway_Tpb_Ach';
			return $methods;
		}
	);
}

if ( did_action( 'plugins_loaded' ) ) {
	tpb_ach_boot();
} else {
	add_action( 'plugins_loaded', 'tpb_ach_boot', 11 );
}
