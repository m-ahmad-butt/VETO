<?php
/*
Plugin Name: VETO Demo Homepage Hijack
Description: Controlled demonstration of an unauthorized homepage replacement. No external destinations or payment collection.
Version: 1.0.0
*/
defined('ABSPATH') || exit;

function veto_demo_hijack_activate() {
    $previous = array(
        'show_on_front' => get_option('show_on_front'),
        'page_on_front' => get_option('page_on_front'),
    );
    update_option('veto_demo_previous_homepage', $previous);

    $page_id = wp_insert_post(array(
        'post_title' => '限定当選のお知らせ',
        'post_name' => 'veto-demo-fake-prize',
        'post_status' => 'publish',
        'post_type' => 'page',
        'post_content' => '<h1>当選しました！</h1><p>賞金を受け取るには今すぐ手続きしてください。</p><p><strong>VETO SECURITY DEMO: This is a fake prize scam example. Do not enter personal information or send money.</strong></p>',
    ));
    if (is_wp_error($page_id) || !$page_id) {
        return;
    }
    update_option('veto_demo_hijack_page_id', (int) $page_id);
    update_option('show_on_front', 'page');
    update_option('page_on_front', (int) $page_id);
}
register_activation_hook(__FILE__, 'veto_demo_hijack_activate');

function veto_demo_hijack_deactivate() {
    $previous = get_option('veto_demo_previous_homepage');
    if (is_array($previous)) {
        update_option('show_on_front', $previous['show_on_front']);
        update_option('page_on_front', $previous['page_on_front']);
    }
    $page_id = (int) get_option('veto_demo_hijack_page_id');
    if ($page_id) {
        wp_delete_post($page_id, true);
    }
    delete_option('veto_demo_previous_homepage');
    delete_option('veto_demo_hijack_page_id');
}
register_deactivation_hook(__FILE__, 'veto_demo_hijack_deactivate');
