<?php

if (!function_exists('cleffo_log')) {
    /**
     * Global logging function that can be called from anywhere in the plugin
     * 
     * @param string $message The message to log
     * @param array $context Additional context data (optional)
     * @param string $level Log level (info, error, debug)
     * @return void
     */
    function cleffo_log($message, $context = [], $level = 'info') {
        require_once __DIR__ . '/Logger.php';
        $logger = CleffoLogger::getInstance();

        switch (strtolower($level)) {
            case 'error':
                $logger->error($message, $context);
                break;
            case 'debug':
                $logger->debug($message, $context);
                break;
            default:
                $logger->info($message, $context);
        }
    }
}

if ( ! function_exists( 'cleffo_mask_sensitive_payment_fields' ) ) {
    /**
     * Return a copy of a payment payload with card fields masked, safe for logging.
     * Covers both the raw Stripe-style token ("ccno|month|year|cvv") and the
     * Cleffo-mapped fields (ccno, ccvv) so it can be used at any log site.
     *
     * @param array $data
     * @return array
     */
    function cleffo_mask_sensitive_payment_fields( $data ) {
        if ( ! is_array( $data ) ) {
            return $data;
        }

        $masked = $data;

        if ( ! empty( $masked['suap_stripe_token'] ) ) {
            $parts = explode( '|', $masked['suap_stripe_token'] );
            if ( count( $parts ) === 4 ) {
                [ $ccno, $month, $year, $cvv ] = $parts;
                $last4 = substr( $ccno, -4 );
                $parts = [ str_repeat( '*', max( strlen( $ccno ) - 4, 0 ) ) . $last4, $month, $year, str_repeat( '*', strlen( $cvv ) ) ];
                $masked['suap_stripe_token'] = implode( '|', $parts );
            } else {
                $masked['suap_stripe_token'] = '***REDACTED***';
            }
        }

        if ( ! empty( $masked['ccno'] ) ) {
            $ccno = (string) $masked['ccno'];
            $masked['ccno'] = str_repeat( '*', max( strlen( $ccno ) - 4, 0 ) ) . substr( $ccno, -4 );
        }

        if ( ! empty( $masked['ccvv'] ) ) {
            $masked['ccvv'] = str_repeat( '*', strlen( (string) $masked['ccvv'] ) );
        }

        return $masked;
    }
}

if ( ! function_exists( 'cleffo_get_gateway_settings' ) ) {
    /**
     * @return array
     */
    function cleffo_get_gateway_settings() {
        $settings = get_option( 'woocommerce_suap_wordpresspayplugin_settings', array() );

        return is_array( $settings ) ? $settings : array();
    }
}

if ( ! function_exists( 'cleffo_get_api_key' ) ) {
    /**
     * Store API key from admin settings.
     *
     * @return string
     */
    function cleffo_get_api_key() {
        $settings = cleffo_get_gateway_settings();

        if ( ! empty( $settings['api_key'] ) ) {
            return trim( $settings['api_key'] );
        }

        return '';
    }
}

if ( ! function_exists( 'cleffo_get_client_key' ) ) {
    /**
     * Client key from admin settings (agency7_client_key).
     *
     * @return string
     */
    function cleffo_get_client_key() {
        $settings = cleffo_get_gateway_settings();

        if ( ! empty( $settings['agency7_client_key'] ) ) {
            return trim( $settings['agency7_client_key'] );
        }

        return '';
    }
}

if ( ! function_exists( 'cleffo_get_payment_gateway_fallback' ) ) {
    /**
     * Last-known active gateway, or the hard default (cardpaymentz).
     *
     * @return string
     */
    function cleffo_get_payment_gateway_fallback() {
        $last_known = get_option( 'cleffo_active_gateway', '' );

        if ( is_string( $last_known ) && '' !== $last_known ) {
            return sanitize_text_field( $last_known );
        }

        return 'cardpaymentz';
    }
}

if ( ! function_exists( 'cleffo_clear_payment_gateway_cache' ) ) {
    /**
     * Clear cached active gateway so the next read hits the Cleffo API.
     *
     * @return void
     */
    function cleffo_clear_payment_gateway_cache() {
        delete_transient( 'cleffo_active_gateway' );
    }
}

if ( ! function_exists( 'cleffo_cache_payment_gateway' ) ) {
    /**
     * Cache active gateway for the request + transient TTL.
     *
     * @param string $gateway
     * @param int    $ttl
     * @return string
     */
    function cleffo_cache_payment_gateway( $gateway, $ttl = HOUR_IN_SECONDS ) {
        $gateway = sanitize_text_field( (string) $gateway );
        set_transient( 'cleffo_active_gateway', $gateway, $ttl );
        return $gateway;
    }
}

if ( ! function_exists( 'cleffo_get_payment_gateway' ) ) {
    /**
     * Active payment gateway from Cleffo business API (cached 1 hour).
     *
     * GET /api/business/{client_key}/gateway with x-api-key.
     * Defaults to stripe when API key is missing or the gateway API errors.
     * Error results are also cached (15 min) to avoid repeated API calls/logs.
     *
     * @return string
     */
    function cleffo_get_payment_gateway() {
        static $resolved = null;
        if ( is_string( $resolved ) && '' !== $resolved ) {
            return $resolved;
        }

        $cached = get_transient( 'cleffo_active_gateway' );
        if ( is_string( $cached ) && '' !== $cached ) {
            $resolved = $cached;
            return $resolved;
        }

        $client_key = cleffo_get_client_key();
        $api_key    = cleffo_get_api_key();

        // Without an API key, cardpaymentz cannot authenticate — default to stripe.
        if ( '' === $api_key ) {
            $resolved = cleffo_cache_payment_gateway( 'stripe', 15 * MINUTE_IN_SECONDS );
            return $resolved;
        }

        if ( '' === $client_key || ! defined( 'PAYMENTS_API_BASE_URL' ) ) {
            $resolved = cleffo_get_payment_gateway_fallback();
            return $resolved;
        }

        $url = PAYMENTS_API_BASE_URL . '/api/business/' . rawurlencode( $client_key ) . '/gateway';

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 15,
                'headers' => array(
                    'x-api-key' => $api_key,
                    'Accept'    => 'application/json',
                ),
            )
        );

        cleffo_log( 'Active gateway API response: ' . json_encode( $response ), array(), 'error' );
        if ( is_wp_error( $response ) ) {
            cleffo_log( 'Failed to fetch active gateway: ' . $response->get_error_message(), array(), 'error' );
            $resolved = cleffo_cache_payment_gateway( 'stripe', 15 * MINUTE_IN_SECONDS );
            return $resolved;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if (
            200 === (int) $code
            && is_array( $body )
            && ! empty( $body['status'] )
            && ! empty( $body['data']['active_gateway'] )
            && is_scalar( $body['data']['active_gateway'] )
        ) {
            $gateway = sanitize_text_field( (string) $body['data']['active_gateway'] );
            set_transient( 'cleffo_active_gateway', $gateway, HOUR_IN_SECONDS );
            update_option( 'cleffo_active_gateway', $gateway, false );
            $resolved = $gateway;
            cleffo_log( 'Active gateway: ' . $resolved, array(), 'error' );
            return $resolved;
        }

        cleffo_log(
            'Unexpected active gateway API response',
            array(
                'http_code' => $code,
                'message'   => is_array( $body ) && isset( $body['message'] ) ? $body['message'] : null,
            ),
            'error'
        );

        $resolved = cleffo_cache_payment_gateway( 'stripe', 15 * MINUTE_IN_SECONDS );
        return $resolved;
    }
}

if ( ! function_exists( 'cleffo_get_request_api_key_header' ) ) {
    /**
     * Read x-api-key from the incoming request headers.
     *
     * @return string
     */
    function cleffo_get_request_api_key_header() {
        if ( ! empty( $_SERVER['HTTP_X_API_KEY'] ) ) {
            return trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_API_KEY'] ) ) );
        }

        if ( function_exists( 'getallheaders' ) ) {
            $headers = getallheaders();
            if ( is_array( $headers ) ) {
                foreach ( $headers as $name => $value ) {
                    if ( strtolower( $name ) === 'x-api-key' && is_scalar( $value ) ) {
                        return trim( sanitize_text_field( (string) $value ) );
                    }
                }
            }
        }

        return '';
    }
}

if ( ! function_exists( 'cleffo_is_valid_notify_api_key' ) ) {
    /**
     * Validate the provided notify webhook API key.
     *
     * @param string $provided_key
     * @return bool
     */
    function cleffo_is_valid_notify_api_key( $provided_key ) {
        $expected_key = cleffo_get_api_key();

        if ( '' === $expected_key || '' === $provided_key ) {
            return false;
        }

        return hash_equals( $expected_key, $provided_key );
    }
}

if ( ! function_exists( 'cleffo_allow_guest_checkout' ) ) {
    /**
     * Whether the Cleffo payment option is shown to non-logged-in users.
     *
     * @return bool
     */
    function cleffo_allow_guest_checkout() {
        $settings = cleffo_get_gateway_settings();

        return isset( $settings['allow_guest'] ) && 'yes' === $settings['allow_guest'];
    }
}

if ( ! function_exists( 'cleffo_sanitize_request_field' ) ) {
    /**
     * @param array  $request
     * @param string $key
     * @param string $default
     * @return string
     */
    function cleffo_sanitize_request_field( $request, $key, $default = '' ) {
        if ( ! isset( $request[ $key ] ) || '' === $request[ $key ] ) {
            return $default;
        }

        return sanitize_text_field( wp_unslash( $request[ $key ] ) );
    }
}

if ( ! function_exists( 'cleffo_get_shipping_methods_payload' ) ) {
    /**
     * @param WC_Order $order
     * @param array    $request
     * @return array
     */
    function cleffo_get_shipping_methods_payload( $order, $request ) {
        if ( ! empty( $request['shipping_method'] ) && is_array( $request['shipping_method'] ) ) {
            return array_map(
                'sanitize_text_field',
                wp_unslash( $request['shipping_method'] )
            );
        }

        $methods = array();
        foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
            $methods[] = $shipping_item->get_method_id() . ':' . $shipping_item->get_instance_id();
        }

        return $methods;
    }
}

if ( ! function_exists( 'cleffo_is_redirect_gateway' ) ) {
    /**
     * @return bool
     */
    function cleffo_is_redirect_gateway() {
        return cleffo_get_payment_gateway() === 'cardpaymentz';
    }
}

add_action( 'woocommerce_update_options_payment_gateways_suap_wordpresspayplugin', 'cleffo_clear_payment_gateway_cache' );

if ( ! function_exists( 'cleffo_get_wc_api_url' ) ) {
    /**
     * Build a WooCommerce API endpoint URL that respects permalink structure.
     *
     * @param string $endpoint e.g. cleffo_payment_return
     * @param array  $query_args
     * @return string
     */
    function cleffo_get_wc_api_url( $endpoint, $query_args = array() ) {
        $base_url = ( function_exists( 'WC' ) && method_exists( WC(), 'api_request_url' ) )
            ? WC()->api_request_url( $endpoint )
            : home_url( '/wc-api/' . ltrim( $endpoint, '/' ) );

        if ( empty( $query_args ) ) {
            return esc_url_raw( $base_url );
        }

        return esc_url_raw( add_query_arg( $query_args, $base_url ) );
    }
}

if ( ! function_exists( 'cleffo_get_payment_return_url' ) ) {
    /**
     * WooCommerce API return URL used as Cleffo success/cancel/error redirect target (GET).
     *
     * @param WC_Order $order
     * @return string
     */
    function cleffo_get_payment_return_url( $order ) {
        if ( ! $order->get_order_key() ) {
            $order->set_order_key( wc_generate_order_key() );
            $order->save();
        }

        return cleffo_get_wc_api_url(
            'cleffo_payment_return',
            array(
                'order_id' => $order->get_id(),
                'key'      => $order->get_order_key(),
            )
        );
    }
}

if ( ! function_exists( 'cleffo_get_payment_notify_url' ) ) {
    /**
     * WooCommerce API notify/webhook URL used as Cleffo notify_url (POST).
     *
     * @param WC_Order $order
     * @return string
     */
    function cleffo_get_payment_notify_url( $order ) {
        if ( ! $order->get_order_key() ) {
            $order->set_order_key( wc_generate_order_key() );
            $order->save();
        }

        return cleffo_get_wc_api_url(
            'cleffo_payment_notify',
            array(
                'order_id' => $order->get_id(),
                'key'      => $order->get_order_key(),
            )
        );
    }
}

if ( ! function_exists( 'cleffo_get_return_params' ) ) {
    /**
     * Normalise GET query params for the payment return endpoint.
     *
     * GET only. Some gateways (Cleffo) HTML-encode the ampersands when redirecting back,
     * so the incoming query string becomes "?order_id=1&amp;key=..." and PHP
     * parses keys as "amp;key", "amp;status", etc. Rebuild a clean param array
     * from the raw query string.
     *
     * @return array
     */
    function cleffo_get_return_params() {
        $params = array();

        if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
            $query = html_entity_decode( wp_unslash( $_SERVER['QUERY_STRING'] ), ENT_QUOTES );
            parse_str( $query, $params );
        }

        if ( empty( $params ) && ! empty( $_GET ) ) {
            $params = wp_unslash( $_GET );
        }

        // Strip a leftover "amp;" prefix on keys, just in case.
        $clean = array();
        foreach ( $params as $key => $value ) {
            if ( 0 === strpos( $key, 'amp;' ) ) {
                $key = substr( $key, 4 );
            }
            $clean[ $key ] = $value;
        }

        return $clean;
    }
}

if ( ! function_exists( 'cleffo_get_notify_params' ) ) {
    /**
     * Collect params for the Cleffo notify webhook (POST).
     *
     * Merges URL query args (order_id/key) with form POST and JSON body.
     *
     * @return array
     */
    function cleffo_get_notify_params() {
        $params = cleffo_get_return_params();

        if ( ! empty( $_POST ) && is_array( $_POST ) ) {
            foreach ( wp_unslash( $_POST ) as $key => $value ) {
                if ( is_scalar( $value ) ) {
                    $params[ $key ] = $value;
                }
            }
        }

        $raw = file_get_contents( 'php://input' );
        if ( is_string( $raw ) && '' !== trim( $raw ) ) {
            $json = json_decode( $raw, true );
            if ( is_array( $json ) ) {
                // Flatten common nested "data" payload from Cleffo-style responses.
                if ( isset( $json['data'] ) && is_array( $json['data'] ) ) {
                    foreach ( $json['data'] as $key => $value ) {
                        if ( is_scalar( $value ) && ! isset( $params[ $key ] ) ) {
                            $params[ $key ] = $value;
                        }
                    }
                }
                foreach ( $json as $key => $value ) {
                    if ( is_scalar( $value ) ) {
                        $params[ $key ] = $value;
                    }
                }
            }
        }

        return $params;
    }
}

if ( ! function_exists( 'cleffo_extract_notify_status' ) ) {
    /**
     * @param array $params
     * @return string
     */
    function cleffo_extract_notify_status( $params ) {
        foreach ( array( 'payment_status', 'status' ) as $key ) {
            if ( ! empty( $params[ $key ] ) && is_scalar( $params[ $key ] ) ) {
                return strtolower( sanitize_text_field( (string) $params[ $key ] ) );
            }
        }

        return '';
    }
}

if ( ! function_exists( 'cleffo_extract_notify_txn_ref' ) ) {
    /**
     * @param array $params
     * @return string
     */
    function cleffo_extract_notify_txn_ref( $params ) {
        foreach ( array( 'txn_ref_no', 'transaction_reference_number' ) as $key ) {
            if ( ! empty( $params[ $key ] ) && is_scalar( $params[ $key ] ) ) {
                return sanitize_text_field( (string) $params[ $key ] );
            }
        }

        return '';
    }
}

if ( ! function_exists( 'cleffo_get_order_from_return_request' ) ) {
    /**
     * @param array $request
     * @return WC_Order|false
     */
    function cleffo_get_order_from_return_request( $request ) {
        $order_id  = isset( $request['order_id'] ) ? absint( $request['order_id'] ) : 0;
        $order_key = isset( $request['key'] ) ? sanitize_text_field( wp_unslash( $request['key'] ) ) : '';

        // Notify payloads may send merchant_order_id instead of order_id.
        if ( ! $order_id && ! empty( $request['merchant_order_id'] ) ) {
            $order_id = absint( $request['merchant_order_id'] );
        }

        if ( ! $order_id || '' === $order_key ) {
            return false;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        if ( ! hash_equals( $order->get_order_key(), $order_key ) ) {
            return false;
        }

        return $order;
    }
}

if ( ! function_exists( 'cleffo_get_order_payment_urls' ) ) {
    /**
     * Build checkout redirect URLs for cardpaymentz / Cleffo APIs.
     *
     * @param WC_Order $order
     * @return array
     */
    function cleffo_get_order_payment_urls( $order ) {
        if ( ! $order->get_order_key() ) {
            $order->set_order_key( wc_generate_order_key() );
            $order->save();
        }

        $checkout_url = wc_get_checkout_url();
        $payment_url  = $order->get_checkout_payment_url();
        $thank_you_url = $order->get_checkout_order_received_url();

        if ( empty( $payment_url ) ) {
            $payment_url = add_query_arg(
                array(
                    'pay_for_order' => 'true',
                    'key'           => $order->get_order_key(),
                ),
                wc_get_endpoint_url( 'order-pay', $order->get_id(), $checkout_url )
            );
        }

        if ( empty( $thank_you_url ) ) {
            $thank_you_url = add_query_arg(
                array(
                    'key'      => $order->get_order_key(),
                    'order_id' => $order->get_id(),
                ),
                wc_get_endpoint_url( 'order-received', '', $checkout_url )
            );
        }

        if ( cleffo_is_redirect_gateway() ) {
            $return_url = cleffo_get_payment_return_url( $order );
            $notify_url = cleffo_get_payment_notify_url( $order );

            return array(
                'success_url'  => $return_url,
                'cancel_url'   => $return_url,
                'error_url'    => $return_url,
                'notify_url'   => $notify_url,
                'redirect_url' => esc_url_raw( $payment_url ),
            );
        }

        return array(
            'success_url'  => esc_url_raw( $thank_you_url ),
            'cancel_url'   => esc_url_raw( $payment_url ),
            'error_url'    => esc_url_raw( $payment_url ),
            'notify_url'   => cleffo_get_payment_notify_url( $order ),
            'redirect_url' => esc_url_raw( $payment_url ),
        );
    }
}

if ( ! function_exists( 'cleffo_build_create_charge_payload' ) ) {
    /**
     * Build the payload expected by the Cleffo create-charge endpoint.
     *
     * @param WC_Order $order
     * @param array    $request
     * @param string   $client_key
     * @param string   $test_mode
     * @return array
     */
    function cleffo_build_create_charge_payload( $order, $request, $client_key, $test_mode = 'test' ) {
        $suap_used_client_key = cleffo_sanitize_request_field( $request, 'suap_used_client_key', $client_key );
        $suap_mode            = cleffo_sanitize_request_field( $request, 'suap_mode', $test_mode );
        $payment_urls         = cleffo_get_order_payment_urls( $order );

        $payload = array(
            'billing_first_name' => cleffo_sanitize_request_field( $request, 'billing_first_name', $order->get_billing_first_name() ),
            'billing_last_name'  => cleffo_sanitize_request_field( $request, 'billing_last_name', $order->get_billing_last_name() ),
            'billing_country'    => cleffo_sanitize_request_field( $request, 'billing_country', $order->get_billing_country() ),
            'billing_address_1'  => cleffo_sanitize_request_field( $request, 'billing_address_1', $order->get_billing_address_1() ),
            'billing_city'       => cleffo_sanitize_request_field( $request, 'billing_city', $order->get_billing_city() ),
            'billing_state'      => cleffo_sanitize_request_field( $request, 'billing_state', $order->get_billing_state() ),
            'billing_postcode'   => cleffo_sanitize_request_field( $request, 'billing_postcode', $order->get_billing_postcode() ),
            'billing_phone'      => cleffo_sanitize_request_field( $request, 'billing_phone', $order->get_billing_phone() ),
            'billing_email'      => cleffo_sanitize_request_field( $request, 'billing_email', $order->get_billing_email() ),
            'shipping_first_name' => cleffo_sanitize_request_field( $request, 'shipping_first_name', $order->get_shipping_first_name() ),
            'shipping_last_name'  => cleffo_sanitize_request_field( $request, 'shipping_last_name', $order->get_shipping_last_name() ),
            'shipping_country'    => cleffo_sanitize_request_field( $request, 'shipping_country', $order->get_shipping_country() ),
            'shipping_address_1'  => cleffo_sanitize_request_field( $request, 'shipping_address_1', $order->get_shipping_address_1() ),
            'shipping_city'       => cleffo_sanitize_request_field( $request, 'shipping_city', $order->get_shipping_city() ),
            'shipping_state'      => cleffo_sanitize_request_field( $request, 'shipping_state', $order->get_shipping_state() ),
            'shipping_postcode'   => cleffo_sanitize_request_field( $request, 'shipping_postcode', $order->get_shipping_postcode() ),
            'order_comments'      => cleffo_sanitize_request_field( $request, 'order_comments', $order->get_customer_note() ),
            'shipping_method'     => cleffo_get_shipping_methods_payload( $order, $request ),
            'payment_method'      => cleffo_sanitize_request_field( $request, 'payment_method', 'suap_wordpresspayplugin' ),
            'suap_mode'           => $suap_mode,
            'suap_stripe_token'   => cleffo_sanitize_request_field( $request, 'suap_stripe_token' ),
            'order_amount'        => wc_format_decimal( $order->get_total(), 2 ),
            'order_currency'      => $order->get_currency(),
            'suap_client_key'     => $client_key,
            'suap_used_client_key' => $suap_used_client_key,
            'merchant_order_id'   => cleffo_sanitize_request_field( $request, 'merchant_order_id', (string) $order->get_id() ),
            'success_url'         => cleffo_sanitize_request_field( $request, 'success_url', $payment_urls['success_url'] ),
            'cancel_url'          => cleffo_sanitize_request_field( $request, 'cancel_url', $payment_urls['cancel_url'] ),
            'error_url'           => cleffo_sanitize_request_field( $request, 'error_url', $payment_urls['error_url'] ),
            'notify_url'          => cleffo_sanitize_request_field( $request, 'notify_url', $payment_urls['notify_url'] ),
            'redirect_url'        => cleffo_sanitize_request_field( $request, 'redirect_url', $payment_urls['redirect_url'] ),
        );

        if ( isset( $request['woocommerce-process-checkout-nonce'] ) ) {
            $payload['woocommerce-process-checkout-nonce'] = sanitize_text_field(
                wp_unslash( $request['woocommerce-process-checkout-nonce'] )
            );
        }

        if ( isset( $request['_wp_http_referer'] ) ) {
            $payload['_wp_http_referer'] = sanitize_text_field(
                wp_unslash( $request['_wp_http_referer'] )
            );
        }

        return $payload;
    }
} 