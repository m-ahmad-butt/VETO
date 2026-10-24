<?php
/**
 * Plugin Name: Peak — Load WooCommerce
 * Description: Always load WooCommerce from disk when present. Theme hooks are too late for plugins_loaded.
 * Version: 1.0.2
 *
 * @package Peak
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Absolute path to a plugin main file, or empty if missing.
 */
function tpb_mu_plugin_file( string $relative ): string {
	$file = trailingslashit( WP_PLUGIN_DIR ) . ltrim( $relative, '/' );
	return is_readable( $file ) ? $file : '';
}

/**
 * Ensure a plugin appears in the active_plugins option.
 */
function tpb_mu_mark_plugin_active( string $relative ): void {
	$active = (array) get_option( 'active_plugins', array() );
	if ( in_array( $relative, $active, true ) ) {
		return;
	}
	$active[] = $relative;
	sort( $active );
	update_option( 'active_plugins', $active );
}

/**
 * Include plugin main file once (safe if already loaded).
 */
function tpb_mu_include_plugin( string $relative ): bool {
	$file = tpb_mu_plugin_file( $relative );
	if ( $file === '' ) {
		return false;
	}
	try {
		include_once $file;
	} catch ( Throwable $e ) {
		error_log( 'tpb_mu_include_plugin ' . $relative . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return false;
	}
	return true;
}

/**
 * Load WooCommerce early so its plugins_loaded callbacks register.
 */
function tpb_mu_bootstrap_woocommerce(): void {
	$relative = 'woocommerce/woocommerce.php';
	if ( ! tpb_mu_include_plugin( $relative ) ) {
		return;
	}
	tpb_mu_mark_plugin_active( $relative );
}

/**
 * After plugins_loaded: ensure WC instance. Do not force-load Stripe.
 */
function tpb_mu_boot_commerce_after_plugins(): void {
	tpb_mu_bootstrap_woocommerce();

	if ( class_exists( 'WooCommerce', false ) && method_exists( 'WooCommerce', 'instance' ) && ! function_exists( 'WC' ) ) {
		try {
			WooCommerce::instance();
		} catch ( Throwable $e ) {
			error_log( 'tpb_mu WooCommerce::instance: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

// Cash App / Venmo / ACH register HPOS compatibility, so they load before WooCommerce boots.
foreach ( array( 'tpb-cashapp/tpb-cashapp.php', 'tpb-venmo/tpb-venmo.php', 'tpb-ach/tpb-ach.php' ) as $tpb_pay_plugin ) {
	if ( tpb_mu_include_plugin( $tpb_pay_plugin ) ) {
		tpb_mu_mark_plugin_active( $tpb_pay_plugin );
	}
}

// Include WC now so it can attach to plugins_loaded.
tpb_mu_bootstrap_woocommerce();
add_action( 'plugins_loaded', 'tpb_mu_boot_commerce_after_plugins', 1 );
add_action( 'init', 'tpb_mu_boot_commerce_after_plugins', 0 );
