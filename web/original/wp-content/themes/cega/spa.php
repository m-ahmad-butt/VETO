<?php
/**
 * SPA entry — injects built cega-webb assets. Falls back if build missing.
 *
 * @package Cega
 */

defined( 'ABSPATH' ) || exit;

$index = CEGA_THEME_DIR . '/app/index.html';
if ( ! is_readable( $index ) ) {
	status_header( 503 );
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>CEGA build missing</title></head><body>';
	echo '<h1>ExamSlot UI build missing</h1>';
	echo '<p>Run <code>npm run build</code> for cega-webb frontend into this theme <code>app/</code> folder.</p>';
	echo '</body></html>';
	exit;
}

$html = file_get_contents( $index );
$base = trailingslashit( CEGA_THEME_URI ) . 'app/';

// Rewrite root-relative Vite asset URLs to the theme app folder.
$html = preg_replace( '#(href|src)="(/assets/)#', '$1="' . esc_url( $base ) . 'assets/', $html );
$html = preg_replace( '#(href|src)="(/notion/)#', '$1="' . esc_url( $base ) . 'notion/', $html );
$html = str_replace( '<head>', '<head><base href="' . esc_url( $base ) . '">', $html );

// Expose API base for the SPA (ExamSlot backend).
$api = defined( 'CEGA_API_URL' ) ? CEGA_API_URL : ( getenv( 'CEGA_API_URL' ) ?: 'http://localhost:3001' );
$inject = '<script>window.__CEGA_API_URL__=' . wp_json_encode( $api ) . ';</script>';
$html   = str_replace( '</head>', $inject . '</head>', $html );

header( 'Content-Type: text/html; charset=utf-8' );
echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built SPA HTML
