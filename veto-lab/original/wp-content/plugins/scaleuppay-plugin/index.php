<?php 

/*

Plugin Name: Cleffo

Plugin URI:  https://apis.cleffo.com

Description: Accept Payments Online via https://apis.cleffo.com

Author: Cleffo

version: 1.0.1

*/



ini_set('display_errors','off');

ini_set('error_reporting', E_ALL );

// define('WP_DEBUG', false);

// define('WP_DEBUG_DISPLAY', false);

defined('ABSPATH') or die('You don\'t have permission to access this file');

//plugin slug myuserpay

if(!function_exists('add_action')){

    die('You don\'t have permission to access this file because add_action does not exist');

}

// Make sure WooCommerce is active

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}
// Include helper functions early
require_once __DIR__ . '/helpers/functions.php';

define('SUA_Payments_PUBLIC', WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/public/');

define('SUA_Payments_PUBLIC_IMAGES', SUA_Payments_PUBLIC . 'images/');
define("suap_wordpresspayplugin_plugin_folder", "cleffo-plugin");

define('PAYMENTS_API_BASE_URL',  'https://apis.cleffo.com');

add_action( 'wp_footer', 'bbloomer_add_jscript_checkout', 9999 );

function bbloomer_add_jscript_checkout() {

    global $wp;
    //jQuery(".checkout").on("submit", function(event) {
    if ( is_checkout() && empty( $wp->query_vars['order-pay'] ) && ! isset( $wp->query_vars['order-received'] ) ) {
        echo '<script>
            // The handler object is already defined in the payment gateway class
            jQuery("form.checkout").on("checkout_place_order", function(event) {
                let payment_method = jQuery(\'input[name="payment_method"]:checked\').val();
                //check if suap_wordpresspayplugin (CC/DC) is selected
                if(payment_method != "suap_wordpresspayplugin"){
                    return true;
                }
                jQuery("#place_order").prop("disabled", true);
                event.preventDefault();
                // Validate the checkout form
                if(CleffoPaymentHandler.stripeToken == "" && CleffoPaymentHandler.callCount < 2){
                    CleffoPaymentHandler.callCount++;
                    const validationResult = CleffoPaymentHandler.sendMessage();
                    if (!validationResult) {
                        // Validation failed, dont proceed
                        jQuery("#place_order").prop("disabled", false);
                        return false;
                    }
                } else if (CleffoPaymentHandler.stripeToken != ""){
                    return true;
                } else {
                    jQuery("#place_order").prop("disabled", false);
                    return false;
                }
            });

        </script>';
    }

}



// Add a custom validation function to the 'woocommerce_after_checkout_validation' hook

function validate_checkout_form($data, $errors) {
    // Retrieve form field values
    $pm = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : '';
    if($pm != "suap_wordpresspayplugin"){
        return;
    }

    if ( ! is_user_logged_in() && ! cleffo_allow_guest_checkout() ) {
        $errors->add( 'cleffo_login_required', __( 'You must be logged in to use this payment method.', 'woocommerce' ) );
        return;
    }
    
    $suap_stripe_token = isset($_POST['suap_stripe_token']) ? sanitize_text_field($_POST['suap_stripe_token']) : '';
    
    // Only validate that token exists
    if (empty($suap_stripe_token)) {
        $errors->add('suap_stripe_token', __('Please enter your correct card details.', 'suap_stripe_token'));
    }
    
    return $errors;
}

add_action('woocommerce_after_checkout_validation', 'validate_checkout_form', 10, 2);

require_once __DIR__ . '/classes/Cleffo_Admin_Logs.php';
Cleffo_Admin_Logs::init();

/**
 * 'Settings' link on plugin page
 **/

add_filter( 'plugin_action_links', 'suap_wordpresspayplugin_add_action_plugin', 10, 5 );

function suap_wordpresspayplugin_add_action_plugin( $actions, $plugin_file ) {

    static $plugin;

    if (!isset($plugin))
        $plugin = plugin_basename(__FILE__);

    if ($plugin == $plugin_file) {
        $settings = array(
            'settings' => '<a href="admin.php?page=wc-settings&tab=checkout&section=suap_wordpresspayplugin">' . __( 'Settings' ) . '</a>',
            'logs'     => '<a href="admin.php?page=cleffo-logs">' . __( 'Logs' ) . '</a>',
        );
        $actions = array_merge( $settings, $actions );
    }
    return $actions;

}//END-settings_add_action_link

add_filter( 'woocommerce_payment_gateways', 'suap_add_gateway_class' );

function suap_add_gateway_class($gateways) {
    $gateways[] = 'WC_SUA_Payment_Gateway_Plugin';
    $gateways[] = 'PaymentController';
    return $gateways;

}//END-wc_add_gateway

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */

add_action( 'plugins_loaded', 'suap_init_WPressPlugin' );

function suap_init_WPressPlugin(){

    if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
    include_once __DIR__.'/classes/SUAP_WC_Gateway_WPressPlugin.php';
    include_once __DIR__.'/helpers/PaymentController.php';

}

add_action( 'woocommerce_blocks_loaded', 'cleffo_register_blocks_support' );

function cleffo_register_blocks_support() {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }

    require_once __DIR__ . '/classes/Cleffo_Blocks_Payment_Method.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( $payment_method_registry ) {
            $payment_method_registry->register( new Cleffo_Blocks_Payment_Method() );
        }
    );
}