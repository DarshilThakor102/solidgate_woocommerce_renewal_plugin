# Solidgate Payment Gateway for WooCommerce

A WordPress plugin that integrates Solidgate payment gateway with WooCommerce and WooCommerce Subscriptions.

## Description

Solidgate Payment Gateway for WooCommerce allows you to accept payments through Solidgate's secure payment processing platform. The plugin supports both one-time payments and recurring subscriptions.

### Features

- Accept credit card payments
- Support for WooCommerce Subscriptions
- Secure payment processing
- Subscription management
- Payment method updates
- Failed payment handling
- Webhook support
- Test mode for development

## Installation

1. Upload the `solidgate-woocommerce` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Settings > Payments
4. Click on "Solidgate" to configure your payment gateway settings

## Requirements

- WordPress 5.8 or higher
- PHP 7.2 or higher
- WooCommerce 5.0 or higher
- WooCommerce Subscriptions (for subscription support)

## Configuration

1. Log in to your Solidgate merchant account
2. Get your API credentials (Merchant ID, Merchant Key, and Merchant Secret)
3. In WordPress, go to WooCommerce > Settings > Payments
4. Click on "Solidgate" to configure:
   - Enable/Disable the gateway
   - Enter your API credentials
   - Configure test mode
   - Customize payment form settings

## Usage

### Regular Payments

1. Add products to cart
2. Proceed to checkout
3. Select "Solidgate" as payment method
4. Enter card details
5. Complete the purchase

### Subscriptions

1. Add subscription products to cart
2. Proceed to checkout
3. Select "Solidgate" as payment method
4. Enter card details
5. Complete the subscription purchase

## Support

For support, please contact:
- Email: support@solidgate.com
- Website: https://solidgate.com/support

## License

This plugin is licensed under the GPL v2 or later.

## Changelog

### 1.0.0
- Initial release
- Basic payment processing
- Subscription support
- Webhook handling
- Payment method updates

## Credits

Developed by Solidgate 