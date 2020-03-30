<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin Name: Shoppy WooCommerce Payment Gateway
 * Plugin URI: http://github.com/shoppygg/shoppy-woocommerce
 * Description:  A payment gateway for Shoppy Pay
 * Author: Shoppy
 * Author URI: https://shoppy.gg
 * Version: 1.0.1
 */

add_action('plugins_loaded', 'shoppy_gateway_load', 0);

function shoppy_gateway_load()
{

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    /**
     * Add the gateway to WooCommerce.
     */
    add_filter('woocommerce_payment_gateways', 'add_gateway');

    function add_gateway($classes)
    {
        if (!in_array('WC_Gateway_Shoppy', $classes)) {
            $classes[] = 'WC_Gateway_Shoppy';
        }

        return $classes;
    }

    class WC_Gateway_Shoppy extends WC_Payment_Gateway
    {
        /**
         * Constructor for the gateway.
         *
         * @access public
         * @return void
         */
        public function __construct()
        {
            global $woocommerce;

            $this->id = 'shoppy';
            $this->icon = apply_filters('woocommerce_shoppy_icon', plugins_url() . '/shoppy-woocommerce/assets/shoppy.png');
            $this->method_title = __('Shoppy', 'woocommerce');
            $this->has_fields = true;
            $this->webhook_url = add_query_arg('wc-api', 'shoppy_webhook_handler', home_url('/'));

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->email = $this->get_option('email');
            $this->api_key = $this->get_option('api_key');
            $this->webhook_secret = $this->get_option('webhook_secret');
            $this->order_id_prefix = $this->get_option('order_id_prefix');
            $this->confirmations = $this->get_option('confirmations');
            $this->paypal = $this->get_option('paypal') == 'yes' ? true : false;
            $this->bitcoin = $this->get_option('bitcoin') == 'yes' ? true : false;
            $this->litecoin = $this->get_option('litecoin') == 'yes' ? true : false;
            $this->ethereum = $this->get_option('ethereum') == 'yes' ? true : false;

            // Logger
            $this->log = new WC_Logger();

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

            // Webhook Handler
            add_action('woocommerce_api_shoppy_webhook_handler', [$this, 'webhook_handler']);
        }


        public function payment_fields()
        {
            ?>
            <script src="https://shoppy.gg/api/embed.js"></script>

            <div class="form-row shoppy-payment-gateway-form">
                <label for="payment_gateway" class="shoppy-payment-gateway-label">
                    Payment Method <abbr class="required" title="required">*</abbr>
                </label>
                <select name="payment_gateway" class="shoppy-payment-gateway-select">
                    <?php if ($this->paypal) { ?>
                        <option value="PayPal">PayPal</option><?php } ?>
                    <?php if ($this->bitcoin) { ?>
                        <option value="BTC">Bitcoin</option><?php } ?>
                    <?php if ($this->litecoin) { ?>
                        <option value="LTC">Litecoin</option><?php } ?>
                    <?php if ($this->ethereum) { ?>
                        <option value="ETH">Ethereum</option><?php } ?>
                </select>
            </div>

            <script>
                jQuery(document).ajaxComplete(function (event, xhr, opt) {
                    if (xhr.responseJSON && xhr.responseJSON.payment) {
                        window.shoppy.launch(xhr.responseJSON.payment)
                    }
                })
            </script>

            <?php
        }

        /**
         * Check if this gateway is available
         *
         * @access public
         * @return bool
         */
        function is_valid_for_use()
        {
            return true;
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @since 1.0.0
         */
        public function admin_options()
        {
            ?>
            <h3><?php _e('Shoppy', 'woocommerce'); ?></h3>

            <table class="form-table">
                <?php
                $this->generate_settings_html();
                ?>
            </table>
            <?php
        }


        /**
         * Initialise settings
         *
         * @access public
         * @return void
         */
        function init_form_fields()
        {
            $this->form_fields = [
                'enabled'         => [
                    'title'   => __('Enable/Disable', 'woocommerce'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable Shoppy', 'woocommerce'),
                    'default' => 'yes'
                ],
                'title'           => [
                    'title'       => __('Title', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default'     => __('Shoppy Pay', 'woocommerce'),
                    'desc_tip'    => true,
                ],
                'description'     => [
                    'title'       => __('Description', 'woocommerce'),
                    'type'        => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                    'default'     => __('Pay with PayPal, Bitcoin, Ethereum, Litecoin and many more gateways via Shoppy', 'woocommerce')
                ],
                'api_key'         => [
                    'title'       => __('API Key', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Please enter your Shoppy API Key.', 'woocommerce'),
                    'default'     => '',
                ],
                'webhook_secret'  => [
                    'title'       => __('Webhook secret', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('Please enter your Shoppy webhook secret.', 'woocommerce'),
                    'default'     => '',
                ],
                'order_id_prefix' => [
                    'title'       => __('Order ID Prefix', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('The prefix before the order number. For example, a prefix of "Order #" and a ID of "10" will result in "Order #10"', 'woocommerce'),
                    'default'     => 'Order #',
                ],
                'confirmations'   => [
                    'title'       => __('Number of confirmations for crypto currencies', 'woocommerce'),
                    'type'        => 'number',
                    'description' => __('The default of 1 is advised for both speed and security', 'woocommerce'),
                    'default'     => '1'
                ],
                'paypal'          => [
                    'title'   => __('Accept PayPal', 'woocommerce'),
                    'label'   => __('Enable/Disable PayPal', 'woocommerce'),
                    'type'    => 'checkbox',
                    'default' => 'no',
                ],
                'bitcoin'         => [
                    'title'   => __('Accept Bitcoin', 'woocommerce'),
                    'label'   => __('Enable/Disable Bitcoin', 'woocommerce'),
                    'type'    => 'checkbox',
                    'default' => 'no',
                ],
                'litecoin'        => [
                    'title'   => __('Accept Litecoin', 'woocommerce'),
                    'label'   => __('Enable/Disable Litecoin', 'woocommerce'),
                    'type'    => 'checkbox',
                    'default' => 'no',
                ],
                'ethereum'        => [
                    'title'   => __('Accept Ethereum', 'woocommerce'),
                    'label'   => __('Enable/Disable Ethereum', 'woocommerce'),
                    'type'    => 'checkbox',
                    'default' => 'no',
                ]
            ];

        }

        function generate_shoppy_payment($order)
        {
            if (!function_exists('curl_version')) {
                return wc_add_notice('No cURL installed');
            }

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL            => 'https://shoppy.gg/api/v2/pay',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT      => 'Shoppy WooCommerce (PHP ' . PHP_VERSION . ')',
                CURLOPT_HTTPHEADER     => [
                    'Authorization: ' . $this->api_key
                ],
                CURLOPT_POSTFIELDS     => json_encode([
                    'title' => $order->order_key,
                    'value' => $order->get_total(),
                    'webhook_urls'  => [
                        add_query_arg('wc_id', $order->get_id(), $this->webhook_url)
                    ],
                    'confirmations' => $this->confirmations
                ])
            ]);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                return wc_add_notice(__('Payment error:', 'woothemes') . 'Request error: ' . curl_error($ch), 'error');
            }

            curl_close($ch);
            $response = json_decode($response, true);

            if (isset($response['error'])) {
                return wc_add_notice(__('Payment error:', 'woothemes') . 'Shoppy API error: ' . join($response['error']), 'error');
            } else {
                return $response['id'];
            }
        }


        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $payment = $this->generate_shoppy_payment($order);

            if ($payment) {
                die(json_encode([
                    'result'   => 'failure',
                    'messages' => 'KappaClaus',
                    'payment'  => $payment
                ]));
            } else {
                return;
            }

        }

        /**
         * Handle webhooks
         *
         * @access public
         * @return void
         */
        function webhook_handler()
        {
            global $woocommerce;

            $this->log->add('shoppy', 'Processing webhook with secret: ' . $this->webhook_secret);
            $data = file_get_contents('php://input');

            $this->log->add('shoppy', 'Processing webhook');

            $data = json_decode($data);
            $order = $this->get_shoppy_order($data->data->order->id);

            $this->log->add('shoppy', 'Processing order: ' . $data->data->order->id);

            if($order) {
                $wc_order = wc_get_order($_REQUEST['wc_id']);
                $this->log->add('shoppy', 'Order #' . $_REQUEST['wc_id'] . ' Status: ' . $order->paid_at);

                if ($order->paid_at) {
                    $wc_order->payment_complete();

                    //$this->log->add('shoppy', 'WC_ORDER: ' . $wc_order);

                    $this->log->add('shoppy', 'Marking payment as completed');
                }
            } else {
                $this->log->add('shoppy', 'Invalid order specified');
            }
        }

        function get_shoppy_order($order_id) {
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL            => 'https://shoppy.gg/api/v1/orders/' . $order_id,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT      => 'Shoppy WooCommerce (PHP ' . PHP_VERSION . ')',
                CURLOPT_HTTPHEADER     => [
                    'Authorization: ' . $this->api_key
                ]
            ]);

            $response = json_decode(curl_exec($ch));

            curl_close($ch);

            if (!$response->status) {
                return $response;
            } else {
                $this->log->add('shoppy', 'Unable to verify order: ' . $order_id);
                return null;
            }
        }
    }
}
