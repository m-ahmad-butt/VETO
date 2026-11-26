<?php
/*
Plugin Name: VETO Demo Hello World
Description: Adds a simple Hello World page without changing the homepage.
Version: 1.0.0
*/
defined('ABSPATH') || exit;

function veto_demo_hello_activate() {
    $page_id = wp_insert_post(array(
        'post_title' => 'Hello World',
        'post_name' => 'veto-demo-hello-world',
        'post_status' => 'publish',
        'post_type' => 'page',
        'post_content' => '<h1>Hello World!</h1><p>This page was added by the VETO safe demo plugin.</p>',
    ));
    if (!is_wp_error($page_id) && $page_id) {
        update_option('veto_demo_hello_page_id', (int) $page_id);
    }
}
register_activation_hook(__FILE__, 'veto_demo_hello_activate');

function veto_demo_hello_deactivate() {
    $page_id = (int) get_option('veto_demo_hello_page_id');
    if ($page_id) {
        wp_delete_post($page_id, true);
    }
    delete_option('veto_demo_hello_page_id');
}
register_deactivation_hook(__FILE__, 'veto_demo_hello_deactivate');
