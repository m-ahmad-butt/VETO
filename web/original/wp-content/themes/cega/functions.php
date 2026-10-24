<?php
/**
 * CEGA ExamSlot theme — serves the cega-webb React SPA from /app.
 * WordPress provides the Top-Peak-like shell (plugins, admin, VETO surface).
 *
 * @package Cega
 */

defined( 'ABSPATH' ) || exit;

define( 'CEGA_THEME_VERSION', '1.0.0' );
define( 'CEGA_THEME_DIR', get_template_directory() );
define( 'CEGA_THEME_URI', get_template_directory_uri() );

/**
 * ExamSlot SPA uses root paths (/notion/*, /logo.png). Serve them from theme/app.
 */
add_action(
	'template_redirect',
	static function () {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = rawurldecode( $path );

		$is_public_asset = ( '/logo.png' === $path ) || str_starts_with( $path, '/notion/' );
		if ( ! $is_public_asset ) {
			return;
		}

		$app_root = realpath( CEGA_THEME_DIR . '/app' );
		if ( false === $app_root ) {
			return;
		}

		$candidate = realpath( $app_root . $path );
		if ( false === $candidate || ! str_starts_with( $candidate, $app_root ) || ! is_file( $candidate ) ) {
			status_header( 404 );
			exit;
		}

		$ext  = strtolower( pathinfo( $candidate, PATHINFO_EXTENSION ) );
		$mime = array(
			'png'  => 'image/png',
			'webp' => 'image/webp',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'svg'  => 'image/svg+xml',
			'gif'  => 'image/gif',
		);
		header( 'Content-Type: ' . ( $mime[ $ext ] ?? 'application/octet-stream' ) );
		header( 'Content-Length: ' . (string) filesize( $candidate ) );
		header( 'Cache-Control: public, max-age=86400' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $candidate );
		exit;
	},
	0
);

/**
 * Front requests (except WP internals) render the SPA shell.
 * Use template_include so theme bootstrap finishes (avoids WP 6.8 notices).
 */
add_filter(
	'template_include',
	static function ( $template ) {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return $template;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		$pass = array(
			'/wp-admin',
			'/wp-login.php',
			'/wp-json',
			'/xmlrpc.php',
			'/wp-cron.php',
			'/wp-content',
			'/wp-includes',
		);
		foreach ( $pass as $prefix ) {
			if ( str_starts_with( $path, $prefix ) ) {
				return $template;
			}
		}

		return CEGA_THEME_DIR . '/spa.php';
	},
	99
);

add_action(
	'after_setup_theme',
	static function () {
		add_theme_support( 'title-tag' );
	}
);
