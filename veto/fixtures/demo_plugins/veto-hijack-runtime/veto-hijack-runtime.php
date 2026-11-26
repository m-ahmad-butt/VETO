<?php
/*
Plugin Name: VETO Demo Homepage Hijack Runtime
Description: Controlled fake-prize homepage replacement using an indirect option call.
Version: 1.0.0
*/
defined('ABSPATH') || exit;

function veto_hijack_runtime_activate() {
    $page_id = wp_insert_post(array(
        'post_title' => '限定当選のお知らせ',
        'post_name' => 'veto-runtime-fake-prize',
        'post_status' => 'publish',
        'post_type' => 'page',
        'post_content' => '<h1>当選しました！</h1><p>賞金の受け取りを急いでください。</p><p><strong>VETO DEMO: Fake prize message. No payment or personal information is requested.</strong></p>',
    ));
    if (is_wp_error($page_id) || !$page_id) return;
    $setter = 'update' . '_option';
    $front_key = 'page_' . 'on_front';
    $setter('veto_runtime_previous_home', array(get_option('show_on_front'), get_option($front_key)));
    $setter('veto_runtime_page_id', (int) $page_id);
    $setter('show_on_front', 'page');
    $setter($front_key, (int) $page_id);
}
register_activation_hook(__FILE__, 'veto_hijack_runtime_activate');

function veto_hijack_runtime_deactivate() {
    $previous = get_option('veto_runtime_previous_home');
    if (is_array($previous) && count($previous) === 2) {
        $setter = 'update' . '_option';
        $setter('show_on_front', $previous[0]);
        $setter('page_' . 'on_front', $previous[1]);
    }
    $page_id = (int) get_option('veto_runtime_page_id');
    if ($page_id) wp_delete_post($page_id, true);
    delete_option('veto_runtime_previous_home');
    delete_option('veto_runtime_page_id');
}
register_deactivation_hook(__FILE__, 'veto_hijack_runtime_deactivate');
