<?php
/**
 * Plugin Name: VETO ALB Identity
 * Description: Footer shows node IP + pod name for ALB demo.
 */
add_action('wp_footer', function () {
	$node = getenv('NODE_IP') ?: 'unknown-node';
	$name = getenv('NODE_NAME') ?: 'unknown-node-name';
	$pod  = gethostname();
	echo '<p class="veto-alb-identity" style="text-align:center;font-size:12px;opacity:.75;margin:1rem 0;">'
		. 'Served by: ' . esc_html($node)
		. ' (' . esc_html($name) . ')'
		. ' | pod: ' . esc_html($pod)
		. '</p>';
}, 99);
