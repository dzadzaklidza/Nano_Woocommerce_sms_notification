<?php
/**
 * Plugin Name: NALO SMS WooCommerce Notifications
 * Description: Send SMS notifications and custom messages to customers via NALO API.
 * Version: 2.1
 * Author: Peka Integrated Technologies
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', function () {

    if (!class_exists('WooCommerce')) return;

    class NALO_SMS_WC {

        private $logger;
        private $tab_id = 'nalo_sms';

        public function __construct() {
            $this->logger = wc_get_logger();

            add_action('woocommerce_order_status_changed', [$this,'on_status_change'], 10, 4);

            add_filter('woocommerce_settings_tabs_array', [$this,'add_tab'], 50);
            add_action("woocommerce_settings_tabs_{$this->tab_id}", [$this,'render_settings']);
            add_action("woocommerce_update_options_{$this->tab_id}", [$this,'save_settings']);

            add_action('add_meta_boxes', [$this,'add_order_sms_box']);
        }

        /* ================= SETTINGS TAB ================= */

        public function add_tab($tabs) {
            $tabs[$this->tab_id] = 'NALO SMS';
            return $tabs;
        }

        public function render_settings() {
            woocommerce_admin_fields($this->get_settings());
        }

        public function save_settings() {
            woocommerce_update_options($this->get_settings());
        }

        private function get_settings() {

            return [

                [
                    'title' => 'NALO SMS Settings',
                    'type'  => 'title',
                    'id'    => 'nalo_sms_section'
                ],

                [
                    'title' => 'Authentication Key',
                    'id'    => 'nalo_auth_key',
                    'type'  => 'password',
                ],

                [
                    'title' => 'Sender ID',
                    'id'    => 'nalo_sender_id',
                    'type'  => 'text',
                ],

                [
                    'title' => 'Enable SMS For',
                    'id'    => 'nalo_enabled_statuses',
                    'type'  => 'multiselect',
                    'class' => 'wc-enhanced-select',
                    'options' => [
                        'processing' => 'Processing',
                        'completed'  => 'Completed',
                        'on-hold'    => 'On Hold',
                        'cancelled'  => 'Cancelled',
                    ],
                ],

                ['title'=>'Processing Template','id'=>'nalo_tpl_processing','type'=>'textarea'],
                ['title'=>'Completed Template','id'=>'nalo_tpl_completed','type'=>'textarea'],
                ['title'=>'On-hold Template','id'=>'nalo_tpl_on-hold','type'=>'textarea'],
                ['title'=>'Cancelled Template','id'=>'nalo_tpl_cancelled','type'=>'textarea'],

                [
                    'type' => 'custom',
                    'id'   => 'nalo_sms_donate',
                    'desc' => '
                        <hr>
                        <p><strong>Support Development ❤️</strong></p>
                        <p>If this plugin helps your business, consider supporting development.Thank you!!</p>
                        <a href="https://paystack.shop/pay/s66qyjn25-"
                           target="_blank"
                           style="
                             background:#2271b1;
                             color:#fff;
                             padding:10px 18px;
                             border-radius:4px;
                             text-decoration:none;
                             font-weight:bold;
                           ">
                           Donate via Paystack
                        </a>
                    ',
                ],

                [
                    'type' => 'sectionend',
                    'id'   => 'nalo_sms_section'
                ],
            ];
        }

        /* ================= ORDER STATUS ================= */

        public function on_status_change($order_id, $old, $new, $order) {

            $enabled = (array)get_option('nalo_enabled_statuses', []);
            if (!in_array($new, $enabled)) return;

            $template = get_option("nalo_tpl_$new");
            if (!$template) return;

            $phone = $this->normalize_phone($order->get_billing_phone());
            if (!$phone) return;

            $message = str_replace(
                ['{order_number}','{order_total}','{order_status}','{customer_name}'],
                [
                    $order->get_order_number(),
                    $this->clean_currency($order->get_total()),
                    ucfirst($new),
                    $order->get_billing_first_name()
                ],
                $template
            );

            $this->send_sms($phone, $message);
        }

        /* ================= ORDER PAGE SMS ================= */

        public function add_order_sms_box() {
            add_meta_box(
                'nalo_sms_box',
                'Send Custom SMS',
                [$this,'render_sms_box'],
                'shop_order',
                'side',
                'high'
            );
        }

        public function render_sms_box($post) {

            if (isset($_POST['nalo_custom_sms'])) {
                $order = wc_get_order($post->ID);
                $phone = $this->normalize_phone($order->get_billing_phone());
                $msg   = sanitize_textarea_field($_POST['nalo_custom_sms']);

                if ($phone && $msg) {
                    $this->send_sms($phone, $msg);
                    echo '<p style="color:green;">SMS Sent</p>';
                }
            }
            ?>
            <textarea name="nalo_custom_sms" rows="4" style="width:100%;"
                placeholder="Type custom SMS to customer..."></textarea>
            <button class="button button-primary" style="margin-top:6px;">Send SMS</button>
            <?php
        }

        /* ================= SMS CORE ================= */

        private function send_sms($phone, $message) {

            $key    = get_option('nalo_auth_key');
            $sender = get_option('nalo_sender_id');
            if (!$key || !$sender) return;

            $phone   = $this->normalize_phone($phone);
            $message = $this->clean_currency($message);
            $message = wp_strip_all_tags($message);

            $this->logger->info("SMS → $phone | $message", ['source'=>'nalo_sms']);

            wp_remote_post(
                'https://sms.nalosolutions.com/smsbackend/clientapi/Resl_Nalo/send-message/',
                [
                    'timeout' => 20,
                    'body' => [
                        'key'       => $key,
                        'sender_id'=> $sender,
                        'msisdn'   => $phone,
                        'message'  => $message,
                    ]
                ]
            );
        }

        /* ================= HELPERS ================= */

        private function normalize_phone($phone) {
            $p = preg_replace('/\D/','',$phone);
            if (strlen($p)==10 && $p[0]=='0') return '+233'.substr($p,1);
            if (strlen($p)==12 && substr($p,0,3)=='233') return '+'.$p;
            return false;
        }

        private function clean_currency($amount) {

            if (is_numeric($amount)) {
                return 'GHS ' . number_format((float)$amount, 2);
            }

            $amount = html_entity_decode($amount);
            $amount = wp_strip_all_tags($amount);
            $amount = preg_replace('/[^\d\.]/', '', $amount);

            return 'GHS ' . $amount;
        }
    }

    new NALO_SMS_WC();
});
