<?php
if (!defined('ABSPATH')) {
    exit;
}

use SolidGate\API\Api;

class WC_Solidgate_Gateway extends WC_Payment_Gateway {
    private $api;
    private $subscription_handler;
    private $webhook_handler;
    private $sdk_api;

    public function __construct() {
        $this->id = 'solidgate';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'Solidgate';
        $this->method_description = 'Accept payments via Solidgate payment gateway';

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->merchant_id = $this->testmode ? $this->get_option('test_merchant_id') : $this->get_option('merchant_id');
        $this->merchant_secret = $this->testmode ? $this->get_option('test_merchant_secret') : $this->get_option('merchant_secret');
        $this->webhook_public_key = $this->testmode ? $this->get_option('test_solidgate_webhook_public_key') : $this->get_option('solidgate_webhook_public_key');
        $this->webhook_private_key = $this->testmode ? $this->get_option('test_solidgate_webhook_private_key') : $this->get_option('solidgate_webhook_private_key');

        // Initialize API handler
        $this->api = new Solidgate_API($this->merchant_id, $this->merchant_secret, $this->testmode);
        
        // Initialize subscription handler
        $this->subscription_handler = new Solidgate_Subscription_Handler($this->api);

        // Initialize webhook handler
        $this->webhook_handler = new Solidgate_Webhook_Handler($this->merchant_id, $this->merchant_secret, $this->testmode, $this->webhook_public_key, $this->webhook_private_key);

        // Initialize SDK
        $sdk_path = SOLIDGATE_WC_PLUGIN_DIR . '/soildgate-php/vendor/autoload.php';
        if (file_exists($sdk_path)) {
            require_once $sdk_path;
            $this->sdk_api = new Api($this->merchant_id, $this->merchant_secret);
        }

        // Register subscription support
        $this->supports = array(
            'products',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'multiple_subscriptions',
            'refunds',
            'tokenization'
        );

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_solidgate_webhook', array($this->webhook_handler, 'handle_webhook'));
        add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this->subscription_handler, 'process_subscription_payment'), 10, 2);
        add_action('woocommerce_subscription_cancelled_' . $this->id, array($this->subscription_handler, 'cancel_subscription'));
        
        // Enqueue scripts on checkout page
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add payment form to checkout page
        add_action('woocommerce_review_order_before_payment', array($this, 'payment_form_container'));

        // Add AJAX handlers
        add_action('wp_ajax_get_solidgate_form', array($this, 'get_solidgate_form'));
        add_action('wp_ajax_nopriv_get_solidgate_form', array($this, 'get_solidgate_form'));
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable Solidgate Payment',
                'default' => 'no'
            ),
            'title' => array(
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'This controls the title which the user sees during checkout.',
                'default'     => 'Credit Card (Solidgate)',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => 'Description',
                'type'        => 'textarea',
                'description' => 'This controls the description which the user sees during checkout.',
                'default'     => 'Pay with your credit card via Solidgate.',
            ),
            'testmode' => array(
                'title'       => 'Test mode',
                'type'        => 'checkbox',
                'label'       => 'Enable Test Mode',
                'default'     => 'yes',
                'description' => 'Place the payment gateway in test mode.',
            ),
            'test_merchant_id' => array(
                'title'       => 'Test Merchant ID',
                'type'        => 'text',
                'description' => 'Enter your test merchant ID',
                'default'     => '',
            ),
            'test_merchant_secret' => array(
                'title'       => 'Test Merchant Secret',
                'type'        => 'password',
                'description' => 'Enter your test merchant secret',
                'default'     => '',
            ),
            'test_solidgate_webhook_public_key' => array(
                'title'       => 'Test Webhook Public Key',
                'type'        => 'text',
                'description' => 'Enter your test webhook public key',
                'default'     => 'wh_pk_46931c3a_d82a_4d03_8549_6799277e7a19',
            ),
            'test_solidgate_webhook_private_key' => array(
                'title'       => 'Test Webhook Private Key',
                'type'        => 'password',
                'description' => 'Enter your test webhook private key',
                'default'     => 'wh_sk_b4542336_7c7c_4ba2_b57b_6f86d5858e12',
            ),
            'merchant_id' => array(
                'title'       => 'Live Merchant ID',
                'type'        => 'text',
                'description' => 'Enter your live merchant ID',
                'default'     => '',
            ),
            'merchant_secret' => array(
                'title'       => 'Live Merchant Secret',
                'type'        => 'password',
                'description' => 'Enter your live merchant secret',
                'default'     => '',
            ),
            'solidgate_webhook_public_key' => array(
                'title'       => 'Live Webhook Public Key',
                'type'        => 'text',
                'description' => 'Enter your live webhook public key',
                'default'     => 'wh_pk_95cb9d17_935d_4485_ab3e_3daea7307588',
            ),
            'solidgate_webhook_private_key' => array(
                'title'       => 'Live Webhook Private Key',
                'type'        => 'password',
                'description' => 'Enter your live webhook private key',
                'default'     => 'wh_sk_01c4d083_4957_4247_b7dd_db70cb445659',
            ),
        );
    }

    /**
     * Process the payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        try {
            // Check if this is a subscription payment method change
            if (wcs_is_subscription($order_id)) {
                return $this->process_subscription_payment_method_change($order);
            }

            // Get payment data
            $payment_data = $this->get_payment_data($order);
            if (!$payment_data) {
                throw new Exception('Unable to initialize payment data.');
            }

            // Store payment data in order meta
            update_post_meta($order->get_id(), '_solidgate_payment_data', $payment_data);

            // Set order status to pending
            $order->update_status('pending', __('Awaiting payment via Solidgate.', 'solidgate-woocommerce'));

            // Return to thank you page
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );

        } catch (Exception $e) {
            wc_add_notice('Payment error: ' . $e->getMessage(), 'error');
            return array(
                'result' => 'fail',
                'redirect' => ''
            );
        }
    }

    /**
     * Process subscription payment method change
     */
    private function process_subscription_payment_method_change($subscription) {
        try {
            $payment_token = isset($_POST['solidgate_payment_token']) ? wc_clean($_POST['solidgate_payment_token']) : '';
            
            if (empty($payment_token)) {
                throw new Exception('Payment token is required.');
            }

            // Store the new payment token
            update_post_meta($subscription->get_id(), '_solidgate_payment_token', $payment_token);

            // Update subscription status
            $subscription->update_status('active', 'Payment method updated successfully.');

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($subscription)
            );
        } catch (Exception $e) {
            wc_add_notice('Payment method update failed: ' . $e->getMessage(), 'error');
            return array(
                'result' => 'fail',
                'redirect' => ''
            );
        }
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts() {
        // Only load on checkout page
        if (!is_checkout()) {
            return;
        }

        // Enqueue Solidgate SDK
        wp_enqueue_script(
            'solidgate-sdk',
            'https://cdn.solidgate.com/js/solidgate.js',
            array(),
            '1.0.0',
            true
        );

        // Enqueue our custom script
        wp_enqueue_script(
            'solidgate-woocommerce',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/solidgate-woocommerce.js',
            array('jquery', 'solidgate-sdk'),
            time(),
            true
        );

        // Add script data
        wp_localize_script('solidgate-woocommerce', 'solidgate_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('solidgate-payment-nonce'),
            'is_checkout' => true
        ));
    }

    /**
     * AJAX handler to get Solidgate form data for the popup payment modal.
     * Returns merchant_id, signature, and payment_intent for the JS SDK.
     * Responds with wp_send_json_success or wp_send_json_error.
     */
    public function get_solidgate_form() {
        check_ajax_referer('solidgate-payment-nonce', 'nonce');

        try {
            if (!isset($_POST['order_id'])) {
                throw new Exception('Order ID is required');
            }

            $order_id = intval($_POST['order_id']);
            $order = wc_get_order($order_id);

            if (!$order) {
                throw new Exception('Invalid order');
            }

            // Get payment data for Solidgate JS SDK
            $payment_data = $this->get_payment_data($order);
            if (!$payment_data) {
                throw new Exception('Unable to initialize payment data');
            }

            // Return all required fields for the JS SDK
            wp_send_json_success($payment_data);

        } catch (Exception $e) {
            // Return a clear error message for the JS popup
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Generate payment data using PaymentIntentGenerator
     */
    private function get_payment_data($order) {
        try {
            if (!$this->sdk_api) {
                throw new Exception('SDK not properly initialized');
            }

            // Prepare payment data
            $solid_data = array(
                'order_id' => $order->get_id(),
                'amount' => round($order->get_total() * 100), // Convert to cents
                'currency' => $order->get_currency(),
                'order_description' => $this->get_order_description($order),
                'order_items' => $this->get_order_items($order),
                'type' => 'auth',
                'settle_interval' => 0,
                'retry_attempt' => 3,
                'customer_email' => $order->get_billing_email(),
                'customer_account_id' => $order->get_billing_email() . '_' . $order->get_id(),
                'platform' => 'WEB',
                'order_metadata' => array(
                    'order_id' => $order->get_id(),
                    'order_url' => get_site_url()
                )
            );

            // Generate payment intent using SDK
            $payment_data = $this->sdk_api->formMerchantData($solid_data);
            
            if (!$payment_data) {
                throw new Exception('Failed to generate payment data');
            }

            return array(
                'merchant_id' => $payment_data->getMerchantId(),
                'signature' => $payment_data->getSignature(),
                'payment_intent' => $payment_data->getPaymentIntent()
            );

        } catch (Exception $e) {
            throw new Exception('Solidgate Error: ' . $e->getMessage());
        }
    }

    /**
     * Get order description
     */
    private function get_order_description($order) {
        $description = '';
        foreach ($order->get_items() as $item) {
            $description .= esc_html($item->get_name()) . ' (' . $item->get_quantity() . '); ';
        }
        return $description;
    }

    /**
     * Get order items
     */
    private function get_order_items($order) {
        $items = '';
        foreach ($order->get_items() as $item) {
            $items .= esc_html($item->get_name()) . ', ';
        }
        return $items;
    }

    /**
     * Add payment form to checkout page
     */
    public function payment_form_container() {
        if (!is_checkout()) {
            return;
        }

        echo '<div class="payment_box payment_method_solidgate">';
        echo '<div id="solid-payment-form-container" class="solidgate-payment-form"></div>';
        echo '</div>';

        // Add inline styles
        echo '<style>
            .payment_box.payment_method_solidgate {
                position: relative;
                box-sizing: border-box;
                width: 100%;
                margin: 1em 0;
                padding: 1em;
                border-radius: 2px;
                line-height: 1.5em;
                background-color: #f8f8f8;
            }
            .payment_box.payment_method_solidgate::before {
                content: "";
                display: block;
                border: 1em solid #f8f8f8;
                border-right-color: transparent;
                border-left-color: transparent;
                border-top-color: transparent;
                position: absolute;
                top: -0.75em;
                left: 0;
                margin: -1em 0 0 2em;
            }
            .solidgate-payment-form {
                margin-top: 20px;
            }
        </style>';
    }

    /**
     * Handle payment form submission via AJAX
     */
    public function handle_payment_submission() {
        check_ajax_referer('solidgate-payment-nonce', 'nonce');

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $payment_data = isset($_POST['payment_data']) ? $_POST['payment_data'] : null;

        if (!$order_id || !$payment_data) {
            wp_send_json_error('Invalid request');
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) {
            wp_send_json_error('Invalid order');
        }

        try {
            // Process the payment
            $response = $this->api->process_payment(array(
                'order_id' => $order->get_id(),
                'amount' => $order->get_total() * 100,
                'currency' => $order->get_currency(),
                'payment_data' => $payment_data,
                'customer_email' => $order->get_billing_email(),
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'customer_phone' => $order->get_billing_phone(),
                'billing_address' => array(
                    'country' => $order->get_billing_country(),
                    'state' => $order->get_billing_state(),
                    'city' => $order->get_billing_city(),
                    'address' => $order->get_billing_address_1(),
                    'zip' => $order->get_billing_postcode()
                )
            ));

            if (isset($response['transaction']['status']) && $response['transaction']['status'] === 'success') {
                // Payment successful
                $order->payment_complete($response['transaction']['id']);
                $order->add_order_note('Payment completed via Solidgate. Transaction ID: ' . $response['transaction']['id']);
                
                // Clear cart
                WC()->cart->empty_cart();
                
                wp_send_json_success(array(
                    'redirect' => $this->get_return_url($order)
                ));
            } else {
                // Payment failed
                $error_message = isset($response['error']['message']) ? $response['error']['message'] : 'Unknown error';
                $order->update_status('failed', 'Payment failed: ' . $error_message);
                wp_send_json_error($error_message);
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
} 