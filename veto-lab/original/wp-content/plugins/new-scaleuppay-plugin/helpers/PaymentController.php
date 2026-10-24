<?php



class PaymentController {

    CONST HTTP_METHOD_GET = 'GET';

    CONST HTTP_METHOD_POST = 'POST';

    CONST HTTP_METHOD_PUT = 'PUT';

    CONST HTTP_METHOD_DELETE = 'DELETE';

    CONST PG_CARDPAYMENTZ = 'cardpaymentz';



    const CURLOPT_TIMEOUT = 1800; // 30*60 seconds

    private $headers = [];



    public function createCharge($request){
        $request['suap_action'] = 'create-charge';
        
        //default for Stripe
        $url = PAYMENTS_API_BASE_URL."/api/create-charge";
        //change if pg is cardpaymentz
        if(cleffo_get_payment_gateway() == SELF::PG_CARDPAYMENTZ){
            $url = PAYMENTS_API_BASE_URL."/api/v1/payments";
            $this->headers[] = 'x-api-key: '.cleffo_get_api_key();
        }
        
        // Test if the URL is reachable
        $parsedUrl = parse_url($url);
        
        try{
            $postParams = $this->processRequestParams($request);
        } catch (Exception $e){
            return [
                "status" => 1,
                "message" => "",
                "error" => $e->getMessage(),
                "data" => [],
            ]; 
        }

        cleffo_log('Final request parameters: ' . json_encode(cleffo_mask_sensitive_payment_fields($postParams)).", url: ".$url);

        $resp = SELF::makeCurlCall($url,$postParams,$this->headers,SELF::HTTP_METHOD_POST);
        
        $decodedResp = json_decode($resp['resp'],true);
        cleffo_log('Decoded Response: ' . json_encode($decodedResp));
        
        return $decodedResp;
    }

    private function processRequestParams($request){
        if(cleffo_get_payment_gateway() == SELF::PG_CARDPAYMENTZ){
            try{
                return $this->mapWooToCleffo($request);
            } catch (Exception $e){
                throw new Exception("Error Processing Request:".$e->getMessage(), 1);
            }
        } else {
            return $request;
        }
    }
    
    /**
     * Maps a WooCommerce SUAP checkout payload (associative array)
     * to the Cleffo charge API format.
     *
     * @param array $wc      Decoded WooCommerce checkout array.
     * @param array $config  Values not present in the WC payload (URLs, order id, meta).
     * @return array         Cleffo-ready payload.
     * @throws InvalidArgumentException on a malformed Stripe token.
     */
    private function mapWooToCleffo($wc){
        try{
            // --- Stripe token: "ccno|month|year|cvv" ---
            $token = $wc['suap_stripe_token'] ?? '';
            $parts = explode('|', $token);
            if (count($parts) !== 4) {
                throw new InvalidArgumentException(
                    'suap_stripe_token must be "ccno|month|year|cvv", got: ' . $token
                );
            }
            [$ccno, $expMonth, $expYear, $ccvv] = array_map('trim', $parts);

            // 2-digit year to match Cleffo's string format ("2030" -> "30")
            $expYear2 = substr($expYear, -2);

            // --- Phone: normalise to national number, strip +<cc> ---
            $phone = $this->normalisePhone($wc['billing_phone'] ?? '');

            // --- Mode -> test flag ---
            $isTest = ($wc['suap_mode'] ?? '') === 'test';

            $name = trim(
                ($wc['billing_first_name'] ?? '') . ' ' . ($wc['billing_last_name'] ?? '')
            );

            return [
                'customer_name'          => $name,
                'customer_email'         => $wc['billing_email'] ?? null,
                'bill_phone'             => $phone,
                'amount'                 => isset($wc['order_amount']) ? round((float) $wc['order_amount'], 2)  : null,
                'currency'               => $wc['order_currency'] ?? '',
                'merchant_order_id'      => $wc['merchant_order_id'] ?? $this->generateOrderId(),
                'pay_type'               => 'card',
                'is_test'                => $isTest,
                'success_url'            => $wc['success_url'] ?? null,
                'cancel_url'             => $wc['cancel_url']  ?? null,
                'error_url'              => $wc['error_url']   ?? null,
                'notify_url'             => $wc['notify_url']  ?? null,
                'redirect_url'            => $wc['redirect_url'] ?? null,
                'meta_data'              => $wc['meta_data'] ?? 'Migrated from suap checkout',
                'cleffo_client_key'      => $wc['suap_client_key'] ?? ($wc['suap_used_client_key'] ?? null),
                'ccno'                   => $ccno,
                'ccvv'                   => $ccvv,
                'expiry_month'           => (int) $expMonth,
                'expiry_year'            => $expYear2,
                'billing_address_line_1' => $wc['billing_address_1'] ?? null,
                'billing_address_line_2' => $wc['billing_address_2'] ?? null,
                'billing_city'           => $wc['billing_city'] ?? null,
                'billing_state'          => $wc['billing_state'] ?? null,
                'billing_country'        => $wc['billing_country'] ?? null,
                'billing_zip'            => $wc['billing_postcode'] ?? null,
                'source'                 => 'api',
            ];
        } catch (Exception $e){
            throw new Exception("Error Processing Request:".$e->getMessage(), 1);
        }
    }

    /**
     * Strip a leading international dial code, return national digits only.
     * "+917042032000" -> "7042032000". Adjust the map if you take non-IN numbers.
     */
    private function normalisePhone($raw){
        $digits = preg_replace('/[^\d+]/', '', $raw);

        $ccMap = ['91', '1', '44', '971', '61']; // extend as needed
        if (str_starts_with($digits, '+')) {
            $digits = substr($digits, 1);
            foreach ($ccMap as $cc) {
                if (str_starts_with($digits, $cc)) {
                    return substr($digits, strlen($cc));
                }
            }
        }
        return $digits;
    }

    private function generateOrderId(): string
    {
        return 'ORD_' . time() . random_int(10, 99);
    }



    public static function makeCurlCall($url, $postParams, $headers = array(), $method = SELF::HTTP_METHOD_GET) {
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // Add user agent to avoid 403 errors
        curl_setopt($ch, CURLOPT_USERAGENT, 'Cleffo-WooCommerce-Plugin/1.0');
        
        // Enable verbose debugging
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);
        
        if ($method == SELF::HTTP_METHOD_POST ) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postParams);
        }
        
        if($method == SELF::HTTP_METHOD_PUT ) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, SELF::HTTP_METHOD_PUT);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postParams);
        }
        
        if ($headers && count($headers) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            //cleffo_log('cURL - Headers set: ' . json_encode($headers));
        }
        
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::CURLOPT_TIMEOUT); //timeout in seconds
        
        $result = curl_exec($ch);
        $errors = curl_error($ch);
        $respCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $info = curl_getinfo($ch);
        
        // Log verbose output for debugging
        //rewind($verbose);
        //$verboseLog = stream_get_contents($verbose);
        //cleffo_log('cURL Verbose Output: ' . $verboseLog);
        
        //cleffo_log('cURL Response - Code: ' . $respCode . ', Errors: ' . ($errors ? $errors : 'NONE') . ', Length: ' . strlen($result));
        //cleffo_log('cURL Info - Total Time: ' . $info['total_time'] . 's, Connect Time: ' . $info['connect_time'] . 's');
        //cleffo_log('cURL Info - Effective URL: ' . $info['url']);
        
        if ($errors) {
            cleffo_log('cURL Error Details: ' . $errors);
        }
        
        if ($respCode == 403) {
            cleffo_log('WARNING: Received 403 Forbidden - This might indicate:');
            cleffo_log('1. Server blocking requests based on User-Agent');
            cleffo_log('2. Server blocking requests based on IP');
            cleffo_log('3. Server requiring specific headers');
            cleffo_log('4. URL might be incorrect or server not accepting requests');
        }
 
        curl_close($ch);
        //fclose($verbose);
        
        $response = array('resp' => $result, 'error' => $errors, 'respCode' => $respCode);
        
        return $response;
    }

    public function verifyPayment($txn_ref){
        $url = PAYMENTS_API_BASE_URL."/api/v1/payments/".rawurlencode($txn_ref);
        cleffo_log('Verify payment url: '.$url);
        //x-api-key in headers
        $headers = array('x-api-key: '.cleffo_get_api_key());
        $resp = SELF::makeCurlCall($url,[],$headers,SELF::HTTP_METHOD_GET);

        $decodedResp = json_decode($resp['resp'],true);
        cleffo_log('Verify payment decoded response: ' . json_encode($decodedResp));

        return $decodedResp;
    }

}



?>