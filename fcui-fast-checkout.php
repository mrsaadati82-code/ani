<?php
/**
 * Plugin Name: پرداخت سریع - سلام وردپرس
 * Description: این پلاگین صفحه ی پرداخت سریع را جایگزین فرایند سفارش ووکامرس میکند.
 * Version: 2.0.0
 * Author: امیرحسین سعادتی
 */

if (!defined('ABSPATH')) exit;

final class FCUI_Fast_Checkout {

    // ========== Options ==========
    const OPT_FAST_PAGE_ID = 'fcui_fast_checkout_page_id';
    const OPT_C2C_PAGE_ID  = 'fcui_card2card_page_id';

    const OPT_SETTINGS     = 'fcui_settings';

    // Default slugs/titles
    const FAST_PAGE_TITLE = 'پرداخت سریع';
    const FAST_PAGE_SLUG  = 'fast-checkout';

    const C2C_PAGE_TITLE  = 'کارت به کارت';
    const C2C_PAGE_SLUG   = 'card-to-card';

    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'bootstrap']);
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
    }

    public static function bootstrap() {
        if (!class_exists('WooCommerce')) return;

        // Gateway
        require_once __DIR__ . '/includes/class-fcui-gateway-card2card.php';
        add_filter('woocommerce_payment_gateways', [__CLASS__, 'register_gateway']);

        // Standalone renders (no theme header/footer)
        add_action('template_redirect', [__CLASS__, 'render_standalone_fast_checkout'], 1);
        add_action('template_redirect', [__CLASS__, 'render_standalone_card2card'], 1);

        // MyAccount redirect_to support
        add_action('template_redirect', [__CLASS__, 'maybe_redirect_from_myaccount'], 0);
        add_filter('woocommerce_login_redirect', [__CLASS__, 'woocommerce_login_redirect'], 10, 2);
        add_filter('woocommerce_registration_redirect', [__CLASS__, 'woocommerce_registration_redirect'], 10, 1);

        // Assets
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // Product buy URL redirect (some themes)
        add_filter('woocommerce_product_add_to_cart_url', [__CLASS__, 'filter_add_to_cart_url'], 10, 2);

        // Minimal checkout fields on our pages
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'filter_checkout_fields'], 20);
        add_filter('woocommerce_cart_needs_shipping', [__CLASS__, 'disable_shipping_on_fast_pages'], 20);
        add_filter('woocommerce_enable_order_notes_field', [__CLASS__, 'disable_order_notes_on_fast_pages'], 20);

        // Admin pages
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
		
		//success page
        add_action('init', function(){

            add_rewrite_rule(
                '^order-success/?$',
                'index.php?fcui_order_success=1',
                'top'
            );
        
        });
        
        add_filter('query_vars', function($vars){
            $vars[] = 'fcui_order_success';
            return $vars;
        });
        
        add_action('template_redirect', function(){
        
            if (get_query_var('fcui_order_success')) {
        
                include plugin_dir_path(__FILE__) . 'templates/order-success-page.php';
                exit;
        
            }
        
        });

        // body class
        add_filter('body_class', [__CLASS__, 'body_class']);
    }

    public static function activate() {
        // Create fast checkout page
        $fast_id = (int) get_option(self::OPT_FAST_PAGE_ID);
        if (!$fast_id || !get_post($fast_id)) {
            $fast_id = wp_insert_post([
                'post_title'   => self::FAST_PAGE_TITLE,
                'post_name'    => self::FAST_PAGE_SLUG,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_content' => '[fcui_fast_checkout]',
            ]);
            if (!is_wp_error($fast_id)) update_option(self::OPT_FAST_PAGE_ID, (int)$fast_id);
        }

        // Create card-to-card page
        $c2c_id = (int) get_option(self::OPT_C2C_PAGE_ID);
        if (!$c2c_id || !get_post($c2c_id)) {
            $c2c_id = wp_insert_post([
                'post_title'   => self::C2C_PAGE_TITLE,
                'post_name'    => self::C2C_PAGE_SLUG,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_content' => '[fcui_card2card]',
            ]);
            if (!is_wp_error($c2c_id)) update_option(self::OPT_C2C_PAGE_ID, (int)$c2c_id);
        }

        // Defaults
        $s = self::get_settings();
        if (empty($s)) {
            self::save_settings(self::default_settings());
        }
    }

    // ========== Settings ==========
    public static function default_settings() {
        return [
            'require_login' => 1,

            // apply mode: all | digital
            'apply_mode' => 'all',

            // card-to-card enabled
            'c2c_enabled' => 1,
            'c2c_card_number' => '0000-0000-0000-0000',
            'c2c_holder_name' => 'نام صاحب کارت',
            'c2c_bank_name'   => 'بانک',
            'c2c_theme'       => 'blue', // blue|red|gold|dark

            'c2c_timer_minutes' => 20,

            // after approval: processing or completed
            'c2c_approved_status' => 'completed',

            // upload limits
            'c2c_max_mb' => 3,
            'success_page_title' => 'پرداخت موفق',
'success_page_online_message' => 'پرداخت شما با موفقیت انجام شد و دسترسی دوره فعال شده است.',
'success_page_c2c_message' => 'رسید شما ثبت شد و پس از بررسی دسترسی فعال خواهد شد.',
'course_access_link' => '',
        ];
    }

    public static function get_settings() {
        $s = get_option(self::OPT_SETTINGS, []);
        if (!is_array($s)) $s = [];
        return wp_parse_args($s, self::default_settings());
    }

    public static function save_settings($s) {
        update_option(self::OPT_SETTINGS, $s);
    }

    // ========== Pages ==========
    public static function is_fast_checkout_page() {
        $id = (int) get_option(self::OPT_FAST_PAGE_ID);
        return $id && is_page($id);
    }

    public static function is_card2card_page() {
        $id = (int) get_option(self::OPT_C2C_PAGE_ID);
        return $id && is_page($id);
    }

    public static function get_fast_checkout_url($args = []) {
        $id = (int) get_option(self::OPT_FAST_PAGE_ID);
        $base = $id ? get_permalink($id) : home_url('/' . self::FAST_PAGE_SLUG . '/');
        return !empty($args) ? add_query_arg($args, $base) : $base;
    }

    public static function get_card2card_url($args = []) {
        $id = (int) get_option(self::OPT_C2C_PAGE_ID);
        $base = $id ? get_permalink($id) : home_url('/' . self::C2C_PAGE_SLUG . '/');
        return !empty($args) ? add_query_arg($args, $base) : $base;
    }

    public static function body_class($classes) {
        if (self::is_fast_checkout_page()) $classes[] = 'fcui-fast-checkout';
        if (self::is_card2card_page()) $classes[] = 'fcui-card2card';
        return $classes;
    }

    // ========== Product targeting ==========
    public static function is_enabled_for_product($product_id) {
        $s = self::get_settings();
        $mode = isset($s['apply_mode']) ? $s['apply_mode'] : 'all';
        if ($mode === 'all') return true;

        // digital mode
        $p = wc_get_product($product_id);
        if (!$p) return false;

        // variable: check children
        if ($p->is_type('variable')) {
            $children = $p->get_children();
            if (is_array($children)) {
                foreach ($children as $cid) {
                    $v = wc_get_product($cid);
                    if ($v && ($v->is_downloadable() || $v->is_virtual())) return true;
                }
            }
            return false;
        }

        return ($p->is_downloadable() || $p->is_virtual());
    }

    // ========== Login handling (MyAccount) ==========
    private static function get_myaccount_url() {
        $my = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
        if (!$my) $my = home_url('/my-account/');
        return $my;
    }

    public static function get_theme_login_url($redirect_url) {
        $my = self::get_myaccount_url();
        return add_query_arg([
            'redirect_to' => $redirect_url,
            'redirect'    => $redirect_url,
        ], $my);
    }

    private static function get_requested_redirect_target() {
        $rt = '';
        if (isset($_REQUEST['redirect_to']) && $_REQUEST['redirect_to'] !== '') {
            $rt = wp_unslash($_REQUEST['redirect_to']);
        } elseif (isset($_REQUEST['redirect']) && $_REQUEST['redirect'] !== '') {
            $rt = wp_unslash($_REQUEST['redirect']);
        }
        $rt = is_string($rt) ? trim($rt) : '';
        if (!$rt) return '';
        $rt = esc_url_raw($rt);
        if (!$rt) return '';
        $fallback = self::get_myaccount_url();
        return wp_validate_redirect($rt, $fallback);
    }

    public static function maybe_redirect_from_myaccount() {
        if (!function_exists('is_account_page') || !is_account_page()) return;
        if (!is_user_logged_in()) return;
        if (!isset($_GET['redirect_to']) && !isset($_GET['redirect'])) return;
        $target = self::get_requested_redirect_target();
        if (!$target) return;
        wp_safe_redirect($target);
        exit;
    }

    public static function woocommerce_login_redirect($redirect, $user) {
        $t = self::get_requested_redirect_target();
        return $t ? $t : $redirect;
    }

    public static function woocommerce_registration_redirect($redirect) {
        $t = self::get_requested_redirect_target();
        return $t ? $t : $redirect;
    }

    // ========== Gateway ==========
    public static function register_gateway($methods) {
        $methods[] = 'FCUI_Gateway_Card2Card';
        return $methods;
    }

    // ========== Assets ==========
    public static function enqueue_assets() {
        $s = self::get_settings();

        if (is_product()) {
            $pid = get_queried_object_id();
            $enabled = self::is_enabled_for_product($pid) ? 1 : 0;

            wp_enqueue_script(
                'fcui-product-redirect',
                plugins_url('assets/product-redirect.js', __FILE__),
                ['jquery'],
                '2.0.0',
                true
            );
            wp_localize_script('fcui-product-redirect', 'FCUI_REDIRECT', [
                'fast_url' => self::get_fast_checkout_url(),
                'enabled_for_product' => $enabled,
                'product_id' => (int)$pid,
            ]);
        }

        if (self::is_fast_checkout_page()) {
            wp_enqueue_style(
                'fcui-fast-checkout',
                plugins_url('assets/fast-checkout.css', __FILE__),
                [],
                '2.0.0'
            );
        }

        if (self::is_card2card_page()) {
            wp_enqueue_style(
                'fcui-card2card',
                plugins_url('assets/card2card.css', __FILE__),
                [],
                '2.0.0'
            );
            wp_enqueue_script(
                'fcui-card2card',
                plugins_url('assets/card2card.js', __FILE__),
                [],
                '2.0.0',
                true
            );
        }
    }

    // ========== Filter add-to-cart URL ==========
    public static function filter_add_to_cart_url($url, $product) {
        if (!is_product()) return $url;
        $pid = $product ? $product->get_id() : 0;
        if (!$pid) return $url;

        if (!self::is_enabled_for_product($pid)) return $url;

        return self::get_fast_checkout_url(['product_id' => $pid]);
    }

    // ========== Checkout fields ==========
    public static function filter_checkout_fields($fields) {
        if (!self::is_fast_checkout_page()) return $fields;

        $keep = ['billing_first_name', 'billing_last_name', 'billing_phone', 'billing_email'];

        if (isset($fields['billing']) && is_array($fields['billing'])) {
            foreach ($fields['billing'] as $key => $field) {
                if (!in_array($key, $keep, true)) unset($fields['billing'][$key]);
            }
            if (isset($fields['billing']['billing_first_name'])) $fields['billing']['billing_first_name']['required'] = true;
            if (isset($fields['billing']['billing_last_name']))  $fields['billing']['billing_last_name']['required']  = true;
            if (isset($fields['billing']['billing_phone']))      $fields['billing']['billing_phone']['required']      = true;
            if (isset($fields['billing']['billing_email']))      $fields['billing']['billing_email']['required']      = false;
        }

        $fields['shipping'] = [];
        return $fields;
    }

    public static function disable_shipping_on_fast_pages($needs_shipping) {
        return (self::is_fast_checkout_page() || self::is_card2card_page()) ? false : $needs_shipping;
    }

    public static function disable_order_notes_on_fast_pages($enabled) {
        return (self::is_fast_checkout_page() || self::is_card2card_page()) ? false : $enabled;
    }

    // ========== Prefill ==========
    private static function get_user_prefill() {
        if (!is_user_logged_in()) {
            return ['full_name'=>'','phone'=>'','email'=>''];
        }

        $user_id = get_current_user_id();
        $user = wp_get_current_user();
        $customer = new WC_Customer($user_id);

        $first = $customer->get_billing_first_name();
        $last  = $customer->get_billing_last_name();
        $full  = trim(($first ? $first : '') . ' ' . ($last ? $last : ''));

        if (!$full) {
            $full = trim((isset($user->first_name) ? $user->first_name : '') . ' ' . (isset($user->last_name) ? $user->last_name : ''));
        }

        $phone = $customer->get_billing_phone();
        if (!$phone) {
            $fallback_keys = ['billing_phone', 'digits_phone', 'mobile', 'user_mobile', 'phone'];
            foreach ($fallback_keys as $k) {
                $val = get_user_meta($user_id, $k, true);
                if (!empty($val)) { $phone = $val; break; }
            }
        }

        $email = $customer->get_billing_email();
        if (!$email && isset($user->user_email)) $email = $user->user_email;

        return [
            'full_name' => $full ? $full : '',
            'phone'     => $phone ? $phone : '',
            'email'     => $email ? $email : '',
        ];
    }

    private static function split_full_name($full_name) {
        $full_name = trim(preg_replace('/\s+/', ' ', (string)$full_name));
        if ($full_name === '') return ['',''];
        $parts = preg_split('/\s+/', $full_name);
        $first = isset($parts[0]) ? $parts[0] : '';
        array_shift($parts);
        $last  = trim(implode(' ', $parts));
        if ($last === '') $last = $first;
        return [$first, $last];
    }

    // ========== Standalone: Fast Checkout ==========
    private static function get_context_from_query() {
        $product_id   = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        $variation_id = isset($_GET['variation_id']) ? absint($_GET['variation_id']) : 0;
        $qty          = isset($_GET['quantity']) ? max(1, absint($_GET['quantity'])) : 1;

        if (!$product_id) return [null, null, [], 0, 0, 1, '', ''];

        if (!self::is_enabled_for_product($product_id)) {
            return [null, null, [], 0, 0, 1, '', 'not_allowed'];
        }

        $display_product = wc_get_product($variation_id ? $variation_id : $product_id);
        if (!$display_product) return [null, null, [], 0, 0, 1, '', ''];

        $parent_product = wc_get_product($product_id);

        $variation = [];
        foreach ($_GET as $k => $v) {
            if (strpos($k, 'attribute_') === 0) {
                $variation[sanitize_text_field($k)] = sanitize_text_field(wp_unslash($v));
            }
        }

        $product_url = get_permalink($product_id);

        return [$display_product, $parent_product, $variation, $product_id, $variation_id, $qty, $product_url, 'ok'];
    }

    private static function enforce_login_or_redirect() {
        $s = self::get_settings();
        $require = !empty($s['require_login']) ? 1 : 0;
        if (!$require) return;

        if (!is_user_logged_in()) {
            $redirect = self::is_fast_checkout_page()
                ? self::get_fast_checkout_url($_GET)
                : self::get_card2card_url($_GET);

            wp_safe_redirect(self::get_theme_login_url($redirect));
            exit;
        }
    }

    public static function render_standalone_fast_checkout() {
        if (!self::is_fast_checkout_page()) return;

        self::enforce_login_or_redirect();

        // Handle POST actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::handle_fast_checkout_post();
            // handler exits
        }

        list($product, $parent_product, $variation, $product_id, $variation_id, $qty, $product_url, $state) = self::get_context_from_query();

        nocache_headers();

        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $prefill  = self::get_user_prefill();

        $s = self::get_settings();

        status_header($product ? 200 : 404);

        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php if (function_exists('wp_body_open')) wp_body_open(); ?>

<?php
        if ($state === 'not_allowed') {
            echo '<div style="max-width:680px;margin:40px auto;padding:0 14px;">این محصول در حالت پرداخت سریع فعال نیست.</div>';
        } elseif (!$product) {
            echo '<div style="max-width:680px;margin:40px auto;padding:0 14px;">محصول مشخص نیست.</div>';
        } else {
            include __DIR__ . '/templates/fast-checkout-page.php';
        }
?>

<?php wp_footer(); ?>
</body>
</html>
        <?php
        exit;
    }

    private static function handle_fast_checkout_post() {
        self::enforce_login_or_redirect();

        if (!isset($_POST['fcui_nonce']) || !wp_verify_nonce($_POST['fcui_nonce'], 'fcui_fast_checkout')) {
            wp_safe_redirect(self::get_fast_checkout_url($_GET));
            exit;
        }

        $product_id   = absint($_POST['product_id'] ?? 0);
        $variation_id = absint($_POST['variation_id'] ?? 0);
        $qty          = max(1, absint($_POST['quantity'] ?? 1));
        $product = wc_get_product($product_id);

if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
    wp_safe_redirect(self::get_fast_checkout_url([
        'product_id' => $product_id,
        'err' => 'outofstock'
    ]));
    exit;
}


        if (!$product_id || !self::is_enabled_for_product($product_id)) {
            wp_safe_redirect(self::get_fast_checkout_url());
            exit;
        }

        $full_name = sanitize_text_field($_POST['billing_full_name'] ?? '');
        $phone     = sanitize_text_field($_POST['billing_phone'] ?? '');
        $email     = sanitize_email($_POST['billing_email'] ?? '');

        if (!$full_name || !$phone) {
            wp_safe_redirect(self::get_fast_checkout_url(['product_id' => $product_id, 'err' => 1]));
            exit;
        }

        list($first, $last) = self::split_full_name($full_name);

        if (!$email) {
            $domain = parse_url(home_url(), PHP_URL_HOST);
            $email = preg_replace('/\D+/', '', $phone) . '@' . $domain;
        }

        $variation = [];
        foreach ($_POST as $k => $v) {
            if (strpos($k, 'attribute_') === 0) {
                $variation[sanitize_text_field($k)] = sanitize_text_field(wp_unslash($v));
            }
        }

        if (function_exists('wc_load_cart')) wc_load_cart();
        if (!WC()->cart) {
            wp_safe_redirect(self::get_fast_checkout_url(['product_id' => $product_id, 'err' => 1]));
            exit;
        }

        // Pay later => add to cart & go cart
        if (isset($_POST['fcui_add_to_cart_later'])) {
            WC()->cart->add_to_cart($product_id, $qty, $variation_id, $variation);
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }
// Free register
if (isset($_POST['fcui_free_register'])) {

    $user_id = get_current_user_id();

    if (!$user_id) {

        $username = sanitize_user($phone);
        $password = wp_generate_password();

        $user_id = wp_create_user($username, $password, $email);

        if (!is_wp_error($user_id)) {

            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id);
        }
    }

    $order = wc_create_order();

    $order->add_product($product, $qty);

    $order->set_address([
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'email'      => $email,
        'phone'      => $phone,
    ], 'billing');

    $order->set_customer_id($user_id);

    $order->calculate_totals();

    $order->update_status('completed');

    wp_safe_redirect(
        add_query_arg([
            'order_id' => $order->get_id(),
            'key'      => $order->get_order_key(),
            'type'     => 'online'
        ], home_url('/order-success/'))
    );

    exit;
}

        // Pay now
        $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');
        if (!$payment_method) {
            wp_safe_redirect(self::get_fast_checkout_url(['product_id' => $product_id, 'err' => 1]));
            exit;
        }

        // Fill user meta
        $uid = get_current_user_id();
        if ($uid) {
            update_user_meta($uid, 'billing_first_name', $first);
            update_user_meta($uid, 'billing_last_name', $last);
            update_user_meta($uid, 'billing_phone', $phone);
            update_user_meta($uid, 'billing_email', $email);
        }

        // Build cart single-item for payment
        WC()->cart->empty_cart();
        $added = WC()->cart->add_to_cart($product_id, $qty, $variation_id, $variation);
        if (!$added) {
            wp_safe_redirect(self::get_fast_checkout_url(['product_id' => $product_id, 'err' => 1]));
            exit;
        }

        // If card2card selected => create order and redirect to c2c page
        if ($payment_method === 'fcui_card2card') {
            $order_id = self::create_order_for_card2card($first, $last, $phone, $email, $payment_method);
            if (!$order_id) {
                wp_safe_redirect(self::get_fast_checkout_url(['product_id' => $product_id, 'err' => 1]));
                exit;
            }
            $order = wc_get_order($order_id);
            $key = $order ? $order->get_order_key() : '';
            $url = self::get_card2card_url(['order_id' => $order_id, 'key' => $key]);
            wp_safe_redirect($url);
            exit;
        }

        // Normal gateways: run Woo checkout
        wc_maybe_define_constant('WOOCOMMERCE_CHECKOUT', true);

        $_POST['billing_first_name'] = $first;
        $_POST['billing_last_name']  = $last;
        $_POST['billing_phone']      = $phone;
        $_POST['billing_email']      = $email;
        $_POST['payment_method']     = $payment_method;
        $_POST['terms']              = 1;
        $_POST['ship_to_different_address'] = 0;

        if (!isset($_POST['woocommerce-process-checkout-nonce'])) {
            $_POST['woocommerce-process-checkout-nonce'] = wp_create_nonce('woocommerce-process_checkout');
        }

        WC()->checkout()->process_checkout();
        exit;
    }

    private static function create_order_for_card2card($first, $last, $phone, $email, $payment_method) {
        try {
            wc_maybe_define_constant('WOOCOMMERCE_CHECKOUT', true);

            // Create order from cart
            $checkout = WC()->checkout();
            $order_id = $checkout->create_order([
                'billing_first_name' => $first,
                'billing_last_name'  => $last,
                'billing_phone'      => $phone,
                'billing_email'      => $email,
            ]);

            if (is_wp_error($order_id) || !$order_id) return 0;

            $order = wc_get_order($order_id);
            if (!$order) return 0;

            $order->set_payment_method($payment_method);

            // timer
            $s = self::get_settings();
            $mins = max(1, (int)$s['c2c_timer_minutes']);
            $expires = time() + ($mins * 60);
            $order->update_meta_data('_fcui_c2c_expires_at', $expires);

            $order->update_status('on-hold', 'در انتظار پرداخت کارت به کارت و بررسی رسید');
            $order->save();

            WC()->cart->empty_cart();

            return (int)$order_id;

        } catch (Throwable $e) {
            return 0;
        }
    }

    // ========== Standalone: Card-to-Card page ==========
    public static function render_standalone_card2card() {
        if (!self::is_card2card_page()) return;

        self::enforce_login_or_redirect();

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key      = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';

        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order || !$key || $order->get_order_key() !== $key) {
            status_header(404);
            nocache_headers();
            echo 'سفارش معتبر نیست.';
            exit;
        }

        // Ensure owner
        $uid = get_current_user_id();
        if ($order->get_user_id() && $uid && (int)$order->get_user_id() !== (int)$uid) {
            status_header(403);
            nocache_headers();
            echo 'دسترسی غیرمجاز';
            exit;
        }

        // Handle upload submit
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::handle_card2card_upload($order);
            // handler exits
        }

        $s = self::get_settings();
        $theme = sanitize_key($s['c2c_theme']);
        $expires_at = (int) $order->get_meta('_fcui_c2c_expires_at');
        if (!$expires_at) {
            $mins = max(1, (int)$s['c2c_timer_minutes']);
            $expires_at = time() + ($mins*60);
            $order->update_meta_data('_fcui_c2c_expires_at', $expires_at);
            $order->save();
        }

        $receipt1 = (string) $order->get_meta('_fcui_c2c_receipt_1');
        $receipt2 = (string) $order->get_meta('_fcui_c2c_receipt_2');

        nocache_headers();
        status_header(200);
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <?php wp_head(); ?>
</head>
<body <?php body_class('fcui-c2c-theme-' . esc_attr($theme)); ?>>
<?php if (function_exists('wp_body_open')) wp_body_open(); ?>

<?php include __DIR__ . '/templates/card2card-page.php'; ?>

<?php wp_footer(); ?>
</body>
</html>
        <?php
        exit;
    }

    private static function handle_card2card_upload($order) {
        if (!isset($_POST['fcui_nonce']) || !wp_verify_nonce($_POST['fcui_nonce'], 'fcui_card2card')) {
            wp_safe_redirect(self::get_card2card_url(['order_id'=>$order->get_id(),'key'=>$order->get_order_key(),'err'=>1]));
            exit;
        }

        $expires_at = (int) $order->get_meta('_fcui_c2c_expires_at');
        if ($expires_at && time() > $expires_at) {
            wp_safe_redirect(self::get_card2card_url(['order_id'=>$order->get_id(),'key'=>$order->get_order_key(),'expired'=>1]));
            exit;
        }

        // receipt 1 is required
        if (empty($_FILES['receipt_1']['name'])) {
            wp_safe_redirect(self::get_card2card_url(['order_id'=>$order->get_id(),'key'=>$order->get_order_key(),'err'=>2]));
            exit;
        }

        $s = self::get_settings();
        $max_mb = max(1, (int)$s['c2c_max_mb']);
        $max_bytes = $max_mb * 1024 * 1024;

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $mimes = [
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
            'webp'     => 'image/webp',
            'pdf'      => 'application/pdf',
        ];

        $urls = [];

        foreach (['receipt_1', 'receipt_2'] as $field) {
            if (empty($_FILES[$field]['name'])) continue;

            if (!empty($_FILES[$field]['size']) && (int)$_FILES[$field]['size'] > $max_bytes) {
                wp_safe_redirect(self::get_card2card_url(['order_id'=>$order->get_id(),'key'=>$order->get_order_key(),'err'=>3]));
                exit;
            }

            $upload = wp_handle_upload($_FILES[$field], [
                'test_form' => false,
                'mimes'     => $mimes,
            ]);

            if (!empty($upload['error'])) {
                wp_safe_redirect(self::get_card2card_url(['order_id'=>$order->get_id(),'key'=>$order->get_order_key(),'err'=>4]));
                exit;
            }

            if (!empty($upload['url'])) {
                $urls[$field] = esc_url_raw($upload['url']);
            }
        }

        if (empty($urls['receipt_1'])) {
            wp_safe_redirect(self::get_card2card_url(['order_id'=>$order->get_id(),'key'=>$order->get_order_key(),'err'=>4]));
            exit;
        }

        $order->update_meta_data('_fcui_c2c_receipt_1', $urls['receipt_1']);
        if (!empty($urls['receipt_2'])) {
            $order->update_meta_data('_fcui_c2c_receipt_2', $urls['receipt_2']);
        }

        $order->add_order_note('رسید کارت به کارت توسط کاربر آپلود شد.');
        // Keep on-hold until admin approval
        $order->update_status('on-hold');
        $order->save();

        wp_safe_redirect(
            home_url('/order-success/?order_id=' . $order->get_id() . '&key=' . $order->get_order_key() . '&type=c2c')
        );
        exit;
    }

    // ========== Admin ==========
    public static function admin_menu() {
        add_submenu_page(
            'woocommerce',
            'تنظیمات پرداخت سریع ',
            'تنظیمات پرداخت سریع ',
            'manage_woocommerce',
            'fcui-fast-checkout',
            [__CLASS__, 'admin_settings_page']
        );

        add_submenu_page(
            'woocommerce',
            'سفارش های کارت به کارت',
            'سفارش های کارت به کارت',
            'manage_woocommerce',
            'fcui-card2card-orders',
            [__CLASS__, 'admin_card2card_orders_page']
        );
    }

    public static function admin_settings_page() {

        if (!current_user_can('manage_woocommerce')) return;
    
        $s = self::get_settings();
    
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fcui_save_settings'])) {
    
            check_admin_referer('fcui_save_settings');
    
            $s['require_login'] = !empty($_POST['require_login']) ? 1 : 0;
    
            $apply = sanitize_key($_POST['apply_mode'] ?? 'all');
            $s['apply_mode'] = in_array($apply, ['all','digital'], true) ? $apply : 'all';
    
            $s['c2c_enabled'] = !empty($_POST['c2c_enabled']) ? 1 : 0;
    
            $s['c2c_card_number'] = sanitize_text_field($_POST['c2c_card_number'] ?? '');
    
            $s['c2c_holder_name'] = sanitize_text_field($_POST['c2c_holder_name'] ?? '');
    
            $s['c2c_bank_name'] = sanitize_text_field($_POST['c2c_bank_name'] ?? '');
    
            $theme = sanitize_key($_POST['c2c_theme'] ?? 'blue');
    
            $s['c2c_theme'] = in_array($theme, ['blue','red','gold','dark'], true)
                ? $theme
                : 'blue';
    
            $s['c2c_timer_minutes'] = max(
                1,
                (int)($_POST['c2c_timer_minutes'] ?? 20)
            );
    
            $st = sanitize_key($_POST['c2c_approved_status'] ?? 'completed');
    
            $s['c2c_approved_status'] = in_array(
                $st,
                ['processing','completed'],
                true
            ) ? $st : 'completed';
    
            $s['c2c_max_mb'] = max(
                1,
                (int)($_POST['c2c_max_mb'] ?? 3)
            );
    
            // Success page settings
            $s['success_page_title'] = sanitize_text_field(
                $_POST['success_page_title'] ?? ''
            );
    
            $s['success_page_online_message'] = sanitize_textarea_field(
                $_POST['success_page_online_message'] ?? ''
            );
    
            $s['success_page_c2c_message'] = sanitize_textarea_field(
                $_POST['success_page_c2c_message'] ?? ''
            );
    
            $s['course_access_link'] = esc_url_raw(
                $_POST['course_access_link'] ?? ''
            );
    
            self::save_settings($s);
    
            echo '<div class="updated"><p>تنظیمات ذخیره شد.</p></div>';
        }
    
        ?>
    
        <div class="wrap">
    
            <h1>FCUI پرداخت سریع - تنظیمات</h1>
    
            <form method="post">
    
                <?php wp_nonce_field('fcui_save_settings'); ?>
    
                <table class="form-table" role="presentation">
    
                    <tr>
                        <th scope="row">اجبار به لاگین</th>
    
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="require_login"
                                    value="1"
                                    <?php checked(1, (int)$s['require_login']); ?>
                                >
    
                                فقط کاربران وارد شده بتوانند پرداخت سریع را ببینند
                            </label>
                        </td>
                    </tr>
    
                    <tr>
                        <th scope="row">اعمال افزونه روی محصولات</th>
    
                        <td>
    
                            <label>
                                <input
                                    type="radio"
                                    name="apply_mode"
                                    value="all"
                                    <?php checked('all', $s['apply_mode']); ?>
                                >
                                همه محصولات
                            </label>
    
                            <br>
    
                            <label>
                                <input
                                    type="radio"
                                    name="apply_mode"
                                    value="digital"
                                    <?php checked('digital', $s['apply_mode']); ?>
                                >
                                فقط محصولات دیجیتال
                            </label>
    
                        </td>
                    </tr>
    
                    <tr>
                        <th colspan="2">
                            <h2>کارت به کارت</h2>
                        </th>
                    </tr>
    
                    <tr>
                        <th scope="row">فعال</th>
    
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="c2c_enabled"
                                    value="1"
                                    <?php checked(1, (int)$s['c2c_enabled']); ?>
                                >
    
                                روش کارت به کارت فعال باشد
                            </label>
                        </td>
                    </tr>
    
                    <tr>
                        <th scope="row">شماره کارت</th>
    
                        <td>
                            <input
                                type="text"
                                name="c2c_card_number"
                                value="<?php echo esc_attr($s['c2c_card_number']); ?>"
                                class="regular-text"
                            >
                        </td>
                    </tr>
    
                    <tr>
                        <th scope="row">نام صاحب کارت</th>
    
                        <td>
                            <input
                                type="text"
                                name="c2c_holder_name"
                                value="<?php echo esc_attr($s['c2c_holder_name']); ?>"
                                class="regular-text"
                            >
                        </td>
                    </tr>
    
                    <tr>
                        <th scope="row">نام بانک</th>
    
                        <td>
                            <input
                                type="text"
                                name="c2c_bank_name"
                                value="<?php echo esc_attr($s['c2c_bank_name']); ?>"
                                class="regular-text"
                            >
                        </td>
                    </tr>
    
                    <tr>
                        <th scope="row">تم کارت</th>
    
                        <td>
    
                            <select name="c2c_theme">
    
                                <option value="blue" <?php selected('blue', $s['c2c_theme']); ?>>
                                    آبی
                                </option>
    
                                <option value="red" <?php selected('red', $s['c2c_theme']); ?>>
                                    قرمز
                                </option>
    
                                <option value="gold" <?php selected('gold', $s['c2c_theme']); ?>>
                                    طلایی
                                </option>
    
                                <option value="dark" <?php selected('dark', $s['c2c_theme']); ?>>
                                    مشکی
                                </option>
    
                            </select>
    
                        </td>
                    </tr>
    
                    <tr>
                        <th scope="row">تایمر پرداخت</th>
    
                        <td>
                            <input
                                type="number"
                                name="c2c_timer_minutes"
                                value="<?php echo (int)$s['c2c_timer_minutes']; ?>"
                                min="1"
                            >
                        </td>
                    </tr>
    
                    <tr>
                        <th scope="row">حداکثر حجم رسید</th>
    
                        <td>
                            <input
                                type="number"
                                name="c2c_max_mb"
                                value="<?php echo (int)$s['c2c_max_mb']; ?>"
                                min="1"
                            >
                        </td>
                    </tr>
    
                    <tr>
                        <th scope="row">وضعیت سفارش بعد از تأیید</th>
    
                        <td>
    
                            <select name="c2c_approved_status">
    
                                <option value="processing" <?php selected('processing', $s['c2c_approved_status']); ?>>
                                    Processing
                                </option>
    
                                <option value="completed" <?php selected('completed', $s['c2c_approved_status']); ?>>
                                    Completed
                                </option>
    
                            </select>
    
                        </td>
                    </tr>
    
                    <tr>
                        <th colspan="2">
                            <h2>صفحه موفقیت پرداخت</h2>
                        </th>
                    </tr>
    
                    <tr>
                        <th scope="row">عنوان صفحه موفقیت</th>
    
                        <td>
                            <input
                                type="text"
                                name="success_page_title"
                                value="<?php echo esc_attr($s['success_page_title']); ?>"
                                class="regular-text"
                            >
                        </td>
                    </tr>
    
                    <tr>
                        <th scope="row">متن پرداخت آنلاین</th>
    
                        <td>
                            <textarea
                                name="success_page_online_message"
                                rows="3"
                                class="large-text"
                            ><?php echo esc_textarea($s['success_page_online_message']); ?></textarea>
                        </td>
                    </tr>
    
                    <tr>
                        <th scope="row">متن کارت به کارت</th>
    
                        <td>
                            <textarea
                                name="success_page_c2c_message"
                                rows="3"
                                class="large-text"
                            ><?php echo esc_textarea($s['success_page_c2c_message']); ?></textarea>
                        </td>
                    </tr>
    
                    <tr>
                        <th scope="row">لینک ورود به دوره</th>
    
                        <td>
    
                            <input
                                type="url"
                                name="course_access_link"
                                value="<?php echo esc_attr($s['course_access_link']); ?>"
                                class="regular-text"
                            >
    
                            <p class="description">
                                اگر خالی باشد کاربر به صفحه حساب کاربری هدایت می‌شود.
                            </p>
    
                        </td>
                    </tr>
    
                </table>
    
                <p>
                    <button
                        class="button button-primary"
                        name="fcui_save_settings"
                        value="1"
                    >
                        ذخیره تنظیمات
                    </button>
                </p>
    
            </form>
    
        </div>
    
        <?php
    }
    

    public static function admin_card2card_orders_page() {
        if (!current_user_can('manage_woocommerce')) return;

        $s = self::get_settings();

        // Approve action
        if (isset($_GET['fcui_approve']) && isset($_GET['_wpnonce'])) {
            $oid = absint($_GET['fcui_approve']);
            if (wp_verify_nonce($_GET['_wpnonce'], 'fcui_approve_' . $oid)) {
                $order = wc_get_order($oid);
                if ($order && $order->get_payment_method() === 'fcui_card2card') {
                    $status = $s['c2c_approved_status'] === 'processing' ? 'processing' : 'completed';
                    $order->update_status($status, 'تأیید کارت به کارت توسط مدیر');
                    $order->save();
                    echo '<div class="updated"><p>سفارش تأیید شد.</p></div>';
                }
            }
        }
        // Reject action
if (isset($_GET['fcui_reject']) && isset($_GET['_wpnonce'])) {
    $oid = absint($_GET['fcui_reject']);
    if (wp_verify_nonce($_GET['_wpnonce'], 'fcui_reject_' . $oid)) {
        $order = wc_get_order($oid);
        if ($order && $order->get_payment_method() === 'fcui_card2card') {
            $order->update_status('cancelled', 'پرداخت کارت به کارت توسط مدیر رد شد');
            $order->save();
            echo '<div class="updated"><p>سفارش رد شد.</p></div>';
        }
    }
}

        // List on-hold card2card orders
        $args = [
            'limit'        => 50,
            'status'       => ['on-hold'],
            'payment_method' => 'fcui_card2card',
            'orderby'      => 'date',
            'order'        => 'DESC',
            'return'       => 'objects',
        ];
        $orders = wc_get_orders($args);

        ?>
        <div class="wrap">
          <h1>سفارش‌های کارت به کارت (در انتظار تأیید)</h1>

          <table class="widefat striped">
            <thead>
              <tr>
                <th>شماره سفارش</th>
                <th>مشتری</th>
                <th>مبلغ</th>
                <th>رسید ۱</th>
                <th>رسید ۲</th>
                <th>اقدام</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($orders)): ?>
                <tr><td colspan="6">سفارشی یافت نشد.</td></tr>
              <?php else: ?>
                <?php foreach ($orders as $order): ?>
                  <?php
                    $r1 = (string) $order->get_meta('_fcui_c2c_receipt_1');
                    $r2 = (string) $order->get_meta('_fcui_c2c_receipt_2');
                    $approve_url = wp_nonce_url(
                        admin_url('admin.php?page=fcui-card2card-orders&fcui_approve=' . $order->get_id()),
                        'fcui_approve_' . $order->get_id()
                    );
                    $reject_url = wp_nonce_url(
                        admin_url('admin.php?page=fcui-card2card-orders&fcui_reject=' . $order->get_id()),
                        'fcui_reject_' . $order->get_id()
                    );
                  ?>
                  <tr>
                    <td>#<?php echo (int)$order->get_id(); ?></td>
                    <td><?php echo esc_html($order->get_formatted_billing_full_name()); ?></td>
                    <td><?php echo wp_kses_post(wc_price($order->get_total())); ?></td>
                    <td><?php echo $r1 ? '<a target="_blank" href="'.esc_url($r1).'">مشاهده</a>' : '—'; ?></td>
                    <td><?php echo $r2 ? '<a target="_blank" href="'.esc_url($r2).'">مشاهده</a>' : '—'; ?></td>
                    <td>
                      <a class="button button-primary" href="<?php echo esc_url($approve_url); ?>">تأیید</a>
                      <a class="button button-secondary fcui-reject-order" href="<?php echo esc_url($reject_url); ?>">رد</a>
                      <a class="button" href="<?php echo esc_url(get_edit_post_link($order->get_id())); ?>">جزئیات</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php
    }
}

FCUI_Fast_Checkout::init();

// Shortcodes exist only to create pages; output handled by standalone render.
add_shortcode('fcui_fast_checkout', function(){ return ''; });
add_shortcode('fcui_card2card', function(){ return ''; });
add_filter('woocommerce_get_return_url', function($url, $order){

    if (!$order) {
        return $url;
    }

    return home_url(
        '/order-success/?order_id=' .
        $order->get_id() .
        '&key=' .
        $order->get_order_key() .
        '&type=online'
    );

}, 10, 2);
