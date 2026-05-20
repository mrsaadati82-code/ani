<?php
if (!defined('ABSPATH')) exit;

class FCUI_Gateway_Card2Card extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'fcui_card2card';
        $this->method_title       = 'کارت به کارت';
        $this->method_description = 'پرداخت از طریق کارت به کارت (آپلود رسید و تأیید دستی).';
        $this->has_fields         = false;

        $this->title = 'کارت به کارت';

        // For availability, Woo may check supports.
        $this->supports = ['products'];

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function is_available() {
        if (!parent::is_available()) return false;

        if (!class_exists('FCUI_Fast_Checkout')) return true;

        $s = FCUI_Fast_Checkout::get_settings();
        if (empty($s['c2c_enabled'])) return false;

        return true;
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['result' => 'failure'];
        }

        if (!class_exists('FCUI_Fast_Checkout')) {
            return ['result' => 'failure'];
        }

        $s = FCUI_Fast_Checkout::get_settings();
        $mins = max(1, (int)$s['c2c_timer_minutes']);
        $expires = time() + ($mins * 60);

        $order->update_meta_data('_fcui_c2c_expires_at', $expires);
        $order->update_status('on-hold', 'در انتظار پرداخت کارت به کارت و بررسی رسید');
        $order->save();

        WC()->cart->empty_cart();

        $redirect = FCUI_Fast_Checkout::get_card2card_url([
            'order_id' => $order->get_id(),
            'key'      => $order->get_order_key(),
        ]);

        return [
            'result'   => 'success',
            'redirect' => $redirect,
        ];
    }
}