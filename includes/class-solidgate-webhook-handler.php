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
        if (!$this->verify_signature($request_body, $request_headers)) {
            error_log('Solidgate webhook: Invalid signature');
            status_header(401);
            wp_send_json_error(array('message' => 'Invalid signature'));
            exit;
        }

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
        $calculated_signature = $this->calculate_signature($request_body);

        return hash_equals($received_signature, $calculated_signature);
    }

    /**
     * Calculate signature for verification
     */
    private function calculate_signature($request_body) {
        return base64_encode(
            hash_hmac(
                'sha512',
                $this->webhook_public_key . $request_body . $this->webhook_public_key,
                $this->webhook_private_key
            )
        );
    }

    /**
     * Process webhook data
     */
    private function process_webhook($request_body) {
        $notification = json_decode($request_body, true);
        
        if (!$notification || !isset($notification['transaction']) || !isset($notification['transactions'])) {
            error_log('Solidgate webhook: Invalid notification format');
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
        if ($order_data['payment_type'] == "1-click") {
            if (($order_data['status'] == "auth_failed" || $transaction['status'] == 'fail') && $order_url == get_site_url()) {
                if ($subscription_ID) {
                    $subscription = wcs_get_subscription( $subscription_ID );
                    $subscription->update_status( 'on-hold' );
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
        elseif ($order_data['payment_type'] == 'rebill' && ($status == 'fail' || $order_data['status'] == 'auth_failed')) {
            if ($order_url == get_site_url()) {
                $this->handle_renewal_payment_failed($notification, $order_data);
                status_header(200);
                wp_send_json_success(array('message' => 'Recurring payment failed, subscription set to pending and queued for retry'));
                exit;
            }
        }
        // Handle successful payment
        elseif (($status == 'success' && $operation == 'auth') || $operation == 'google-pay' || $operation == 'apple-pay') {
            if ($order_url == get_site_url() && $quiz_id) {
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
     * Mark the renewal order as "Pending payment" and push the subscription
     * into WooCommerce Subscriptions' failed-payment retry flow.
     *
     * ASSUMPTIONS — please confirm these against how your site actually
     * stores/manages subscriptions before relying on this in production:
     *   - order_metadata.iqbooster_order_id is a WooCommerce order ID
     *   - order_metadata.subscription_ID is a WooCommerce Subscriptions ID
     *   - WooCommerce Subscriptions is active, and "Retry Failed Payments"
     *     is enabled under WooCommerce > Settings > Subscriptions, since
     *     that's what actually schedules the retry attempt.
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
            : 'Unknown error';

        // 1. Put the renewal order itself into "Pending payment"
        if ($renewal_order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($renewal_order_id);
            if ($order) {
                $order->update_status(
                    'pending',
                    sprintf('Solidgate: recurring auth failed (%s). Awaiting retry.', $error_message)
                );
            } else {
                //error_log("Solidgate webhook: renewal order {$renewal_order_id} not found");
            }
        } else {
            //error_log('Solidgate webhook: no iqbooster_order_id in order_metadata for failed rebill');
        }

        // 2. Tell WooCommerce Subscriptions the payment failed so it queues a retry
        if ($subscription_id && function_exists('wcs_get_subscription')) {
            $subscription = wcs_get_subscription($subscription_id);
            if ($subscription) {
                // payment_failed() fires 'woocommerce_subscription_payment_failed',
                // which WCS_Retry_Manager listens to when automatic retries are
                // enabled in WooCommerce > Settings > Subscriptions.
                $subscription->payment_failed();
            } else {
                //error_log("Solidgate webhook: subscription {$subscription_id} not found");
            }
        } else {
            //error_log('Solidgate webhook: no subscription_ID in order_metadata for failed rebill');
        }
    }
}

/**
 * Register this handler as a WordPress REST API route so it can be hit
 * and tested directly, independent of WooCommerce's wc-api dispatcher.
 *
 * Endpoint: POST https://your-site.com/wp-json/solidgate/v1/webhook
 *
 * permission_callback is left open ('__return_true') because Solidgate
 * calls this from outside WordPress with no cookie/nonce auth — the real
 * authentication is the HMAC signature check already inside
 * handle_webhook() -> verify_signature(). Do not tighten this with a
 * standard REST auth check or Solidgate's real requests will be rejected
 * before they even reach the signature check.
 *
 * ASSUMPTION: gateway settings are stored the standard WooCommerce way, in
 * the option 'woocommerce_solidgate_settings' as an array with the keys
 * below. If your gateway class uses a different option name or different
 * setting keys (check the gateway's __construct / init_settings), update
 * the get_option() call and the array keys to match.
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
 
    // ASSUMPTION: test-mode keys are stored under a "_test" suffix on the
    // same setting name (e.g. 'solidgate_webhook_public_key_test'). If your
    // gateway settings screen names the test keys differently, update the
    // two array keys below to match.
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
 
    // handle_webhook() sends its own JSON response and exit()s, same as it
    // did under wc-api, so this works without needing to return a
    // WP_REST_Response here.
    $handler->handle_webhook();
}