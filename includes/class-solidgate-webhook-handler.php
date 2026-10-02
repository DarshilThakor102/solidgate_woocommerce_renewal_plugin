<?php

if (!defined('ABSPATH')) {
    exit;
}

class Solidgate_Webhook_Handler {

    private $merchant_id;
    private $merchant_secret;
    private $testmode;
    private $webhook_public_key;
    private $webhook_private_key;

    public function __construct($merchant_id, $merchant_secret, $testmode, $webhook_public_key, $webhook_private_key) {
        $this->merchant_id = $merchant_id;
        $this->merchant_secret = $merchant_secret;
        $this->testmode = $testmode;
        $this->webhook_public_key = $webhook_public_key;
        $this->webhook_private_key = $webhook_private_key;
    }

    /**
     * Handle incoming webhook requests
     */
    public function handle_webhook() {
        // Get request body
        $request_body = file_get_contents('php://input');
        
        // Get headers
        if (!function_exists('getallheaders')) {
            $headers = [];
            foreach ($_SERVER as $name => $value) {
                if ('HTTP_' === substr($name, 0, 5)) {
                    $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                }
            }
        } else {
            $headers = getallheaders();
        }
        $request_headers = array_change_key_case($headers, CASE_UPPER);

        // Verify signature
        // if (!$this->verify_signature($request_body, $request_headers)) {
        //     //error_log('Solidgate webhook: Invalid signature');
        //     status_header(401);
        //     wp_send_json_error(array('message' => 'Invalid signature'));
        //     exit;
        // }

        // Process webhook
        $this->process_webhook($request_body);
    }

    /**
     * Verify webhook signature
     */
    private function verify_signature($request_body, $headers) {
        if (!isset($headers['SIGNATURE'])) {
            return false;
        }

        $received_signature = $headers['SIGNATURE'];
        
        $public_key = $this->webhook_public_key;
        $private_key = $this->webhook_private_key;
        $notification = json_decode($request_body, true);
        $order_id = isset($notification['order_metadata']['iqbooster_order_id'])
            ? absint($notification['order_metadata']['iqbooster_order_id'])
            : null;

        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $user_id = $order->get_user_id();
                if ($user_id) {
                    $cross_sell_user = get_user_meta($user_id, 'cross_sell_user', true);
                    if ($cross_sell_user == 4) {
                        $public_key = '';
                        $private_key = '';
                    }
                }
            }
        }

        $calculated_signature = $this->calculate_signature($request_body, $public_key, $private_key);
        return hash_equals($received_signature, $calculated_signature);
    }

    /**
     * Calculate signature for verification
     */
    private function calculate_signature($request_body, $public_key, $private_key) {
        return base64_encode(
            hash_hmac(
                'sha512',
                $public_key . $request_body . $public_key,
                $private_key
            )
        );
    }

    /**
     * Process webhook data
     */
    private function process_webhook($request_body) {
        $notification = json_decode($request_body, true);
        
        if (!$notification || !isset($notification['transaction']) || !isset($notification['transactions'])) {
            //error_log('Solidgate webhook: Invalid notification format');
            status_header(400);
            wp_send_json_error(array('message' => 'Invalid notification format'));
            exit;
        }

        $transaction = $notification['transaction'];
        $transactions = array_slice($notification['transactions'], 0, 1);
        $first_transaction = array_shift($transactions);
        $operation = $first_transaction['operation'];
        $order_data = $notification['order'];
        $status = $first_transaction['status'];
        $order_url = isset($notification['order_metadata']['order_url']) ? $notification['order_metadata']['order_url'] : '';
        $subscription_ID = isset($notification['order_metadata']['subscription_ID']) ? $notification['order_metadata']['subscription_ID'] : null;
        $order_id = isset($notification['order_metadata']['iqbooster_order_id']) ? $notification['order_metadata']['iqbooster_order_id'] : null;

        // Handle 1-click payment
        $log_file = WP_CONTENT_DIR . '/my-log.txt';
        $log_data = "Log started: " . date('Y-m-d H:i:s') . PHP_EOL;
        $log_data .= "Data first_transaction: " . print_r($transaction, true) . PHP_EOL;
        $log_data .= "Data status: " . print_r($status, true) . PHP_EOL;
        $log_data .= "------------------------" . PHP_EOL;
        file_put_contents($log_file, $log_data, FILE_APPEND | LOCK_EX);

        // Handle 1-click payment
        if (isset($order_data['payment_type']) && $order_data['payment_type'] === '1-click') {    
            if (($order_data['status'] == "auth_failed" || $transaction['status'] == 'fail') && $order_url == get_site_url()) {
                if ($subscription_ID) {
                    $subscription = wcs_get_subscription( $subscription_ID );
                    if ($subscription) {
                        // Use payment_failed() instead of update_status() to trigger retry system
                        $subscription->payment_failed('on-hold');
                    }
                    
                    $order = wc_get_order( $order_id );
                    if ( $order ) {
                        $order->update_status(
                            'pending',
                            'Payment reversed after declined webhook.'
                        );
                    }
                }
                status_header(200);
                wp_send_json_success(array('message' => 'Recurring Payment failed'));
                exit;
            }
        }

        // Handle failed recurring/subscription renewal payment (payment_type = "rebill")
        elseif ( isset($order_data['payment_type']) && $order_data['payment_type'] === 'rebill' && ( $status === 'fail' || ($order_data['status'] ?? '') === 'auth_failed' ) ) {
            if ($order_url == get_site_url()) {
                $this->handle_renewal_payment_failed($notification, $order_data);
                status_header(200);
                wp_send_json_success(array('message' => 'Recurring payment failed, subscription set to pending and queued for retry'));
                exit;
            }
        }

        // Handle successful payment
        elseif (($status == 'success' && $operation == 'auth') || $operation == 'google-pay' || $operation == 'apple-pay') {
            if ($order_url == get_site_url()) {
                // Store card details
                if (isset($first_transaction['card_token']['token'])) {
                    // update_post_meta($quiz_id, 'solid_customer_payment_token', $first_transaction['card_token']['token']);
                }
                
                if (isset($first_transaction['card']['number'])) {
                    $card_number = substr($first_transaction['card']['number'], -4);
                    // update_post_meta($quiz_id, 'last_four_digit_cc', $card_number);
                }
                if (isset($first_transaction['card']['brand'])) {
                    // update_post_meta($quiz_id, 'card_type', $first_transaction['card']['brand']);
                }
                status_header(200);
                wp_send_json_success(array('message' => 'Payment processed successfully'));
                exit;
            }
        }

        status_header(204);
        wp_send_json_success(array('message' => 'Data Not Updated for site'));
    }

    /**
     * Handle failed renewal payment and activate WooCommerce Subscriptions retry system.
     * 
     * KEY INSIGHTS:
     * 1. Webhooks are NOT "scheduled payment attempts" so normal retry rules don't auto-apply
     * 2. We need to manually create renewal order if it doesn't exist
     * 3. Payment method must support 'subscription_date_changes' for retries to work
     * 4. Retry system must be enabled in WooCommerce settings
     */
    private function handle_renewal_payment_failed($notification, $order_data) {
        $renewal_order_id = isset($notification['order_metadata']['iqbooster_order_id'])
            ? absint($notification['order_metadata']['iqbooster_order_id'])
            : null;

        $subscription_id = isset($notification['order_metadata']['subscription_ID'])
            ? absint($notification['order_metadata']['subscription_ID'])
            : null;

        $error_message = isset($notification['error']['recommended_message_for_user'])
            ? $notification['error']['recommended_message_for_user']
            : 'Payment failed';

        if (!$subscription_id || !function_exists('wcs_get_subscription')) {
            //error_log('Solidgate: Invalid subscription data in webhook');
            return;
        }

        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription) {
            //error_log('Solidgate: Subscription not found: ' . $subscription_id);
            return;
        }

        // Check if retry system is enabled
        $retry_enabled = class_exists('WCS_Retry_Manager') && WCS_Retry_Manager::is_retry_enabled();
        //error_log('Solidgate: Retry system enabled: ' . ($retry_enabled ? 'yes' : 'no'));

        // Get renewal order - NEVER create new orders to prevent duplicates
        $renewal_order = null;
        
        if ($renewal_order_id) {
            $renewal_order = wc_get_order($renewal_order_id);
            if ($renewal_order) {
                //error_log('Solidgate: Using provided renewal order ' . $renewal_order_id);
            } else {
                //error_log('Solidgate: Provided renewal order ' . $renewal_order_id . ' not found');
            }
        }

        // If no renewal order provided, find the most recent one for this subscription
        if (!$renewal_order) {
            $last_order = $subscription->get_last_order('all', 'any');
            
            if ($last_order && wcs_order_contains_renewal($last_order)) {
                $order_date = $last_order->get_date_created();
                $hours_old = (time() - $order_date->getTimestamp()) / 3600;
                
                // Accept renewal orders up to 24 hours old (generous window)
                if ($hours_old <= 24) {
                    $renewal_order = $last_order;
                    //error_log('Solidgate: Found existing renewal order ' . $renewal_order->get_id() . ' (' . round($hours_old, 2) . ' hours old)');
                } else {
                    //error_log('Solidgate: Last renewal order ' . $last_order->get_id() . ' is too old (' . round($hours_old, 2) . ' hours)');
                }
            }
        }

        // If we still don't have a renewal order, we can't process this webhook safely
        // This prevents duplicate order creation
        if (!$renewal_order) {
            //error_log('Solidgate: No existing renewal order found - cannot process webhook without creating duplicates');
            
            // Still trigger subscription payment failure for status update
            $subscription->payment_failed();
            $subscription->add_order_note('Solidgate webhook: Payment failed but no renewal order available to avoid duplicates');
            return;
        }

        // STEP 1: Set renewal order to failed status with retry metadata
        $renewal_order->update_meta_data('_subscription_renewal_payment_failed', 'yes');
        $renewal_order->update_status('failed', sprintf('Solidgate: %s', $error_message));
        $renewal_order->save();

        // STEP 2: Trigger payment failure on subscription
        // This fires woocommerce_subscription_renewal_payment_failed hook
        $subscription->payment_failed();

        // STEP 3: If retry system is enabled, manually apply retry rules
        // Since webhooks are not "scheduled" attempts, we need to force application
        if ($retry_enabled) {
            
            // Check prerequisites for retry system
            $is_manual = $subscription->is_manual();
            $supports_changes = $subscription->payment_method_supports('subscription_date_changes');
            
            //error_log('Solidgate: Subscription manual: ' . ($is_manual ? 'yes' : 'no'));
            //error_log('Solidgate: Payment method supports changes: ' . ($supports_changes ? 'yes' : 'no'));
            
            if (!$is_manual && $supports_changes) {
                
                // Apply retry rule manually for webhook-based failures
                $retry_count = 0;
                if (method_exists('WCS_Retry_Manager', 'store')) {
                    $retry_count = WCS_Retry_Manager::store()->get_retry_count_for_order($renewal_order->get_id());
                }
                
                //error_log('Solidgate: Current retry count: ' . $retry_count);
                
                // Check if a retry rule exists for this attempt
                if (method_exists('WCS_Retry_Manager', 'rules') && WCS_Retry_Manager::rules()->has_rule($retry_count, $renewal_order->get_id())) {
                    
                    $retry_rule = WCS_Retry_Manager::rules()->get_rule($retry_count, $renewal_order->get_id());
                    
                    if ($retry_rule) {
                        //error_log('Solidgate: Applying retry rule for attempt ' . $retry_count);
                        
                        // Create retry record
                        $retry = new WCS_Retry(array(
                            'status'   => 'pending',
                            'order_id' => $renewal_order->get_id(),
                            'date_gmt' => gmdate('Y-m-d H:i:s', time() + $retry_rule->get_retry_interval()),
                            'rule_raw' => $retry_rule->get_raw_data(),
                        ));
                        
                        $retry_id = WCS_Retry_Manager::store()->save($retry);
                        
                        // Apply statuses from retry rule
                        $order_status = $retry_rule->get_status_to_apply('order');
                        if ($order_status && $order_status !== '' && !$renewal_order->has_status($order_status)) {
                            $renewal_order->update_status($order_status, 'Retry rule applied by webhook handler');
                            //error_log('Solidgate: Set renewal order status to: ' . $order_status);
                        }
                        
                        $subscription_status = $retry_rule->get_status_to_apply('subscription');
                        if ($subscription_status && $subscription_status !== '' && !$subscription->has_status($subscription_status)) {
                            $subscription->update_status($subscription_status, 'Retry rule applied by webhook handler');
                            //error_log('Solidgate: Set subscription status to: ' . $subscription_status);
                        }
                        
                        // Schedule next retry if interval > 0
                        if ($retry_rule->get_retry_interval() > 0) {
                            $retry_date = gmdate('Y-m-d H:i:s', time() + $retry_rule->get_retry_interval());
                            $subscription->update_dates(array('payment_retry' => $retry_date));
                            //error_log('Solidgate: Scheduled retry for: ' . $retry_date);
                        }
                        
                        $subscription->add_order_note(sprintf('Solidgate webhook: Payment failed. Retry scheduled in %d seconds.', $retry_rule->get_retry_interval()));
                        
                    } else {
                        //error_log('Solidgate: No retry rule found for attempt ' . $retry_count);
                    }
                    
                } else {
                    //error_log('Solidgate: No more retry rules available (max attempts reached)');
                    $subscription->add_order_note('Solidgate webhook: Payment failed. No more retries available.');
                }
                
            } else {
                $reason = $is_manual ? 'subscription is manual' : 'payment method does not support subscription changes';
                //error_log('Solidgate: Retry system disabled - ' . $reason);
                $subscription->add_order_note('Solidgate webhook: Payment failed. Retries not available (' . $reason . ').');
            }
            
        } else {
            //error_log('Solidgate: Retry system is disabled in WooCommerce settings');
            $subscription->add_order_note('Solidgate webhook: Payment failed. Retry system is disabled.');
        }
    }
}

/**
 * Register this handler as a WordPress REST API route so it can be hit
 * and tested directly, independent of WooCommerce's wc-api dispatcher.
 *
 * Endpoint: POST https://your-site.com/wp-json/solidgate/v1/webhook
 */
add_action('rest_api_init', 'solidgate_register_webhook_rest_route');

function solidgate_register_webhook_rest_route() {
    register_rest_route('solidgate/v1', '/webhook', array(
        'methods'             => WP_REST_Server::CREATABLE, // POST
        'callback'            => 'solidgate_handle_webhook_rest',
        'permission_callback' => '__return_true',
    ));
}

function solidgate_handle_webhook_rest(WP_REST_Request $request) {
    $settings = get_option('woocommerce_solidgate_settings', array());
 
    $testmode = isset($settings['testmode']) && $settings['testmode'] === 'yes';
 
    $webhook_public_key = $testmode
        ? (isset($settings['test_solidgate_webhook_public_key']) ? $settings['test_solidgate_webhook_public_key'] : '')
        : (isset($settings['solidgate_webhook_public_key']) ? $settings['solidgate_webhook_public_key'] : '');
 
    $webhook_private_key = $testmode
        ? (isset($settings['test_solidgate_webhook_private_key']) ? $settings['test_solidgate_webhook_private_key'] : '')
        : (isset($settings['solidgate_webhook_private_key']) ? $settings['solidgate_webhook_private_key'] : '');
 
    $handler = new Solidgate_Webhook_Handler(
        isset($settings['merchant_id']) ? $settings['merchant_id'] : '',
        isset($settings['merchant_secret']) ? $settings['merchant_secret'] : '',
        $testmode,
        $webhook_public_key,
        $webhook_private_key
    );
 
    $handler->handle_webhook();
}