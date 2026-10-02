<?php
if (!defined('ABSPATH')) {
    exit;
}

class Solidgate_Subscription_Handler {
    private $api;

    public function __construct($api) {
        $this->api = $api;
        add_action('woocommerce_scheduled_subscription_payment_solidgate', array($this, 'process_subscription_payment'), 10, 2);
        add_action('woocommerce_subscription_cancelled_solidgate', array($this, 'cancel_subscription'));
    }

    /**
     * Process subscription payment
     */
    public function process_subscription_payment($amount, $order) {
        try {
            $user_id = $order->get_user_id();
            $use_vip_keys = false;
            if ($user_id) {
                $cross_sell_user = get_user_meta($user_id, 'cross_sell_user', true);
                if ($cross_sell_user) {
                    $use_vip_keys = true;
                }
            }
            // Define your alternate keys here (replace with your actual keys or load from settings)
            $public_key = 'api_pk_84';
            $private_key = 'api_sk_42';
            $default_public_key = $this->api->public_key ?? '';
            $default_private_key = $this->api->private_key ?? '';
            $test_mode = $this->api->test_mode ?? false;

            if( $cross_sell_user == 6 || $cross_sell_user == 7 || $cross_sell_user == 9 || $cross_sell_user == 10 ){
                $public_key = 'api_pk_c69';
                $private_key = 'api_sk_193';
                $user_payment_mode = get_user_meta($user_id, 'user_payment_mode', true);
                if($user_payment_mode == 'test'){
                    $public_key = 'api_pk_713';
                    $private_key = 'api_sk_a5';
                    $test_mode = true;
                }
            }

            if($cross_sell_user == 4){
                $public_key = 'api_pk_052';
                $private_key = 'api_sk_307';
                $user_payment_mode = get_user_meta($user_id, 'user_payment_mode', true);
                if($user_payment_mode == 'test'){
                    $public_key = 'api_pk_713599b8_1b47_4674_9d4a_c0a217046187';
                    $private_key = 'api_sk_a5b2efd2_f8f0_419c_8c1d_a0dd26b70e0c';
                    $test_mode = true;
                }
            }        
            if($cross_sell_user == 5){
                $public_key = 'api_pk_c69';
                $private_key = 'api_sk_193';
                $user_payment_mode = get_user_meta($user_id, 'user_payment_mode', true);
                if($user_payment_mode == 'test'){
                    $public_key = 'api_pk_713599b8_1b47_4674_9d4a_c0a217046187';
                    $private_key = 'api_sk_a5b2efd2_f8f0_419c_8c1d_a0dd26b70e0c';
                    $test_mode = true;
                }
            }

            if($cross_sell_user == 2){
                $public_key = 'api_pk_e63';
                $private_key = 'api_sk_51';
                $user_payment_mode = get_user_meta($user_id, 'user_payment_mode', true);
                if($user_payment_mode == 'test'){
                    $public_key = 'api_pk_716e2a6b_bfd2_4a00_bdcd_6d7620408235';
                    $private_key = 'api_sk_e9e07c11_c2b2_4aa7_991e_441cf67d7e8c';
                    $test_mode = true;
                }
            }

            if($cross_sell_user == 1){
                $public_key = 'api_pk_c96';
                $private_key = 'api_sk_013';
                $user_payment_mode = get_user_meta($user_id, 'user_payment_mode', true);
                if($user_payment_mode == 'test'){
                    $public_key = 'api_pk_713599b8_1b47_4674_9d4a_c0a217046187';
                    $private_key = 'api_sk_a5b2efd2_f8f0_419c_8c1d_a0dd26b70e0c';
                    $test_mode = true;
                }
            } 


            // Select keys
            if ($use_vip_keys) {
                $api = new Solidgate_API($public_key, $private_key, $test_mode);
            } else {
                $api = new Solidgate_API($default_public_key, $default_private_key, $test_mode);
            }
            // Log subscription renewal attempt
            wc_get_logger()->info(
                sprintf(
                    'Processing subscription renewal for order #%s. Amount: %s %s',
                    $order->get_id(),
                    $amount,
                    $order->get_currency()
                ),
                array('source' => 'solidgate-subscription')
            );

            // Get the stored payment token
            $payment_token = get_user_meta($user_id, '_solidgate_payment_token', true);
            $user_visited_site = get_user_meta($user_id, 'site_url', true);
            
            if (empty($payment_token)) {
                $error_message = 'No payment token found for subscription.';
                wc_get_logger()->error(
                    sprintf(
                        'Subscription renewal failed for order #%s: %s',
                        $order->get_id(),
                        $error_message
                    ),
                    array('source' => 'solidgate-subscription')
                );
                throw new Exception($error_message);
            }

            // Handle zero-decimal currencies
            $currency = $order->get_currency();
            $zero_decimal_currencies = array('BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF');
            
            // Ensure amount is numeric
            $amount = (float) $amount;

            // Convert based on currency type
            if (in_array($currency, $zero_decimal_currencies)) {
                $amount_in_cents = (int) round($amount); // Integer, no decimals
            } else {
                $amount_in_cents = (int) round($amount * 100); // Convert to cents
            }

            // Log amount conversion
            wc_get_logger()->info(
                sprintf(
                    'Amount conversion for order #%s: Original amount: %s, Converted amount: %s, Currency: %s, Is zero-decimal: %s',
                    $order->get_id(),
                    $amount,
                    $amount_in_cents,
                    $currency,
                    in_array($currency, $zero_decimal_currencies) ? 'yes' : 'no'
                ),
                array('source' => 'solidgate-subscription')
            );

            $data = random_bytes(16);
            // Set the version to 0100
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            // Set bits 6-7 to 10
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
            $order_uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
            // Prepare order data for recurring payment
            $subscriptionId = wcs_get_subscriptions_for_renewal_order( $order, array( 'order_type' => 'renewal' ) );
            if ( ! empty( $subscriptionId ) ) {
                $subscriptionId = array_shift( $subscriptionId );
                $solid_subId = $subscriptionId->get_id();
            }
            $ip_address = get_user_meta($user_id, 'ip_address', true);
            if(empty($ip_address)){
                $ip_address = $order->get_customer_ip_address();
            }
            $order_data = array(
                'order_id' => $order_uuid,
                'amount' => $amount_in_cents,
                'currency' => $currency,
                'order_description' => 'Subscription renewal for order #' . $order->get_id(),
                'order_items' => 'Subscription renewal',
                'order_number' => 1,
                'type' => 'auth',
                'settle_interval' => 0,
                'payment_type' => 'rebill',
                'recurring_token' => $payment_token,
                'retry_attempt' => 3,
                'force3ds' => false,
                'customer_email' => $order->get_billing_email(),
                'ip_address' => $ip_address,
                'platform' => 'WEB',
                'order_metadata' => array(
                    'order_url' => get_site_url(),
                    'user_visited_site' => $user_visited_site,
                    'subscription_ID' => $solid_subId,
                    'iqbooster_order_id' => $order->get_id(),
                )
            );

            // Log payment attempt
            // wc_get_logger()->info(
            //     sprintf(
            //         'Attempting recurring payment for order #%s with token %s',
            //         $order->get_id(),
            //         substr($payment_token, 0, 8) . '...'
            //     ),
            //     array('source' => 'solidgate-subscription')
            // );

            // Process the payment
            $response = $api->process_recurring_payment($order_data);

            // Log the full response for debugging
            wc_get_logger()->info(
                sprintf(
                    'Solidgate response for order #%s: %s',
                    $order->get_id(),
                    print_r($response, true)
                ),
                array('source' => 'solidgate-subscription')
            );

            // Check for transaction status - handle both 'success' and 'created' statuses
            if (isset($response['transaction']['status']) && 
                ($response['transaction']['status'] == 'success' || $response['transaction']['status'] == 'created' || $response['transaction']['status'] == 'processing')) {
                $payment_status = $response['order']['status'];
                $solidgate_orderId = $response['order']['order_id'];
                // Store the transaction ID
                $transaction_id = $response['transaction']['id'];
                if($payment_status == 'processing'){
                // Complete the payment
                    $order->payment_complete($solidgate_orderId);
                }
                
                // Add order note with detailed information
                $order->add_order_note(sprintf(
                    'Subscription renewal payment processed via Solidgate. Transaction ID: %s, Status: %s, Operation: %s',
                    $solidgate_orderId,
                    $response['transaction']['status'],
                    $response['transaction']['operation'] ?? 'unknown'
                ));
                
                // Log successful payment
                wc_get_logger()->info(
                    sprintf(
                        'Subscription renewal processed for order #%s. Transaction ID: %s, Status: %s',
                        $order->get_id(),
                        $solidgate_orderId,
                        $response['transaction']['status']
                    ),
                    array('source' => 'solidgate-subscription')
                );

                // If this is a subscription, ensure it's active
                if (wcs_order_contains_subscription($order)) {
                    $subscriptions = wcs_get_subscriptions_for_order($order);
                    foreach ($subscriptions as $subscription) {
                        if ($subscription->get_status() !== 'active') {
                            $subscription->update_status('active', 'Payment processed successfully');
                            wc_get_logger()->info(
                                sprintf(
                                    'Updated subscription #%s status to active after successful payment',
                                    $subscription->get_id()
                                ),
                                array('source' => 'solidgate-subscription')
                            );
                        }
                    }
                }
            } else {
                $error_message = 'Payment failed: ' . ($response['error']['message'] ?? 'Unknown error');
                wc_get_logger()->error(
                    sprintf(
                        'Subscription renewal failed for order #%s: %s',
                        $order->get_id(),
                        $error_message
                    ),
                    array('source' => 'solidgate-subscription')
                );
                throw new Exception($error_message);
            }
        } catch (Exception $e) {
            $order->update_status('failed', 'Subscription renewal payment failed: ' . $e->getMessage());
            
            // If this is a subscription, update its status
            if (wcs_order_contains_subscription($order)) {
                $subscriptions = wcs_get_subscriptions_for_order($order);
                foreach ($subscriptions as $subscription) {
                    $subscription->update_status('on-hold', 'Payment failed: ' . $e->getMessage());
                    
                    // Log subscription status update
                    wc_get_logger()->info(
                        sprintf(
                            'Updated subscription #%s status to on-hold due to payment failure: %s',
                            $subscription->get_id(),
                            $e->getMessage()
                        ),
                        array('source' => 'solidgate-subscription')
                    );
                }
            }
        }
    }

    /**
     * Cancel subscription
     */
    public function cancel_subscription($subscription) {
        // Get the stored payment token
        $payment_token = get_post_meta($subscription->get_id(), '_solidgate_payment_token', true);
        
        if ($payment_token) {
            // Clear the stored payment token
            delete_post_meta($subscription->get_id(), '_solidgate_payment_token');
            delete_post_meta($subscription->get_id(), '_solidgate_last_four');
            delete_post_meta($subscription->get_id(), '_solidgate_card_type');
        }
    }
} 