<?php
/**
 * Class to track the behavior of customer and block the customer from future
 * checkout process
 */
if ( !defined('ABSPATH') ) {
    exit();
}

if ( !class_exists('WMFO_Track_Customers') ) {

    class WMFO_Track_Customers {

        public static $_instance;

        public function __construct() {
            add_action('woocommerce_after_checkout_validation', array(
                $this,
                'manage_blacklisted_customers_checkout',
            ), 10, 2);

            add_action('woocommerce_before_pay_action', array(
                $this, 'manage_blacklisted_customers_order_pay'),
                99, 1);

            add_action('woocommerce_after_pay_action', array(
                $this, 'manage_multiple_failed_attempts_order_pay'),
                99, 1);

            add_action('woocommerce_checkout_order_processed', array(
                $this,
                'manage_multiple_failed_attempts_checkout',
            ), 100, 3);
        }

        public static function instance() {
            if ( is_null(self::$_instance) ) {
                self::$_instance = new self();
            }

            return self::$_instance;
        }

        /**
         * @param $data
         * @param $errors
         */
        public static function manage_blacklisted_customers_checkout( $data, $errors ) {
            //Check if there are any other errors first
            //If there are, return
            if ( !empty($errors->errors) ) {
                return;
            }

            //Woo/Payment method saves the payment method validation errors in session
            //If there such errors, skip
            if ( !isset(WC()->session->reload_checkout) ) {
                $error_notices = wc_get_notices('error');
            }

            if ( !empty($error_notices) ) {
                return;
            }

            $customer_details = array();

            $first_name = isset($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : '';
            $last_name = isset($_POST['billing_last_name']) ? sanitize_text_field($_POST['billing_last_name']) : '';
            $customer_details['full_name'] = $first_name . ' ' . $last_name;

            $customer_details['billing_email'] = isset($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : '';
            $customer_details['billing_phone'] = isset($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '';

            self::manage_blacklisted_customers($customer_details);
        }

        /**
         * @param $order
         */
        public static function manage_blacklisted_customers_order_pay( $order ) {
            $customer_details = array();

            $first_name = $order->get_billing_first_name();
            $last_name = $order->get_billing_last_name();
            $customer_details['full_name'] = $first_name . ' ' . $last_name;

            $customer_details['billing_email'] = $order->get_billing_email();
            $customer_details['billing_phone'] = $order->get_billing_phone();

            self::manage_blacklisted_customers($customer_details);
        }

        /**
         * @param $customer_details
         */
        public static function manage_blacklisted_customers( $customer_details ) {
            $customer_details['ip_address'] = method_exists('WC_Geolocation', 'get_ip_address') ? WC_Geolocation::get_ip_address() : wmfo_get_ip_address();
            $domain = substr($customer_details['billing_email'], strpos($customer_details['billing_email'], '@') + 1);

            $allow_blacklist_by_name = get_option('wmfo_allow_blacklist_by_name', 'no');
            $prev_black_list_names = get_option('wmfo_black_list_names', true);

            $prev_black_list_ips = get_option('wmfo_black_list_ips', true);
            $prev_black_list_phones = get_option('wmfo_black_list_phones', true);
            $prev_black_list_emails = get_option('wmfo_black_list_emails', true);
            $prev_black_list_email_domains = get_option('wmfo_black_list_email_domains', true);

            //Block this checkout if this customers details are already blacklisted
            if ( $customer_details['full_name'] && $allow_blacklist_by_name == 'yes' && $prev_black_list_names && in_array($customer_details['full_name'], explode(PHP_EOL, $prev_black_list_names)) ||
                $customer_details['ip_address'] && $prev_black_list_ips && in_array($customer_details['ip_address'], explode(PHP_EOL, $prev_black_list_ips)) ||
                $prev_black_list_phones && $customer_details['billing_phone'] && in_array($customer_details['billing_phone'], explode(PHP_EOL, $prev_black_list_phones)) ||
                $customer_details['billing_email'] && $prev_black_list_emails && in_array($customer_details['billing_email'], explode(PHP_EOL, $prev_black_list_emails)) ||
                $domain && $prev_black_list_email_domains && in_array($domain, explode(PHP_EOL, $prev_black_list_email_domains))
            ) {
                if ( method_exists('WMFO_Blacklist_Handler', 'show_blocked_message') ) {
                    WMFO_Blacklist_Handler::show_blocked_message();
                }

                return;
            }

            /**
             * Block the customer if there is setting for order_status blocking
             * If the customer previously has blocked order status in setting, He/She will be blocked from placing
             * order
             */
            $blacklists_order_status = get_option('wmfo_black_list_order_status', true);

            //Get all previous orders of current customer
            $args = array(
                'post_type' => 'shop_order',
                'posts_per_page' => -1,
                'post_status' => 'any',
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key' => '_customer_user',
                        'value' => is_user_logged_in() ? get_current_user_id() : null, // For logged in
                        'compare' => '=',
                    ),
                    array(
                        'key' => '_billing_email',
                        'value' => sanitize_email($_POST['billing_email']), // For guest customer
                        'compare' => '=',
                    ),
                    array(
                        'key' => '_billing_phone',
                        'value' => sanitize_text_field($_POST['billing_phone']), // For guest customer
                        'compare' => '=',
                    ),
                ),
            );

            $prev_orders_customers = get_posts($args);

            if ( !empty($prev_orders_customers) ) {
                foreach ( $prev_orders_customers as $prev_order ) {

                    if ( in_array($prev_order->post_status, $blacklists_order_status) ) {
                        if ( method_exists('WMFO_Blacklist_Handler', 'show_blocked_message') ) {
                            WMFO_Blacklist_Handler::show_blocked_message();
                        }
                        break;
                    }
                }
            }
        }

        /**
         * @param $order_id
         * @param $posted_data
         * @param $order
         * @throws Exception
         */
        public static function manage_multiple_failed_attempts_checkout( $order_id, $posted_data, $order ) {
            self::manage_multiple_failed_attempts($order);
        }

        /**
         * @param $order
         * @throws Exception
         */
        public static function manage_multiple_failed_attempts_order_pay( $order ) {
            self::manage_multiple_failed_attempts($order, 'order-pay');
        }

        /**
         *
         * 'manage_multiple_failed_attempts' will only track the multiple failed attempts after the creating of failed
         * order by customer, This is helpful when customer enter the correct format of the data but payment gateway
         * couldn't authorize the payment. Typical example will be Electronic check, CC processing
         *
         * @param $order
         * @param string $context
         * @throws Exception
         */
        public static function manage_multiple_failed_attempts( $order, $context = 'front' ) {
            if ( $order->get_status() === 'failed' ) {
                //md5 the name of the cookie for fraud_attempts
                $fraud_attempts_md5 = md5('fraud_attempts');
                $fraud_attempts = (!isset($_COOKIE[$fraud_attempts_md5]) || null === $_COOKIE[$fraud_attempts_md5]) ? 0 : sanitize_text_field($_COOKIE[$fraud_attempts_md5]);

                $cookie_value = (int)$fraud_attempts + 1;
                setcookie($fraud_attempts_md5, $cookie_value, time() + (60 * 60 * 30), '/'); // 30 days
                //Get the allowed failed order limit, default to 3
                $fraud_limit = get_option('wmfo_black_list_allowed_fraud_attempts') != '' ? get_option('wmfo_black_list_allowed_fraud_attempts') : 5;

                if ( (int)$fraud_attempts >= $fraud_limit ) {
                    //Block this customer for future sessions as well
                    //And cancel the order
                    $customer = wmfo_get_customer_details_of_order($order);
                    if ( method_exists('WMFO_Blacklist_Handler', 'init') ) {
                        WMFO_Blacklist_Handler::init($customer, $order, 'add', $context);
                    }
                }
            }
        }

    }
}

WMFO_Track_Customers::instance();
