<?php
/**
 * Payment Intent Generator for Solidgate
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('PaymentIntentGenerator')) {
    class PaymentIntentGenerator {
        private string $secretKey;

        public function __construct(string $secretKey) {
            $this->secretKey = $secretKey;
        }

        private function generateEncryptedFormData(array $attributes): string {
            $attributes = json_encode($attributes);
            $secretKey = substr($this->secretKey, 0, 32);

            $ivLen = openssl_cipher_iv_length('aes-256-cbc');
            $iv = openssl_random_pseudo_bytes($ivLen);

            $encrypt = openssl_encrypt($attributes, 'aes-256-cbc', $secretKey, OPENSSL_RAW_DATA, $iv);

            return $this->base64UrlEncode($iv . $encrypt);
        }

        private function base64UrlEncode($data): string {
            return str_replace(['+', '/'], ['-', '_'], base64_encode($data));
        }

        public function generatePaymentIntent(array $attributes): string {
            return $this->generateEncryptedFormData($attributes);
        }
    }
} 