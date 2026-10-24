<?php
/**
 * Plugin Name: Peak Mail SMTP bootstrap
 * Description: Maps Google Workspace / SMTP env vars into WP Mail SMTP constants before the plugin boots.
 * Version: 1.1.0
 *
 * @package Peak
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read env for mail bootstrap (mu-plugin loads before theme helpers).
 */
function tpb_mu_mail_env( string $name ): string {
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
 * Whether WP Mail SMTP already has a Gmail OAuth token in the database.
 */
function tpb_mu_gmail_authorized(): bool {
	$opts = get_option( 'wp_mail_smtp', array() );
	if ( ! is_array( $opts ) || empty( $opts['gmail'] ) || ! is_array( $opts['gmail'] ) ) {
		return false;
	}
	$gmail   = $opts['gmail'];
	$access  = $gmail['access_token'] ?? null;
	$refresh = isset( $gmail['refresh_token'] ) ? (string) $gmail['refresh_token'] : '';
	return ! empty( $access ) && $refresh !== '';
}

$gmail_id     = tpb_mu_mail_env( 'GMAIL_CLIENT_ID' );
$gmail_secret = tpb_mu_mail_env( 'GMAIL_CLIENT_SECRET' );
$smtp_host    = tpb_mu_mail_env( 'SMTP_HOST' );
$smtp_user    = tpb_mu_mail_env( 'SMTP_USER' );
$smtp_pass    = tpb_mu_mail_env( 'SMTP_PASS' );
$from_email   = tpb_mu_mail_env( 'SMTP_FROM_EMAIL' );
$from_name    = tpb_mu_mail_env( 'SMTP_FROM_NAME' );

if ( $from_email === '' || ! filter_var( $from_email, FILTER_VALIDATE_EMAIL ) ) {
	$from_email = 'support@example.com';
}
if ( $from_name === '' || strcasecmp( $from_name, 'WordPress' ) === 0 ) {
	$from_name = 'Peak';
}

// App-password SMTP works without OAuth; default Gmail host when user/pass are set.
if ( $smtp_host === '' && $smtp_user !== '' && $smtp_pass !== '' ) {
	$smtp_host = 'smtp.gmail.com';
}

$gmail_creds_ok = $gmail_id !== '' && $gmail_secret !== '';
$gmail_authed    = $gmail_creds_ok && tpb_mu_gmail_authorized();

if ( ! defined( 'WPMS_ON' ) ) {
	define( 'WPMS_ON', true );
	define( 'WPMS_MAIL_FROM', $from_email );
	define( 'WPMS_MAIL_FROM_FORCE', true );
	define( 'WPMS_MAIL_FROM_NAME', $from_name );
	define( 'WPMS_MAIL_FROM_NAME_FORCE', true );

	if ( $gmail_creds_ok ) {
		define( 'WPMS_GMAIL_CLIENT_ID', $gmail_id );
		define( 'WPMS_GMAIL_CLIENT_SECRET', $gmail_secret );
	}

	if ( $gmail_authed ) {
		define( 'WPMS_MAILER', 'gmail' );
	} elseif ( $smtp_host !== '' ) {
		define( 'WPMS_MAILER', 'smtp' );
		define( 'WPMS_SMTP_HOST', $smtp_host );
		define( 'WPMS_SMTP_PORT', (int) ( tpb_mu_mail_env( 'SMTP_PORT' ) ?: 587 ) );
		$enc = strtolower( tpb_mu_mail_env( 'SMTP_ENCRYPTION' ) ?: 'tls' );
		define( 'WPMS_SSL', in_array( $enc, array( 'ssl', 'tls' ), true ) ? $enc : 'tls' );
		define( 'WPMS_SMTP_AUTH', true );
		define( 'WPMS_SMTP_USER', $smtp_user );
		define( 'WPMS_SMTP_PASS', $smtp_pass );
		define( 'WPMS_SMTP_AUTOTLS', true );
		define( 'WPMS_SET_RETURN_PATH', true );
	} elseif ( $gmail_creds_ok ) {
		// Client ID/secret are present but Google has not been authorized yet.
		// Select Gmail so WP Admin shows the Allow button; sends will fail until then.
		define( 'WPMS_MAILER', 'gmail' );
	}
}

/**
 * Keep WP Mail SMTP activated when the plugin is present on disk.
 */
function tpb_mu_ensure_wp_mail_smtp(): void {
	$relative = 'wp-mail-smtp/wp_mail_smtp.php';
	$file     = trailingslashit( WP_PLUGIN_DIR ) . $relative;
	if ( ! is_readable( $file ) ) {
		return;
	}
	$active = (array) get_option( 'active_plugins', array() );
	if ( ! in_array( $relative, $active, true ) ) {
		$active[] = $relative;
		sort( $active );
		update_option( 'active_plugins', $active );
	}
}
add_action( 'plugins_loaded', 'tpb_mu_ensure_wp_mail_smtp', 0 );

/**
 * Remind admins that Gmail API still needs a one-time Google authorization.
 */
function tpb_mu_mail_oauth_notice(): void {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( tpb_mu_mail_env( 'GMAIL_CLIENT_ID' ) === '' ) {
		return;
	}
	if ( tpb_mu_gmail_authorized() ) {
		return;
	}
	$url = admin_url( 'options-general.php?page=wp-mail-smtp' );
	echo '<div class="notice notice-error"><p>';
	echo wp_kses(
		sprintf(
			/* translators: %s: WP Mail SMTP settings URL */
			__( 'Verification emails will fail until Gmail is authorized. Open <a href="%s">WP Mail SMTP → Settings</a> and click <strong>Allow plugin to send emails using your Google account</strong> as the Workspace mailbox (support@example.com). Google Cloud redirect URI must be <code>https://connect.wpmailsmtp.com/google/</code>.', 'peak' ),
			esc_url( $url )
		),
		array(
			'a'      => array( 'href' => array() ),
			'strong' => array(),
			'code'   => array(),
		)
	);
	echo '</p></div>';
}
add_action( 'admin_notices', 'tpb_mu_mail_oauth_notice' );
