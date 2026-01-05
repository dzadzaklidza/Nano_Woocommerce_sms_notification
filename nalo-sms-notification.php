<?php
/**
 * Plugin Name: NALO SMS Notifications for WooCommerce
 * Description: Send SMS notifications and custom messages to customers via NALO API.
 * Version: 2.4
 * Author: Peka Integrated Technologies
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
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

            add_action('woocommerce_admin_field_nalo_donate', [$this,'render_donate_field']);
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
                ['title'=>'NALO SMS Settings','type'=>'title','id'=>'nalo_sms_section'],

                ['title'=>'Authentication Key','id'=>'nalo_auth_key','type'=>'password'],
                ['title'=>'Sender ID','id'=>'nalo_sender_id','type'=>'text'],

                [
                    'title'=>'Enable SMS For',
                    'id'=>'nalo_enabled_statuses',
                    'type'=>'multiselect',
                    'class'=>'wc-enhanced-select',
                    'options'=>[
                        'processing'=>'Processing',
                        'completed'=>'Completed',
                        'on-hold'=>'On Hold',
                        'cancelled'=>'Cancelled',
                    ],
                ],

                ['title'=>'Processing Template','id'=>'nalo_tpl_processing','type'=>'textarea'],
                ['title'=>'Completed Template','id'=>'nalo_tpl_completed','type'=>'textarea'],
                ['title'=>'On-hold Template','id'=>'nalo_tpl_on-hold','type'=>'textarea'],
                ['title'=>'Cancelled Template','id'=>'nalo_tpl_cancelled','type'=>'textarea'],

                ['type'=>'nalo_donate','id'=>'nalo_sms_donate'],
                ['type'=>'sectionend','id'=>'nalo_sms_section'],
            ];
        }

        /* ================= DONATE ================= */

        public function render_donate_field() {
            ?>
            <tr>
                <th><label><?php esc_html_e('Support Development', 'nalo-sms'); ?></label></th>
                <td>
                    <a href="https://paystack.shop/pay/s66qyjn25-" target="_blank" class="button button-primary">
                        <?php esc_html_e('Donate via Paystack', 'nalo-sms'); ?>
                    </a>
                </td>
            </tr>
            <?php
        }

        /* ================= ORDER STATUS ================= */

        public function on_status_change($order_id, $old, $new, $order) {

            $enabled = (array) get_option('nalo_enabled_statuses', []);
            if (!in_array($new, $enabled, true)) return;

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
                __('Send Custom SMS', 'nalo-sms'),
                [$this,'render_sms_box'],
                'shop_order',
                'side'
            );
        }

        public function render_sms_box($post) {

            wp_nonce_field('nalo_sms_nonce_action', 'nalo_sms_nonce');

            if (
                isset($_POST['nalo_custom_sms'], $_POST['nalo_sms_nonce']) &&
                wp_verify_nonce($_POST['nalo_sms_nonce'], 'nalo_sms_nonce_action')
            ) {
                $order = wc_get_order($post->ID);
                $phone = $this->normalize_phone($order->get_billing_phone());

                $msg = sanitize_textarea_field(
                    wp_unslash($_POST['nalo_custom_sms'])
                );

                if ($phone && $msg) {
                    $this->send_sms($phone, $msg);
                    echo '<p style="color:green;">'.esc_html__('SMS Sent','nalo-sms').'</p>';
                }
            }
            ?>
            <textarea name="nalo_custom_sms" rows="4" style="width:100%;"
                placeholder="<?php esc_attr_e('Type custom SMS…','nalo-sms'); ?>"></textarea>
            <button class="button button-primary" style="margin-top:6px;">
                <?php esc_html_e('Send SMS','nalo-sms'); ?>
            </button>
            <?php
        }

        /* ================= SMS ================= */

        private function send_sms($phone, $message) {

            $key    = get_option('nalo_auth_key');
            $sender = get_option('nalo_sender_id');
            if (!$key || !$sender) return;

            $phone   = $this->normalize_phone($phone);
            $message = wp_strip_all_tags($message);

            wp_remote_post(
                'https://sms.nalosolutions.com/smsbackend/clientapi/Resl_Nalo/send-message/',
                [
                    'timeout'=>20,
                    'body'=>[
                        'key'=>$key,
                        'sender_id'=>$sender,
                        'msisdn'=>$phone,
                        'message'=>$message,
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
            return is_numeric($amount)
                ? 'GHS '.number_format((float)$amount,2)
                : 'GHS '.preg_replace('/[^\d\.]/','',(string)$amount);
        }
    }

    new NALO_SMS_WC();
});
