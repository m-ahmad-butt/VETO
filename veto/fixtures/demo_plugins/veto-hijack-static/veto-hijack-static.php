<?php
/*
Plugin Name: VETO Demo Homepage Hijack Static
Description: Controlled fake-prize homepage replacement, written to trigger VETO PHP010.
Version: 1.0.0
*/
defined('ABSPATH') || exit;

function veto_hijack_static_activate() {
    $page_id = wp_insert_post(array(
        'post_title' => '限定当選のお知らせ',
        'post_name' => 'veto-static-fake-prize',
        'post_status' => 'publish',
        'post_type' => 'page',
        'post_content' => '<h1>当選しました！</h1><p>賞金の受け取りを急いでください。</p><p><strong>VETO DEMO: Fake prize message. No payment or personal information is requested.</strong></p>',
    ));
    if (is_wp_error($page_id) || !$page_id) return;
    update_option('veto_static_previous_home', array(get_option('show_on_front'), get_option('page_on_front')));
    update_option('veto_static_page_id', (int) $page_id);
    update_option('show_on_front', 'page');
    update_option('page_on_front', (int) $page_id);
}
register_activation_hook(__FILE__, 'veto_hijack_static_activate');

function veto_hijack_static_deactivate() {
    $previous = get_option('veto_static_previous_home');
    if (is_array($previous) && count($previous) === 2) {
        update_option('show_on_front', $previous[0]);
        update_option('page_on_front', $previous[1]);
    }
    $page_id = (int) get_option('veto_static_page_id');
    if ($page_id) wp_delete_post($page_id, true);
    delete_option('veto_static_previous_home');
    delete_option('veto_static_page_id');
}
register_deactivation_hook(__FILE__, 'veto_hijack_static_deactivate');
