<?php



class WC_SUA_Payment_Gateway_Plugin extends WC_Payment_Gateway {

    public $id = null;

    public $notices = array();

    public $get_connected_puadvertiser = array();

    public $testmode = "yes";

    public $private_key = "";

    public $publishable_key = "";

    public $agency7_client_key = "";

    public $api_key = "";

    public $allow_guest = "no";

    public $show_logo = "yes";
    public $show_logo_link = "";

    // Add logging, The logs can be viewed in WooCommerce > Status > Logs in the WordPress admin panel.
    // $logger = wc_get_logger();
    // $logger->info('Initializing Cleffo Payment Gateway', array('source' => 'cleffo-payment'));


    public function __construct(){

        add_action( 'admin_notices', array( $this, 'admin_notices' ), 15 );

		$this->id = 'suap_wordpresspayplugin';

        $this->has_fields = false;

        $this->title = "Cleffo";

        $this->description = "Cleffo";

        $this->method_title = "Cleffo";

        $this->method_description = "Cleffo";

        $this->init_form_fields();

        $this->init_settings();

        $this->enabled = $this->settings['enabled'];

	    $this->testmode = 'test' === $this->settings['test_mode'];

        $this->show_logo = isset($this->settings['show_logo']) ? $this->settings['show_logo'] : 'yes';
        $this->show_logo_link = isset($this->settings['show_logo_link']) ? $this->settings['show_logo_link'] : 'https://cleffo.com';

        $this->agency7_client_key = isset( $this->settings['agency7_client_key'] ) ? $this->settings['agency7_client_key'] : '';

        $this->api_key = isset( $this->settings['api_key'] ) ? trim( $this->settings['api_key'] ) : '';

        $this->allow_guest = isset( $this->settings['allow_guest'] ) ? $this->settings['allow_guest'] : 'no';

        $test_title	= '';	

        $test_description	= '';

        if ( 'test' == $this->settings['test_mode'] ) {
            $test_title 		= ' [TEST MODE]';
        } //END--test_mode=yes
        $this->title 			= $this->settings['title'].$test_title; // Title as displayed on Frontend
        $default_description    = __('Pay securely using a credit or debit card (Visa/MC/Amex). Powered by Cleffo', 'woo_mup_wordpresspayplugin');
        $setting_description    = isset($this->settings['description']) ? trim((string) $this->settings['description']) : '';
        $this->description 		= ($setting_description !== '' ? $setting_description : $default_description) . $test_description; // Description as displayed on Frontend

        $this->supports = array(
            'products'
        );
        // This action hook saves the settings
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        // We need custom JavaScript to obtain a token
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        // Register webhook for 3D Secure authentication return
        add_action( 'woocommerce_api_cleffo_3ds_return', array( $this, 'handle_3ds_return' ) );
        add_action( 'woocommerce_api_cleffo_payment_return', array( $this, 'handle_payment_return' ) );
        add_action( 'woocommerce_api_cleffo_payment_notify', array( $this, 'handle_payment_notify' ) );

    }

    public function is_available() {
        if ( ! is_user_logged_in() && 'yes' !== $this->allow_guest ) {
            return false;
        }

        return parent::is_available();
    }

    public function admin_notices(){
        // $class = 'notice notice-error';
        // $message = __( 'Irks! An error has occurred.', 'sample-text-domain' );
        // printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }



    public function payment_scripts() {
        // we need JavaScript to process a token only on cart/checkout pages, right?
        if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
            
            return;
        }

        // if our payment gateway is disabled, we do not have to enqueue JS too
        if ( 'no' === $this->enabled ) {
            return;
        }

        // no reason to enqueue JavaScript if API keys are not set
        if ( empty( $this->private_key ) || empty( $this->publishable_key ) ) {
            return;
        }

        // do not work with card detailes without SSL unless your website is in a test mode
        if ( ! $this->testmode && ! is_ssl() ) {
            return;
        }

        // External script: let's suppose it is our payment processor JavaScript that allows to obtain a token
        //wp_enqueue_script( 'sua_payments_js', PAYMENTS_API_BASE_URL.'/assets/js/suapayments.js' );

        // Internal script: and this is our custom JS in your plugin directory that works with
        wp_register_script( 'woocommerce_suajs', SUA_Payments_PUBLIC."js/suap_client.js", array( 'jquery', 'sua_payments_js' ) );

        // in most payment processors you have to use PUBLIC KEY to obtain a token
        wp_localize_script( 'woocommerce_suajs', 'sua_payments_params', array(
            'publishableKey' => $this->publishable_key
        ) );
        wp_localize_script( 'woocommerce_suajs', 'SuaPaymentsProcessorObj', null);
        wp_enqueue_script( 'woocommerce_suajs' );

    }



    public function payment_fields() {
       
        $logo_html = '';
        if (isset($this->show_logo) && $this->show_logo === 'yes' ) {
            $logo_url = !empty($this->show_logo_link) ? $this->show_logo_link : SUA_Payments_PUBLIC_IMAGES . 'cleffo-logo.svg';
            $logo_html = ' <a href="'.$logo_url.'" target="_blank"><img src="'.$logo_url.'" alt="Cleffo Logo" style="max-width: 100px; margin: 2px 0px 0px 0px;height:23px;" /></a>';
        }
        // ok, let's display some description before the payment form
        if( $this->description ) {
            // you can instructions for test mode, I mean test card numbers etc.
            if ( $this->testmode ) {
                $this->description .= $logo_html;
                $this->description .= '<br><span style="font-size:12px">TEST MODE ENABLED. In test mode, you can use the card numbers listed in <a href="#">documentation</a>.</span>';
                $this->description  = '<p style="margin:0px">'.trim( $this->description ).'</p>';
            }else{
              $this->description = $this->description .' '. $logo_html; 
            }
            
            // display the description with <p> tags etc.
            echo wp_kses_post( $this->description );
        }

        // I will change the ID to be more descriptive
        echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">';

        // Add this action hook if you want your custom payment gateway to support it

        do_action( 'woocommerce_credit_card_form_start', $this->id );
        

        $iframeUrlParams = ['mode'=>$this->settings['test_mode'],"client_key"=>$this->agency7_client_key];

        $queryParams = http_build_query($iframeUrlParams);

        $iframeBurl = PAYMENTS_API_BASE_URL."/card-element";

        echo '<input value="'.$this->settings['test_mode'].'" name="suap_mode" id="suap_mode" type="hidden" style="width:100%">';

        echo '<input value="" name="suap_stripe_token" id="suap_stripe_token" type="hidden" autocomplete="off" style="width:100%">';
        echo '<input value="" name="suap_used_client_key" id="suap_used_client_key" type="hidden" autocomplete="off" style="width:100%">';

        $gateway = cleffo_get_payment_gateway();
        $iframeHeight = (empty($gateway) || $gateway == 'stripe') ? 'height:80px;' : '';
        echo '<iframe id="myFrame" class="'.$gateway.'" src="'.$iframeBurl.'?'.$queryParams.'" 
            style="width:100%;border:none;'.$iframeHeight.'"
            allow="publickey-credentials-get; payment; camera; microphone; geolocation"
            sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox"></iframe>';

        echo '<div class="clear"></div>
        <script>
            // Initialize payment handler object (check if already exists)
            if (typeof CleffoPaymentHandler === "undefined") {
                var CleffoPaymentHandler = {
                stripeToken: "",
                callCount: 0,
                
                handleMessage: function(event) {
                    // IMPORTANT: check the origin of the data!
                    console.log("place order clicked");
                    jQuery("#place_order").prop("disabled", false);
                    if (event.origin === "'.PAYMENTS_API_BASE_URL.'") {
                        // Data sent with postMessage is stored in event.data:
                        if(event.data && typeof event.data === "string") {
                            const tokenData = JSON.parse(event.data);
                            this.stripeToken = tokenData.token;
                            const used_client_key = tokenData.used_client_key;
                            document.getElementById("suap_stripe_token").value = this.stripeToken;
                            document.getElementById("suap_used_client_key").value = used_client_key;
                            document.getElementById("place_order").click();
                        } else if (event.data === "") {
                            if(this.callCount > 0) {
                                this.callCount--;
                                console.log("Call count decreased to:", this.callCount);
                            }
                        }
                    }
                },
                
                sendMessage: function() {
                    // Collect billing details from the checkout form
                    const billingData = {
                        billing_first_name: jQuery("#billing_first_name").val() || "",
                        billing_last_name: jQuery("#billing_last_name").val() || "",
                        billing_company: jQuery("#billing_company").val() || "",
                        billing_address_1: jQuery("#billing_address_1").val() || "",
                        billing_address_2: jQuery("#billing_address_2").val() || "",
                        billing_city: jQuery("#billing_city").val() || "",
                        billing_state: jQuery("#billing_state").val() || "",
                        billing_postcode: jQuery("#billing_postcode").val() || "",
                        billing_country: jQuery("#billing_country").val() || "",
                        billing_phone: jQuery("#billing_phone").val() || "",
                        billing_email: jQuery("#billing_email").val() || "",
                        order_total: jQuery(".order-total .amount").text() || "",
                        order_currency: "'.get_woocommerce_currency().'",
                        test_mode: "'.$this->settings['test_mode'].'"
                    };
                    
                    // Validate all mandatory billing fields
                    const requiredFields = [
                        // { field: "billing_first_name", label: "First Name" },
                        // { field: "billing_last_name", label: "Last Name" },
                        // { field: "billing_address_1", label: "Address" },
                        // { field: "billing_city", label: "City" },
                        // { field: "billing_state", label: "State" },
                        // { field: "billing_postcode", label: "Postcode" },
                        // { field: "billing_country", label: "Country" },
                        { field: "billing_email", label: "Email" }
                    ];
                    
                    const missingFields = [];
                    
                    // Clear all error classes first
                    requiredFields.forEach(function(item) {
                        jQuery("#" + item.field).removeClass("error");
                    });
                    
                    // Check each required field
                    requiredFields.forEach(function(item) {
                        const fieldValue = billingData[item.field];
                        if (!fieldValue || fieldValue.trim() === "") {
                            missingFields.push(item.label);
                            jQuery("#" + item.field).addClass("error");
                        }
                    });
                    
                    // Check if email is valid (only if it\'s not empty)
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (billingData.billing_email && billingData.billing_email.trim() !== "" && !emailRegex.test(billingData.billing_email)) {
                        missingFields.push("Valid Email");
                        jQuery("#billing_email").addClass("error");
                    }
                    
                    // If there are missing fields, show error and don\'t proceed
                    if (missingFields.length > 0) {
                        jQuery("#place_order").prop("disabled", true);
                        const errorMessage = "Please fill in the following required fields: " + missingFields.join(", ");
                        console.error("Validation failed:", errorMessage);
                        console.log("Missing fields:", missingFields);
                        
                        // Remove existing error messages
                        jQuery(".woocommerce-error").remove();
                        
                        // Add new error message
                        jQuery(".woocommerce-checkout").prepend(
                            "<div class=\"woocommerce-error\" role=\"alert\">" + errorMessage + "</div>"
                        );
                        
                        // Scroll to first error field
                        const firstMissingField = requiredFields.find(function(item) { 
                            return !billingData[item.field] || billingData[item.field].trim() === ""; 
                        });
                        
                        if (firstMissingField) {
                            jQuery("html, body").animate({
                                scrollTop: jQuery("#" + firstMissingField.field).offset().top - 250
                            }, 500);
                        }
                        
                        return false;
                    }
                    
                    // Remove any existing error messages if validation passes
                    jQuery(".woocommerce-error").remove();

                    document.getElementById("myFrame").contentWindow.postMessage(JSON.stringify(billingData), "'.PAYMENTS_API_BASE_URL.'");
                }
            };

            // Add event listener using the handler object
            window.addEventListener("message", function(event) {
                CleffoPaymentHandler.handleMessage.call(CleffoPaymentHandler, event);
            }, false);

            // Handle checkout error
            jQuery(document.body).on("checkout_error", function() {
                jQuery("#place_order").prop("disabled", false);
                console.log("Checkout error occurred, resetting payment handler");
                CleffoPaymentHandler.callCount = 0;
                CleffoPaymentHandler.stripeToken = "";
                console.warn("checkout error occurred!");
            });
            } // End of CleffoPaymentHandler declaration check
        </script>';
        do_action( 'woocommerce_credit_card_form_end', $this->id );
        echo '<div class="clear"></div></fieldset>';
    }



    public function process_payment($order_id) {
        if ( ! is_user_logged_in() && 'yes' !== $this->allow_guest ) {
            wc_add_notice( __( 'You must be logged in to use this payment method.', 'woocommerce' ), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => 'Login required',
            );
        }

        $order = wc_get_order($order_id);

        if ( ! $order ) {
            cleffo_log( 'ERROR: Order not found: ' . $order_id );
            wc_add_notice( __( 'Payment error: Order not found.', 'woocommerce' ), 'error' );
            return array(
                'result'   => 'failure',
                'messages' => 'Order not found',
            );
        }
        
        // Get the token from POST data
        $suap_stripe_token = isset($_POST['suap_stripe_token']) ? sanitize_text_field($_POST['suap_stripe_token']) : '';
        
        if (empty($suap_stripe_token)) {
            cleffo_log('ERROR: Missing payment token');
            wc_add_notice(__('Payment error: Please try again.', 'woocommerce'), 'error');
            return array(
                'result' => 'failure',
                'messages' => 'Missing payment token'
            );
        }

        try {
            $paymentControllerObj = new PaymentController();
            $paymentData = cleffo_build_create_charge_payload(
                $order,
                $_POST,
                $this->agency7_client_key,
                isset( $this->settings['test_mode'] ) ? $this->settings['test_mode'] : 'test'
            );

            cleffo_log('Payment data being sent to processor: ' . json_encode(cleffo_mask_sensitive_payment_fields($paymentData)));
            
            $resp = $paymentControllerObj->createCharge($paymentData);

            // Check for API errors - handle both old and new response formats
            $has_error = false;
            $error_message = '';
            
            // Check for old format: status == 1 with error
            //{"status":true,"message":"Payment link created successfully.","errors":null,"error_code":null,"data":{"payment_link":"http:\/\/local.cleffo.com\/authorize?key=mJft04vnwCr2hSa2sQwzO7PBD9rKUx89","txn_ref_no":"3M2921864797api","merchant_order_id":"ORD_178292186474","payment_source":"api"},"meta_params":null,"lang":"en"}
            if ( ! empty( $resp['data']['payment_link'] ) && ! empty( $resp['status'] ) && empty( $resp['error'] ) ) {
                cleffo_log('Cardpaymentz Redirecting to: ' . $resp['data']['payment_link']);

                if ( ! empty( $resp['data']['txn_ref_no'] ) ) {
                    $order->update_meta_data(
                        '_cleffo_txn_ref',
                        sanitize_text_field( $resp['data']['txn_ref_no'] )
                    );
                }
                $order->update_meta_data(
                    '_cleffo_payment_link',
                    esc_url_raw( $resp['data']['payment_link'] )
                );
                $order->update_status(
                    'pending',
                    __( 'Awaiting Cleffo payment confirmation.', 'woocommerce' )
                );
                $order->save();

                WC()->session->set( 'cleffo_pending_order_id', $order_id );

                return array(
                    'result'   => 'success',
                    'redirect' => $resp['data']['payment_link'],
                );
            } else if (isset($resp['status']) && $resp['status'] == 1 && isset($resp['error']) && !empty($resp['error'])) {
                $has_error = true;
                $error_message = $resp['error'];
            }
            // Check for new format: status == false
            elseif (isset($resp['status']) && $resp['status'] === false) {
                $has_error = true;
                if (isset($resp['errors']['message']) && !empty($resp['errors']['message'])) {
                    $error_message = $resp['errors']['message'];
                } else {
                    $error_message = 'Payment processing failed';
                }
            }
            
            if ($has_error) {
                cleffo_log('ERROR: Payment failed with error: ' . $error_message);
                
                // Check if it's an auth_required error (old format)
                if (isset($resp['error']) && $resp['error'] == 'auth_required') {
                    // Handle 3D Secure authentication
                    if (isset($resp['data']['auth_url']) && !empty($resp['data']['auth_url'])) {
                        $auth_url = $resp['data']['auth_url'];
                        $redirect_url = cleffo_get_wc_api_url( 'cleffo_3ds_return' );

                        // Add redirect_url, client_key, and payment mode parameters to auth_url
                        $separator = (strpos($auth_url, '?') !== false) ? '&' : '?';
                        $auth_url_with_redirect = $auth_url . $separator . 'redirect_url=' . urlencode($redirect_url);

                        cleffo_log('3D Secure authentication required. Redirecting to: ' . $auth_url_with_redirect);

                        // Store order ID in session for when user returns
                        WC()->session->set('cleffo_pending_order_id', $order_id);

                        // Return redirect response
                        return array(
                            'result' => 'success',
                            'redirect' => $auth_url_with_redirect
                        );
                    } else {
                        cleffo_log('ERROR: auth_required but no auth_url provided');
                        throw new Exception('Authentication required but no authentication URL provided');
                    }
                }
                
                // For any other error, throw exception
                throw new Exception($error_message);
            }

            // If we get here, payment was successful
            cleffo_log('SUCCESS: Payment completed successfully');
            $order->payment_complete();
            $order->add_order_note(__('Payment completed via Cleffo Gateway.', 'woocommerce'));
            
            // Empty cart
            WC()->cart->empty_cart();

            // Return success
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );

        } catch (Exception $e) {
            cleffo_log('EXCEPTION: Payment processing failed with exception: ' . $e->getMessage());
            wc_add_notice(__('Payment error: ', 'woocommerce') . $e->getMessage(), 'error');
            return array(
                'result' => 'failure',
                'messages' => $e->getMessage()
            );
        }
    }

    /**
     * Handle redirect payment return from Cleffo (GET only: success/cancel/error via query params).
     */
    public function handle_payment_return() {
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
            cleffo_log( 'ERROR: Payment return endpoint accepts GET requests only', array(), 'error' );
            status_header( 405 );
            header( 'Allow: GET' );
            exit;
        }

        cleffo_log( '=== CLEFFO PAYMENT RETURN HANDLER === GET parameters: ' . wp_json_encode( $_GET ) );

        $params = cleffo_get_return_params();

        $order = cleffo_get_order_from_return_request( $params );
        if ( ! $order ) {
            cleffo_log( 'ERROR: Invalid payment return request', array(), 'error' );
            wc_add_notice( __( 'Invalid payment return request.', 'woocommerce' ), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        if ( $order->get_payment_method() !== $this->id ) {
            cleffo_log( 'ERROR: Payment return for unsupported gateway on order ' . $order->get_id(), array(), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        $status        = isset( $params['status'] ) ? strtolower( sanitize_text_field( $params['status'] ) ) : '';
        $error_message = isset( $params['error'] ) ? sanitize_text_field( $params['error'] ) : '';
        $txn_ref       = isset( $params['txn_ref_no'] )
            ? sanitize_text_field( $params['txn_ref_no'] )
            : '';

        if ( '' === $txn_ref ) {
            $txn_ref = $order->get_meta( '_cleffo_txn_ref' );
        }

        cleffo_log(
            'Payment return for order ' . $order->get_id() . ' status=' . $status . ' txn=' . $txn_ref
        );

        if ( '' === $status ) {
            cleffo_log( 'Payment return called without status. No order change.' );
            status_header( 200 );
            exit;
        }

        if ( $order->is_paid() ) {
            WC()->session->set( 'cleffo_pending_order_id', null );
            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;
        }

        $success_statuses = array( 'success', 'paid', 'succeeded', 'completed' );

        if ( in_array( $status, $success_statuses, true ) ) {
            if ( '' === $txn_ref ) {
                cleffo_log( 'ERROR: Success status but no transaction reference to verify for order ' . $order->get_id(), array(), 'error' );
                $order->update_status( 'on-hold', __( 'Cleffo returned success but no transaction reference to verify.', 'woocommerce' ) );
                $order->save();
                WC()->session->set( 'cleffo_pending_order_id', null );
                wc_add_notice( __( 'We could not confirm your payment automatically. Our team will verify it shortly.', 'woocommerce' ), 'notice' );
                wp_safe_redirect( $this->get_return_url( $order ) );
                exit;
            }

            $paymentControllerObj = new PaymentController();

            $max_attempts   = 2;
            $retry_delay    = 5; // seconds between attempts
            $api_ok         = false;
            $payment_status = '';
            $resp           = array();

            for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
                $resp = $paymentControllerObj->verifyPayment( $txn_ref );
                //cleffo_log( 'Payment verification attempt ' . $attempt . '/' . $max_attempts . ' response: ' . json_encode( $resp ) );

                $api_ok         = ! empty( $resp['status'] );
                $payment_status = isset( $resp['data']['payment_status'] )
                    ? strtolower( sanitize_text_field( $resp['data']['payment_status'] ) )
                    : '';

                //cleffo_log( 'Verify attempt ' . $attempt . ' for order ' . $order->get_id() . ' api_ok=' . var_export( $api_ok, true ) . ' payment_status=' . $payment_status );

                // Stop as soon as we get a definitive success.
                if ( $api_ok && in_array( $payment_status, $success_statuses, true ) ) {
                    break;
                }

                // Don't sleep after the final attempt.
                if ( $attempt < $max_attempts ) {
                    sleep( $retry_delay );
                }
            }

            $pending_statuses = array( 'pending', 'processing', 'in_progress', 'initiated', 'created', 'authorized' );

            if ( ! $api_ok ) {
                cleffo_log( 'ERROR: Verify API call failed for order ' . $order->get_id(), array(), 'error' );
                $order->update_status( 'on-hold', __( 'Could not verify payment with Cleffo. Manual review required.', 'woocommerce' ) );
                $order->save();
                WC()->session->set( 'cleffo_pending_order_id', null );
                wc_add_notice( __( 'We could not confirm your payment automatically. Our team will verify it shortly.', 'woocommerce' ), 'notice' );
                wp_safe_redirect( $this->get_return_url( $order ) );
                exit;
            }

            if ( in_array( $payment_status, $success_statuses, true ) ) {
                $order->payment_complete( $txn_ref );
                $order->add_order_note(
                    __( 'Payment verified & completed via Cleffo.', 'woocommerce' ) .
                    ( $txn_ref ? ' Txn: ' . $txn_ref : '' )
                );
                $order->save();

                WC()->session->set( 'cleffo_pending_order_id', null );
                WC()->cart->empty_cart();

                wp_safe_redirect( $this->get_return_url( $order ) );
                exit;
            }

            if ( in_array( $payment_status, $pending_statuses, true ) ) {
                $order->update_status( 'on-hold', sprintf(
                    /* translators: %s: payment status from gateway */
                    __( 'Cleffo payment is still pending (status: %s). Awaiting confirmation.', 'woocommerce' ),
                    $payment_status
                ) );
                $order->save();

                WC()->session->set( 'cleffo_pending_order_id', null );
                wc_add_notice( __( 'Your payment is being processed. We will confirm your order shortly.', 'woocommerce' ), 'notice' );
                wp_safe_redirect( $this->get_return_url( $order ) );
                exit;
            }

            $order->update_status( 'failed', sprintf(
                /* translators: %s: payment status from gateway */
                __( 'Cleffo verification returned status: %s.', 'woocommerce' ),
                $payment_status ? $payment_status : 'unknown'
            ) );
            $order->save();
            WC()->session->set( 'cleffo_pending_order_id', null );
            wc_add_notice( __( 'Payment failed. Please try again.', 'woocommerce' ), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        $failure_reason = '';
        $user_message   = __( 'Payment failed. Please try again.', 'woocommerce' );

        switch ( $status ) {
            case 'cancelled':
                $failure_reason = 'Payment was cancelled by customer';
                $user_message   = __( 'Payment was cancelled. Please try again.', 'woocommerce' );
                $order->update_status( 'cancelled', $failure_reason );
                break;
            case 'failed':
                $failure_reason = 'Payment failed';
                $user_message   = __( 'Payment failed. Please try again.', 'woocommerce' );
                $order->update_status( 'failed', $failure_reason );
                break;
            case 'error':
                $failure_reason = 'Payment error: ' . $error_message;
                $user_message   = __( 'Payment error. Please try again.', 'woocommerce' );
                $order->update_status( 'failed', $failure_reason );
                break;
            case 'timeout':
                $failure_reason = 'Payment timed out';
                $user_message   = __( 'Payment timed out. Please try again.', 'woocommerce' );
                $order->update_status( 'failed', $failure_reason );
                break;
            default:
                $failure_reason = 'Payment return failed with status: ' . $status;
                $order->update_status( 'failed', $failure_reason );
                break;
        }

        cleffo_log( 'Payment return failed for order ' . $order->get_id() . ' - ' . $failure_reason, array(), 'error' );

        WC()->session->set( 'cleffo_pending_order_id', null );
        wc_add_notice( $user_message, 'error' );
        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

    /**
     * Handle Cleffo server-to-server payment status notify (POST only).
     *
     * Endpoint: /wc-api/cleffo_payment_notify?order_id={id}&key={order_key}
     */
    public function handle_payment_notify() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
            cleffo_log( 'ERROR: Payment notify endpoint accepts POST requests only', array(), 'error' );
            status_header( 405 );
            header( 'Allow: POST' );
            header( 'Content-Type: application/json; charset=utf-8' );
            echo wp_json_encode(
                array(
                    'status'  => false,
                    'message' => 'Method not allowed. Use POST.',
                )
            );
            exit;
        }

        if ( '' === cleffo_get_api_key() ) {
            cleffo_log( 'ERROR: Notify rejected because store API key is not configured', array(), 'error' );
            status_header( 503 );
            header( 'Content-Type: application/json; charset=utf-8' );
            echo wp_json_encode(
                array(
                    'status'  => false,
                    'message' => 'Notify endpoint is not configured.',
                )
            );
            exit;
        }

        $provided_api_key = cleffo_get_request_api_key_header();
        if ( ! cleffo_is_valid_notify_api_key( $provided_api_key ) ) {
            cleffo_log( 'ERROR: Invalid or missing x-api-key on payment notify request', array(), 'error' );
            status_header( 401 );
            header( 'Content-Type: application/json; charset=utf-8' );
            echo wp_json_encode(
                array(
                    'status'  => false,
                    'message' => 'Unauthorized. Invalid or missing x-api-key.',
                )
            );
            exit;
        }

        $params = cleffo_get_notify_params();
        cleffo_log( '=== CLEFFO PAYMENT NOTIFY HANDLER === params: ' . wp_json_encode( $params ) );

        $order = cleffo_get_order_from_return_request( $params );
        if ( ! $order ) {
            cleffo_log( 'ERROR: Invalid payment notify request', array(), 'error' );
            status_header( 400 );
            header( 'Content-Type: application/json; charset=utf-8' );
            echo wp_json_encode(
                array(
                    'status'  => false,
                    'message' => 'Invalid notify request.',
                )
            );
            exit;
        }

        if ( $order->get_payment_method() !== $this->id ) {
            cleffo_log( 'ERROR: Notify for unsupported gateway on order ' . $order->get_id(), array(), 'error' );
            status_header( 400 );
            header( 'Content-Type: application/json; charset=utf-8' );
            echo wp_json_encode(
                array(
                    'status'  => false,
                    'message' => 'Unsupported payment method.',
                )
            );
            exit;
        }

        $status  = cleffo_extract_notify_status( $params );
        $txn_ref = cleffo_extract_notify_txn_ref( $params );

        if ( '' === $txn_ref ) {
            $txn_ref = $order->get_meta( '_cleffo_txn_ref' );
        }

        cleffo_log(
            'Payment notify for order ' . $order->get_id() . ' status=' . $status . ' txn=' . $txn_ref
        );

        if ( '' === $status ) {
            status_header( 400 );
            header( 'Content-Type: application/json; charset=utf-8' );
            echo wp_json_encode(
                array(
                    'status'  => false,
                    'message' => 'Missing payment status.',
                )
            );
            exit;
        }

        $success_statuses = array( 'success', 'paid', 'succeeded', 'completed', 'captured', 'approved' );
        $pending_statuses = array( 'pending', 'processing', 'in_progress', 'initiated', 'created', 'authorized' );
        $failed_statuses  = array( 'failed', 'error', 'declined', 'cancelled', 'canceled', 'timeout' );

        // Prefer server-side verify when we have a txn ref.
        if ( '' !== $txn_ref ) {
            $paymentControllerObj = new PaymentController();
            $resp                 = $paymentControllerObj->verifyPayment( $txn_ref );
            cleffo_log( 'Notify verify response for order ' . $order->get_id() . ': ' . wp_json_encode( $resp ) );

            if ( ! empty( $resp['status'] ) && ! empty( $resp['data']['payment_status'] ) ) {
                $status = strtolower( sanitize_text_field( $resp['data']['payment_status'] ) );
            }
        }

        if ( $order->is_paid() ) {
            if ( in_array( $status, $success_statuses, true ) ) {
                status_header( 200 );
                header( 'Content-Type: application/json; charset=utf-8' );
                echo wp_json_encode(
                    array(
                        'status'  => true,
                        'message' => 'Order already paid.',
                        'data'    => array(
                            'order_id' => $order->get_id(),
                            'status'   => $order->get_status(),
                        ),
                    )
                );
                exit;
            }
        }

        if ( in_array( $status, $success_statuses, true ) ) {
            if ( ! $order->is_paid() ) {
                $order->payment_complete( $txn_ref );
                $order->add_order_note(
                    __( 'Payment completed via Cleffo notify webhook.', 'woocommerce' ) .
                    ( $txn_ref ? ' Txn: ' . $txn_ref : '' )
                );
                $order->save();
            }

            status_header( 200 );
            header( 'Content-Type: application/json; charset=utf-8' );
            echo wp_json_encode(
                array(
                    'status'  => true,
                    'message' => 'Payment marked as complete.',
                    'data'    => array(
                        'order_id' => $order->get_id(),
                        'status'   => $order->get_status(),
                    ),
                )
            );
            exit;
        }

        if ( in_array( $status, $pending_statuses, true ) ) {
            if ( ! $order->is_paid() && ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
                $order->update_status(
                    'on-hold',
                    sprintf(
                        /* translators: %s: payment status from gateway */
                        __( 'Cleffo notify: payment still pending (status: %s).', 'woocommerce' ),
                        $status
                    )
                );
                $order->save();
            }

            status_header( 200 );
            header( 'Content-Type: application/json; charset=utf-8' );
            echo wp_json_encode(
                array(
                    'status'  => true,
                    'message' => 'Payment still pending.',
                    'data'    => array(
                        'order_id' => $order->get_id(),
                        'status'   => $order->get_status(),
                    ),
                )
            );
            exit;
        }

        if ( in_array( $status, $failed_statuses, true ) ) {
            if ( ! $order->is_paid() ) {
                $new_status = in_array( $status, array( 'cancelled', 'canceled' ), true ) ? 'cancelled' : 'failed';
                $order->update_status(
                    $new_status,
                    sprintf(
                        /* translators: %s: payment status from gateway */
                        __( 'Cleffo notify: payment status updated to %s.', 'woocommerce' ),
                        $status
                    )
                );
                $order->save();
            }

            status_header( 200 );
            header( 'Content-Type: application/json; charset=utf-8' );
            echo wp_json_encode(
                array(
                    'status'  => true,
                    'message' => 'Payment status updated.',
                    'data'    => array(
                        'order_id' => $order->get_id(),
                        'status'   => $order->get_status(),
                    ),
                )
            );
            exit;
        }

        cleffo_log( 'Notify received unknown status for order ' . $order->get_id() . ': ' . $status, array(), 'error' );
        status_header( 200 );
        header( 'Content-Type: application/json; charset=utf-8' );
        echo wp_json_encode(
            array(
                'status'  => true,
                'message' => 'Notify received; unknown payment status ignored.',
                'data'    => array(
                    'order_id'        => $order->get_id(),
                    'payment_status'  => $status,
                    'order_status'    => $order->get_status(),
                ),
            )
        );
        exit;
    }

    /**
     * Handle 3D Secure authentication return
     */
    public function handle_3ds_return() {
        cleffo_log( '=== 3D SECURE RETURN HANDLER === GET parameters: ' . wp_json_encode( $_GET ) );

        $params = cleffo_get_return_params();
        cleffo_log( 'Normalised 3DS return params: ' . wp_json_encode( $params ) );

        // Get the pending order ID from session
        $order_id = WC()->session->get( 'cleffo_pending_order_id' );

        if ( ! $order_id ) {
            cleffo_log( 'ERROR: No pending order ID found in session', array(), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            cleffo_log( 'ERROR: Order not found: ' . $order_id, array(), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        // Check authentication status
        $status        = isset( $params['status'] ) ? strtolower( sanitize_text_field( $params['status'] ) ) : '';
        $error_message = isset( $params['error'] ) ? sanitize_text_field( $params['error'] ) : '';

        cleffo_log('3D Secure return status: ' . $status . ', error: ' . $error_message);

        if ($status === 'success') {
            cleffo_log('3D Secure authentication successful for order: ' . $order_id);
            
            // Complete the order
            $order->payment_complete();
            $order->add_order_note(__('Payment completed via Cleffo Gateway (3D Secure).', 'woocommerce'));
            
            // Clear the pending order from session
            WC()->session->set('cleffo_pending_order_id', null);
            
            // Empty cart
            WC()->cart->empty_cart();
            
            // Redirect to thank you page
            wp_redirect($this->get_return_url($order));
            exit;
        } else {
            // Handle different failure scenarios
            $failure_reason = '';
            $user_message = '';

            switch ($status) {
                case 'failed':
                    $failure_reason = '3D Secure authentication failed';
                    $user_message = __('Payment authentication failed. Please try again.', 'woocommerce');
                    break;
                case 'cancelled':
                    $failure_reason = '3D Secure authentication was cancelled by user';
                    $user_message = __('Payment authentication was cancelled. Please try again.', 'woocommerce');
                    break;
                case 'error':
                    $failure_reason = '3D Secure authentication error: ' . $error_message;
                    $user_message = __('Payment authentication error. Please try again.', 'woocommerce');
                    break;
                case 'timeout':
                    $failure_reason = '3D Secure authentication timed out';
                    $user_message = __('Payment authentication timed out. Please try again.', 'woocommerce');
                    break;
                default:
                    $failure_reason = '3D Secure authentication failed with status: ' . $status;
                    $user_message = __('Payment authentication failed. Please try again.', 'woocommerce');
                    break;
            }

            cleffo_log('3D Secure authentication failed for order: ' . $order_id . ' - ' . $failure_reason);

            // Mark order as failed
            $order->update_status('failed', $failure_reason);

            // Clear the pending order from session
            WC()->session->set('cleffo_pending_order_id', null);

            // Redirect back to checkout with appropriate error message
            wc_add_notice($user_message, 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }
    }

    public function admin_options(){

        echo '<h3>'.__('Cleffo', 'woo_suap_wordpresspayplugin').'</h3>';
        //echo '<p>'.__('Cleffo works by adding payment fields on the checkout and then sending the details to Cleffo for verification. <a href=\'https://pay.Agency7.com\' >Sign up</a> for a Cleffo account, and get your Cleffo account keys.', 'woo_suap_wordpresspayplugin').'</p>';
        echo '<p><small><strong>'.__('Confirm your Mode: Is it LIVE or TEST.').'</strong></small></p>';
        echo '<table class="form-table">';
        // Generate the HTML For the settings form.
        $this->generate_settings_html();
        echo '</table>';
    } //END-admin_options



    public function init_form_fields(){

		$currency = get_woocommerce_currency();

		$this->form_fields = array(

			// Activate the Gateway

			'enabled' => array(

				'title' 		=> __('Enable/Disable:', 'woo_mup_wordpresspayplugin'),

				'type' 			=> 'checkbox',

				'label' 		=> __('Enable Cleffo', 'woo_mup_wordpresspayplugin'),

				'default' 		=> 'yes',

				'description' 	=> 'Show in the Payment List as a payment option'

			),

			'allow_guest' => array(

				'title' 		=> __('Guest Checkout:', 'woo_mup_wordpresspayplugin'),

				'type' 			=> 'checkbox',

				'label' 		=> __('Show to non-logged-in users', 'woo_mup_wordpresspayplugin'),

				'default' 		=> 'no',

				'description' 	=> (
					( function_exists( 'cleffo_get_payment_gateway' ) && cleffo_get_payment_gateway() === 'cardpaymentz' )
						? __( 'If enabled, the Cleffo payment option is available to guests (non-logged-in users) as well.', 'woo_mup_wordpresspayplugin' )
						: __(
							'If enabled, the Cleffo payment option is available to guests (non-logged-in users) as well.<br><i style="color: #ec4d4d;">Please be advised that enabling this feature authorizes Stripe\'s automated systems to perform crawlers-based test transactions on your live site. These automated purchases can inadvertently flag your account for suspicious activity, potentially resulting in suspension. We strongly recommend keeping this setting disabled to maintain account integrity. For a more secure testing environment, we suggest implementing a mandatory user authentication (Sign-in/Sign-up) flow prior to checkout.</i>',
							'woo_mup_wordpresspayplugin'
						)
				),

				'desc_tip' 		=> false

			),

			// Title as displayed on Frontend

			'title' => array(

				'title' 		=> __('Title:', 'woo_mup_wordpresspayplugin'),

				'type'			=> 'text',

				'default' 		=> __('Credit Card (secure)', 'woo_mup_wordpresspayplugin'),

				'description' 	=> __('This controls the title which the user sees during checkout.', 'woo_mup_wordpresspayplugin'),

				'desc_tip' 		=> true

			),

			// Description as displayed on Frontend

			'description' => array(

				'title' 		=> __('Description:', 'woo_mup_wordpresspayplugin'),

				'type' 			=> 'textarea',

				'default' 		=> __("Use credit or debit card.", 'woo_mup_wordpresspayplugin'),

				'description' 	=> __('This controls the description which the user sees during checkout.', 'woo_mup_wordpresspayplugin'),

				'desc_tip' 		=> true

			),

            'agency7_client_key' => array(

				'title' => __( 'Client Key', 'woocommerce' ),

				'type' => 'text',

				'description' => __( 'This key is set while onboarding into https://cleffo.com. You can get this key from Cleffo dashboard.', 'woocommerce' ),

				'custom_attributes' => array( 'required' => 'required' ),

				'default' => __( '', 'woocommerce' ),

				'desc_tip'      => true,

			),

			'api_key' => array(

				'title' => __( 'Cleffo API Key', 'woocommerce' ),

				'type' => 'text',

				'description' => __( 'Cleffo API key used to authenticate server-side requests (x-api-key). Get this from your Cleffo dashboard.', 'woocommerce' ),

				'default' => __( '', 'woocommerce' ),

				'desc_tip'      => true,

			),

			'test_mode' => array(

				'title' 		=> __('Mode:', 'woo_mup_wordpresspayplugin'),

				'type' 			=> 'select',

				'label' 		=> __('Cleffo Test/Live Mode.', 'woo_mup_wordpresspayplugin'),

				'options' 		=> array('test'=>'Test Mode','live'=>'Live Mode'),

				'default' 		=> 'test',

				'description' 	=> __('Mode of Cleffo activities'),

				'desc_tip' 		=> true

			),

			'button_text' => array(

				'title' => __( 'Button Text', 'woocommerce' ),

				'type' => 'text',

				'description' => __( 'Text of MyUser checkout button.', 'woocommerce' ),

				'default' => __( 'Pay Now', 'woocommerce' ),

				'desc_tip'      => true,

			),

			'redirect_page' => array(

                'title' 			=> __('Return Page'),

                'type' 			=> 'select',

                'options' 		=> $this->mup_wordpresspayplugin_get_pages('Select Page'),

                'description' 	=> __('URL of success page', 'woo_mup_wordpresspayplugin'),

                'desc_tip' 		=> true

            ),

			'one_dollar' => array(

				'title' => __( 'How much '.$currency.' is one dollar', 'woocommerce' ),

				'type' => 'text',

				'description' => __( 'Currency Converter, $1= X '.$currency, 'woocommerce' ),

				'default' => __( '1.00', 'woocommerce' ),

				'desc_tip'      => true,

			    'custom_attributes' => array( 'required' => 'required'),

			),

			'button_text' => array(

				'title' => __( 'Button Text', 'woocommerce' ),

				'type' => 'text',

				'description' => __( 'Text of MyUser checkout button.', 'woocommerce' ),

				'default' => __( 'Pay Now', 'woocommerce' ),

				'desc_tip'      => true,

			),

			'image_url_of_store' => array(

				'title' => __( 'Image Url of Store', 'woocommerce' ),

				'type' => 'text',

				'description' => __( 'This will be shown when user click pay button.', 'woocommerce' ),

				'default' => __( '', 'woocommerce' ),

				'desc_tip'      => true,

			),

			'store_description' => array(

				'title' => __( 'Description of your store', 'woocommerce' ),

				'type' => 'text',

				'description' => __( 'This will be shown when user click pay button.', 'woocommerce' ),

				'default' => __( 'Your store description', 'woocommerce' ),

				'desc_tip'      => true,

			),

			'store_name' => array(

				'title' => __( 'Name of your store', 'woocommerce' ),

				'type' => 'text',

				'description' => __( 'This will be shown when user click pay button.', 'woocommerce' ),

				'default' => __('Your Store Name', 'woocommerce' ),

				'desc_tip'      => true,

			),

            'show_logo' => array(
				'title'       => __('Show Cleffo Logo', 'woo_mup_wordpresspayplugin'),
				'type'        => 'checkbox',
				'label'       => __('Display Cleffo logo on checkout', 'woo_mup_wordpresspayplugin'),
				'default'     => 'yes',
				'description' => __('Show logo next to payment description.', 'woo_mup_wordpresspayplugin'),
				'desc_tip'    => true,
			),
			'show_logo_link' => array(
				'title'       => __('Logo Link URL', 'woo_mup_wordpresspayplugin'),
				'type'        => 'text',
				'default'     => SUA_Payments_PUBLIC_IMAGES . 'cleffo-logo.svg',
				'description' => __('URL opened when customer clicks the logo.', 'woo_mup_wordpresspayplugin'),
				'desc_tip'    => true,
			),

		); 



		if(!isset($this->settings['one_dollar'])){

			//$this->settings['one_dollar'] 1 dollar = X onlarin

			$this->settings['one_dollar']=1;

		}

		if($this->settings['one_dollar']==0){

			$this->settings['one_dollar']=1;

		}

	}



    function mup_wordpresspayplugin_get_pages($title = false, $indent = true) {

        $wp_pages = get_pages('sort_column=menu_order');

        $page_list = array();

        if ($title) $page_list[] = $title;

        foreach ($wp_pages as $page) {

            $prefix = '';

            // show indented child pages?

            if ($indent) {

                $has_parent = $page->post_parent;

                while($has_parent) {

                    $prefix .=  ' - ';

                    $next_page = get_post($has_parent);

                    $has_parent = $next_page->post_parent;

                }

            }

            // add to page list array array

            $page_list[$page->ID] = $prefix . $page->post_title;

        }

        return $page_list;

    }

    

}



?>