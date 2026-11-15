<?php
/** Trusted read-only MU instrumentation. Application telemetry can be spoofed by plugin code. */
defined('ABSPATH') || exit;
function veto_telemetry($action, $resource, $extra = array()) {
    $event = json_encode(array('action' => $action, 'resource' => substr($resource, 0, 2048), 'detail' => $extra));
    $socket = @stream_socket_client('udp://observer:9009', $errno, $errstr, 0.2);
    if ($socket) { fwrite($socket, $event); fclose($socket); }
}
add_filter('pre_http_request', function($pre, $args, $url) {
    $parts = wp_parse_url($url);
    $allowed = isset($parts['host'], $parts['port']) && $parts['host'] === 'observer'
        && (int)$parts['port'] === 8081 && ($parts['scheme'] ?? '') === 'http';
    veto_telemetry($allowed ? 'http_allowed' : 'http_blocked', $url,
        array('method' => $args['method'] ?? 'GET', 'source' => 'WordPress pre_http_request'));
    return $allowed ? $pre : new WP_Error('veto_egress_blocked', 'VETO sandbox blocks external HTTP');
}, PHP_INT_MAX, 3);
add_filter('automatic_updater_disabled', '__return_true');
add_filter('pre_site_transient_update_core', function() { return null; });
add_filter('pre_site_transient_update_plugins', function() { return null; });
add_filter('pre_site_transient_update_themes', function() { return null; });
