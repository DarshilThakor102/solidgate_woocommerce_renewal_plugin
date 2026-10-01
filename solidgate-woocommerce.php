    <?php
    /**
     * Plugin Name: Solidgate Payment Gateway for WooCommerce
     * Plugin URI: https://solidgate.com
     * Description: Accept payments through Solidgate payment gateway with subscription support
     * Version: 1.0.0
     * Requires at least: 5.8
     * Requires PHP: 7.2
     * Author: Darshil
     * Author URI: https://solidgate.com
     * License: GPL v2 or later
     * License URI: https://www.gnu.org/licenses/gpl-2.0.html
     * Text Domain: solidgate-woocommerce
     * Domain Path: /languages
     * WC requires at least: 5.0
     * WC tested up to: 8.0
     *
     * @package Solidgate_WooCommerce
     */

    if (!defined('ABSPATH')) {
        exit;
    }

    // Define plugin constants
    define('SOLIDGATE_WC_VERSION', '1.0.0');
    define('SOLIDGATE_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
    define('SOLIDGATE_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
    define('SOLIDGATE_WC_PLUGIN_BASENAME', plugin_basename(__FILE__));

    // Include required files
    require_once SOLIDGATE_WC_PLUGIN_DIR . 'includes/class-solidgate-api.php';
    require_once SOLIDGATE_WC_PLUGIN_DIR . 'includes/class-solidgate-webhook-handler.php';
    require_once SOLIDGATE_WC_PLUGIN_DIR . 'includes/class-solidgate-subscription-handler.php';

    /**
     * Initialize the gateway
     */
    function solidgate_wc_init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', 'solidgate_wc_woocommerce_missing_notice');
            return;
        }

        if (!class_exists('WC_Subscriptions')) {
            add_action('admin_notices', 'solidgate_wc_subscriptions_missing_notice');
            return;
        }

        // Load the gateway class after WooCommerce is loaded
        require_once SOLIDGATE_WC_PLUGIN_DIR . 'includes/class-solidgate-gateway.php';

        // Add the gateway to WooCommerce
        function add_solidgate_gateway($methods) {
            $methods[] = 'WC_Solidgate_Gateway';
            return $methods;
        }
        add_filter('woocommerce_payment_gateways', 'add_solidgate_gateway');

        // Add AJAX action handler
        function solidgate_process_payment() {
            $gateway = new WC_Solidgate_Gateway();
            $gateway->handle_payment_submission();
        }
        add_action('wp_ajax_solidgate_process_payment', 'solidgate_process_payment');
        add_action('wp_ajax_nopriv_solidgate_process_payment', 'solidgate_process_payment');
    }
    add_action('plugins_loaded', 'solidgate_wc_init', 11); // Priority 11 to ensure WooCommerce is loaded first

    /**
     * WooCommerce missing notice
     */
    function solidgate_wc_woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('Solidgate Payment Gateway requires WooCommerce to be installed and active.', 'solidgate-woocommerce'); ?></p>
        </div>
        <?php
    }

    /**
     * WooCommerce Subscriptions missing notice
     */
    function solidgate_wc_subscriptions_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('Solidgate Payment Gateway requires WooCommerce Subscriptions to be installed and active.', 'solidgate-woocommerce'); ?></p>
        </div>
        <?php
    }

    /**
     * Add settings link to plugin page
     */
    function solidgate_wc_plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=solidgate') . '">' . __('Settings', 'solidgate-woocommerce') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    add_filter('plugin_action_links_' . SOLIDGATE_WC_PLUGIN_BASENAME, 'solidgate_wc_plugin_action_links');

    /**
     * Add plugin meta links
     */
    function solidgate_wc_plugin_row_meta($links, $file) {
        if (SOLIDGATE_WC_PLUGIN_BASENAME === $file) {
            $row_meta = array(
                'docs' => sprintf(
                    '<a href="%s" aria-label="%s">%s</a>',
                    esc_url('https://solidgate.com/docs'),
                    esc_attr__('View Solidgate documentation', 'solidgate-woocommerce'),
                    esc_html__('Documentation', 'solidgate-woocommerce')
                ),
                'support' => sprintf(
                    '<a href="%s" aria-label="%s">%s</a>',
                    esc_url('https://solidgate.com/support'),
                    esc_attr__('Visit Solidgate support', 'solidgate-woocommerce'),
                    esc_html__('Support', 'solidgate-woocommerce')
                ),
            );
            return array_merge($links, $row_meta);
        }
        return $links;
    }
    add_filter('plugin_row_meta', 'solidgate_wc_plugin_row_meta', 10, 2);

    /**
     * Register activation hook
     */
    function solidgate_wc_activate() {
        // Create necessary database tables or options
        if (!get_option('solidgate_wc_version')) {
            add_option('solidgate_wc_version', SOLIDGATE_WC_VERSION);
        }
    }
    register_activation_hook(__FILE__, 'solidgate_wc_activate');

    /**
     * Register deactivation hook
     */
    function solidgate_wc_deactivate() {
        // Clean up if necessary
    }
    register_deactivation_hook(__FILE__, 'solidgate_wc_deactivate');

    /**
     * Register uninstall hook
     */
    function solidgate_wc_uninstall() {
        // Remove plugin data
        delete_option('solidgate_wc_version');
    }
    register_uninstall_hook(__FILE__, 'solidgate_wc_uninstall'); 