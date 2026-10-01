<?php
if (!defined('ABSPATH')) {
    exit;
}

class Solidgate_API {
    private $public_key;
    private $private_key;
    private $test_mode;

    public function __construct($public_key, $private_key, $test_mode = false) {
        $this->public_key = $public_key;
        $this->private_key = $private_key;
        $this->test_mode = $test_mode;
    }

    /**
     * Generate signature for API request
     */
    private function generate_signature($data) {
        $text = $this->public_key . $data . $this->public_key;
        $hashedBytes = hash_hmac('sha512', $text, $this->private_key);
        return base64_encode($hashedBytes);
    }

    /**
     * Make API request to Solidgate
     */
    private function make_request($endpoint, $data) {
        $signature = $this->generate_signature($data);
        
        $requestData = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'merchant' => $this->public_key,
                'signature' => $signature,
            ],
            'body' => $data,
        ];

        $api_url = $this->test_mode ? 'https://pay.solidgate.com/api/v1/' : 'https://pay.solidgate.com/api/v1/';
        $response = wp_remote_post($api_url . $endpoint, $requestData);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            throw new Exception($body['error']['messages']['0'] ?? 'Unknown error occurred');
        }

        return $body;
    }

    /**
     * Process initial payment
     */
    public function process_payment($order_data) {
        return $this->make_request('charge', json_encode($order_data));
    }

    /**
     * Process recurring payment
     */
    public function process_recurring_payment($order_data) {
        return $this->make_request('recurring', json_encode($order_data));
    }

    /**
     * Validate webhook signature
     */
    public function validate_webhook_signature($json_string) {
        $webhook_public_key = $this->test_mode ? 
            'wh_pk_46931c3a_' : 
            'wh_pk_95cb9d17_';
            
        $webhook_private_key = $this->test_mode ? 
            'wh_sk_b4542336_' : 
            'wh_sk_01c4d083_';

        $expected_signature = base64_encode(
            hash_hmac('sha512',
                $webhook_public_key . $json_string . $webhook_public_key,
                $webhook_private_key)
        );

        return $expected_signature;
    }
} 