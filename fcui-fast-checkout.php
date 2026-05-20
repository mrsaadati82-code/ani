<?php
/**
 * Plugin Name: پرداخت سریع - سلام وردپرس
 * Description: این پلاگین صفحه ی پرداخت سریع را جایگزین فرایند سفارش ووکامرس میکند. نسخه پیشرفته با پشتیبانی محصولات فیزیکی، کد تخفیف و پنل مدیریت حرفه‌ای
 * Version: 2.1.0
 * Author: امیرحسین سعادتی
 * Text Domain: fcui
 */

if (!defined('ABSPATH')) exit;

final class FCUI_Fast_Checkout {

    const OPT_FAST_PAGE_ID = 'fcui_fast_checkout_page_id';
    const OPT_C2C_PAGE_ID  = 'fcui_card2card_page_id';
    const OPT_SETTINGS     = 'fcui_settings';
    const VERSION = '2.1.0';

    const FAST_PAGE_TITLE = 'پرداخت سریع';
    const FAST_PAGE_SLUG  = 'fast-checkout';
    const C2C_PAGE_TITLE  = 'کارت به کارت';
    const C2C_PAGE_SLUG   = 'card-to-card';

    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'bootstrap']);
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
    }

    public static function bootstrap() {
        if (!class_exists('WooCommerce')) return;

        require_once __DIR__ . '/includes/class-fcui-gateway-card2card.php';
        add_filter('woocommerce_payment_gateways', [__CLASS__, 'register_gateway']);

        add_action('template_redirect', [__CLASS__, 'render_standalone_fast_checkout'], 1);
        add_action('template_redirect', [__CLASS__, 'render_standalone_card2card'], 1);
        add_action('template_redirect', [__CLASS__, 'maybe_redirect_from_myaccount'], 0);
        
        add_filter('woocommerce_login_redirect', [__CLASS__, 'woocommerce_login_redirect'], 10, 2);
        add_filter('woocommerce_registration_redirect', [__CLASS__, 'woocommerce_registration_redirect'], 10, 1);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_head', [__CLASS__, 'output_dynamic_styles']);

        add_filter('woocommerce_product_add_to_cart_url', [__CLASS__, 'filter_add_to_cart_url'], 10, 2);
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'filter_checkout_fields'], 20);
        add_filter('woocommerce_cart_needs_shipping', [__CLASS__, 'disable_shipping_on_fast_pages'], 20);
        add_filter('woocommerce_enable_order_notes_field', [__CLASS__, 'disable_order_notes_on_fast_pages'], 20);

        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        
        add_action('init', function(){
            add_rewrite_rule('^order-success/?$', 'index.php?fcui_order_success=1', 'top');
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

        add_filter('body_class', [__CLASS__, 'body_class']);
        
        // Save custom fields
        add_action('woocommerce_checkout_update_order_meta', [__CLASS__, 'save_custom_fields']);
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'display_custom_fields_admin']);
    }

    public static function activate() {
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

        $s = self::get_settings();
        if (empty($s['version']) || version_compare($s['version'], self::VERSION, '<')) {
            self::save_settings(wp_parse_args($s, self::default_settings()));
        }
        
        flush_rewrite_rules();
    }

    public static function default_settings() {
        return [
            'version' => self::VERSION,
            'require_login' => 1,
            'apply_mode' => 'all',
            
            // ظاهر
            'primary_color' => '#1e6bff',
            'secondary_color' => '#0f172a',
            'background_color' => '#f5f8ff',
            'card_radius' => '16',
            'button_radius' => '16',
            
            // متون
            'button_pay_text' => 'پرداخت و ثبت‌نام آنی',
            'button_later_text' => 'بعداً پرداخت می‌کنم',
            'button_free_text' => 'ثبت‌نام رایگان',
            'hint_text' => 'بعد از پرداخت موفق، دسترسی شما همان لحظه فعال می‌شود.',
            
            // کد تخفیف
            'coupon_enabled' => 1,
            'coupon_label' => 'کد تخفیف دارید؟',
            'coupon_placeholder' => 'کد را وارد کنید',
            
            // کارت به کارت
            'c2c_enabled' => 1,
            'c2c_card_number' => '6037-9911-1234-5678',
            'c2c_holder_name' => 'امیرحسین سعادتی',
            'c2c_bank_name'   => 'بانک ملی',
            'c2c_theme'       => 'blue',
            'c2c_timer_minutes' => 20,
            'c2c_approved_status' => 'completed',
            'c2c_max_mb' => 3,
            
            // محصولات فیزیکی
            'physical_enabled' => 1,
            'physical_fields' => ['billing_state','billing_city','billing_address_1','billing_postcode'],
            'enable_national_code' => 1,
            'national_code_required' => 0,
            'national_code_label' => 'کد ملی',
            
            // فیلدهای سفارشی
            'custom_fields' => [],
            
            // صفحه موفقیت
            'success_page_title' => 'پرداخت با موفقیت انجام شد',
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
        $s['version'] = self::VERSION;
        update_option(self::OPT_SETTINGS, $s);
    }

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

    public static function is_enabled_for_product($product_id) {
        $s = self::get_settings();
        $mode = isset($s['apply_mode']) ? $s['apply_mode'] : 'all';
        if ($mode === 'all') return true;

        $p = wc_get_product($product_id);
        if (!$p) return false;

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
    
    public static function is_physical_product($product) {
        if (!$product) return false;
        $s = self::get_settings();
        if (empty($s['physical_enabled'])) return false;
        return !$product->is_virtual() && !$product->is_downloadable();
    }

    private static function get_myaccount_url() {
        $my = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
        if (!$my) $my = home_url('/my-account/');
        return $my;
    }

    public static function get_theme_login_url($redirect_url) {
        $my = self::get_myaccount_url();
        return add_query_arg(['redirect_to' => $redirect_url, 'redirect' => $redirect_url], $my);
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

    public static function register_gateway($methods) {
        $methods[] = 'FCUI_Gateway_Card2Card';
        return $methods;
    }

    public static function enqueue_assets() {
        $s = self::get_settings();

        if (is_product()) {
            $pid = get_queried_object_id();
            $enabled = self::is_enabled_for_product($pid) ? 1 : 0;
            wp_enqueue_script('fcui-product-redirect', plugins_url('assets/product-redirect.js', __FILE__), ['jquery'], self::VERSION, true);
            wp_localize_script('fcui-product-redirect', 'FCUI_REDIRECT', [
                'fast_url' => self::get_fast_checkout_url(),
                'enabled_for_product' => $enabled,
                'product_id' => (int)$pid,
            ]);
        }

        if (self::is_fast_checkout_page()) {
            wp_enqueue_style('fcui-fast-checkout', plugins_url('assets/fast-checkout.css', __FILE__), [], self::VERSION);
            wp_enqueue_script('fcui-checkout', plugins_url('assets/fast-checkout.js', __FILE__), ['jquery'], self::VERSION, true);
        }

        if (self::is_card2card_page()) {
            wp_enqueue_style('fcui-card2card', plugins_url('assets/card2card.css', __FILE__), [], self::VERSION);
            wp_enqueue_script('fcui-card2card', plugins_url('assets/card2card.js', __FILE__), [], self::VERSION, true);
        }
    }
    
    public static function output_dynamic_styles() {
        if (!self::is_fast_checkout_page() && !self::is_card2card_page() && !get_query_var('fcui_order_success')) return;
        
        $s = self::get_settings();
        ?>
        <style id="fcui-dynamic">
            :root{
                --p: <?php echo esc_attr($s['primary_color']); ?>;
                --s: <?php echo esc_attr($s['secondary_color']); ?>;
                --bg: <?php echo esc_attr($s['background_color']); ?>;
                --r: <?php echo (int)$s['card_radius']; ?>px;
                --br: <?php echo (int)$s['button_radius']; ?>px;
            }
            body.fcui-fast-checkout, body.fcui-card2card { background: var(--bg) !important; }
            .fcui__btn--primary, .fcui-c2c__submit { 
                background: linear-gradient(135deg, var(--p) 0%, color-mix(in srgb, var(--p) 80%, black) 100%) !important;
                border-radius: var(--br) !important;
            }
            .fcui__card, .fcui__summary, .fcui-c2c__upload { border-radius: var(--r) !important; }
            .fcui__step { background: color-mix(in srgb, var(--p) 15%, white) !important; color: var(--p) !important; }
        </style>
        <?php
    }

    public static function admin_assets($hook) {
        if (strpos($hook, 'fcui') === false) return;
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_media();
    }

    public static function filter_add_to_cart_url($url, $product) {
        if (!is_product()) return $url;
        $pid = $product ? $product->get_id() : 0;
        if (!$pid) return $url;
        if (!self::is_enabled_for_product($pid)) return $url;
        return self::get_fast_checkout_url(['product_id' => $pid]);
    }

    public static function filter_checkout_fields($fields) {
        if (!self::is_fast_checkout_page()) return $fields;

        $product_id = isset($_REQUEST['product_id']) ? absint($_REQUEST['product_id']) : 0;
        $product = $product_id ? wc_get_product($product_id) : null;
        $is_physical = self::is_physical_product($product);
        $s = self::get_settings();

        if ($is_physical) {
            // محصولات فیزیکی - فیلدهای انتخابی
            $keep = ['billing_first_name', 'billing_last_name', 'billing_phone', 'billing_email'];
            if (!empty($s['physical_fields'])) {
                $keep = array_merge($keep, $s['physical_fields']);
            }
            
            if (isset($fields['billing'])) {
                foreach ($fields['billing'] as $key => $field) {
                    if (!in_array($key, $keep, true)) {
                        unset($fields['billing'][$key]);
                    }
                }
            }
            
            // کد ملی
            if (!empty($s['enable_national_code'])) {
                $fields['billing']['billing_national_code'] = [
                    'label' => $s['national_code_label'],
                    'required' => !empty($s['national_code_required']),
                    'priority' => 35,
                    'class' => ['form-row-first'],
                ];
            }
            
            // فیلدهای سفارشی
            if (!empty($s['custom_fields'])) {
                foreach ($s['custom_fields'] as $cf) {
                    if (empty($cf['key']) || empty($cf['active'])) continue;
                    $fields['billing']['billing_'.$cf['key']] = [
                        'label' => $cf['label'],
                        'required' => !empty($cf['required']),
                        'priority' => 100 + (int)$cf['order'],
                        'class' => ['form-row-wide'],
                    ];
                }
            }
            
            return $fields;
        } else {
            // محصولات دیجیتال - مینیمال
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
    }

    public static function disable_shipping_on_fast_pages($needs_shipping) {
        if (!self::is_fast_checkout_page()) return $needs_shipping;
        $product_id = isset($_REQUEST['product_id']) ? absint($_REQUEST['product_id']) : 0;
        $product = $product_id ? wc_get_product($product_id) : null;
        return self::is_physical_product($product) ? true : false;
    }

    public static function disable_order_notes_on_fast_pages($enabled) {
        return (self::is_fast_checkout_page() || self::is_card2card_page()) ? false : $enabled;
    }

    private static function get_user_prefill() {
        if (!is_user_logged_in()) {
            return ['full_name'=>'','phone'=>'','email'=>'','address'=>'','city'=>'','postcode'=>'','state'=>''];
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
            foreach (['billing_phone', 'digits_phone', 'mobile', 'user_mobile', 'phone'] as $k) {
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
            'address'   => $customer->get_billing_address_1(),
            'city'      => $customer->get_billing_city(),
            'postcode'  => $customer->get_billing_postcode(),
            'state'     => $customer->get_billing_state(),
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

    private static function get_context_from_query() {
        $product_id   = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        $variation_id = isset($_GET['variation_id']) ? absint($_GET['variation_id']) : 0;
        $qty          = isset($_GET['quantity']) ? max(1, absint($_GET['quantity'])) : 1;

        if (!$product_id) return [null, null, [], 0, 0, 1, '', '', false];

        if (!self::is_enabled_for_product($product_id)) {
            return [null, null, [], 0, 0, 1, '', 'not_allowed', false];
        }

        $display_product = wc_get_product($variation_id ? $variation_id : $product_id);
        if (!$display_product) return [null, null, [], 0, 0, 1, '', '', false];

        $parent_product = wc_get_product($product_id);
        $is_physical = self::is_physical_product($display_product);

        $variation = [];
        foreach ($_GET as $k => $v) {
            if (strpos($k, 'attribute_') === 0) {
                $variation[sanitize_text_field($k)] = sanitize_text_field(wp_unslash($v));
            }
        }

        $product_url = get_permalink($product_id);

        return [$display_product, $parent_product, $variation, $product_id, $variation_id, $qty, $product_url, 'ok', $is_physical];
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

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::handle_fast_checkout_post();
        }

        list($product, $parent_product, $variation, $product_id, $variation_id, $qty, $product_url, $state, $is_physical) = self::get_context_from_query();

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
<body <?php body_class($is_physical ? 'fcui-physical' : 'fcui-digital'); ?>>
<?php if (function_exists('wp_body_open')) wp_body_open(); ?>
<?php
        if ($state === 'not_allowed') {
            echo '<div style="max-width:680px;margin:40px auto;padding:0 14px;">این محصول در حالت پرداخت سریع فعال نیست.</div>';
        } elseif (!$product) {
            echo '<div style="max-width:680px;margin:40px auto;padding:0 14px;">محصول مشخص نیست.</div>';
        } else {
            if ($is_physical && file_exists(__DIR__ . '/templates/fast-checkout-physical.php')) {
                include __DIR__ . '/templates/fast-checkout-physical.php';
            } else {
                include __DIR__ . '/templates/fast-checkout-page.php';
            }
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
            wp_safe_redirect(self::get_fast_checkout_url(['product_id' => $product_id, 'err' => 'outofstock']));
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
        $extra_data = [];
        foreach ($_POST as $k => $v) {
            if (strpos($k, 'attribute_') === 0) {
                $variation[sanitize_text_field($k)] = sanitize_text_field(wp_unslash($v));
            }
            if (strpos($k, 'billing_') === 0 && !in_array($k, ['billing_full_name','billing_phone','billing_email'])) {
                $extra_data[$k] = sanitize_text_field(wp_unslash($v));
            }
        }

        if (function_exists('wc_load_cart')) wc_load_cart();
        if (!WC()->cart) {
            wp_safe_redirect(self::get_fast_checkout_url(['product_id' => $product_id, 'err' => 1]));
            exit;
        }

        // افزودن به سبد برای بعد
        if (isset($_POST['fcui_add_to_cart_later'])) {
            WC()->cart->add_to_cart($product_id, $qty, $variation_id, $variation);
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        // ثبت نام رایگان
        if (isset($_POST['fcui_free_register'])) {
            $user_id = get_current_user_id();
            if (!$user_id) {
                $username = sanitize_user($phone);
                $password = wp_generate_password();
                $user_id = wp_create_user($username, $password, $email);
                if (!is_wp_error($user_id)) {
                    wp_set_current_user($user_id);
                    wp_set_auth_cookie($user_id);
                    update_user_meta($user_id, 'first_name', $first);
                    update_user_meta($user_id, 'last_name', $last);
                }
            }

            $order = wc_create_order();
            $order->add_product($product, $qty);
            $order->set_address([
                'first_name' => $first,
                'last_name'  => $last,
                'email'      => $email,
                'phone'      => $phone,
            ] + $extra_data, 'billing');
            $order->set_customer_id($user_id);
            $order->calculate_totals();
            $order->update_status('completed');
            $order->save();

            wp_safe_redirect(add_query_arg([
                'order_id' => $order->get_id(),
                'key'      => $order->get_order_key(),
                'type'     => 'online'
            ], home_url('/order-success/')));
            exit;
        }

        // پرداخت
        $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');
        if (!$payment_method) {
            wp_safe_redirect(self::get_fast_checkout_url(['product_id' => $product_id, 'err' => 1]));
            exit;
        }

        $uid = get_current_user_id();
        if ($uid) {
            update_user_meta($uid, 'billing_first_name', $first);
            update_user_meta($uid, 'billing_last_name', $last);
            update_user_meta($uid, 'billing_phone', $phone);
            update_user_meta($uid, 'billing_email', $email);
            foreach ($extra_data as $k => $v) {
                update_user_meta($uid, $k, $v);
            }
        }

        WC()->cart->empty_cart();
        $added = WC()->cart->add_to_cart($product_id, $qty, $variation_id, $variation);
        if (!$added) {
            wp_safe_redirect(self::get_fast_checkout_url(['product_id' => $product_id, 'err' => 1]));
            exit;
        }

        // کد تخفیف
        if (!empty($_POST['fcui_coupon'])) {
            $coupon_code = sanitize_text_field($_POST['fcui_coupon']);
            WC()->cart->apply_coupon($coupon_code);
        }

        if ($payment_method === 'fcui_card2card') {
            $order_id = self::create_order_for_card2card($first, $last, $phone, $email, $payment_method, $extra_data);
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

        wc_maybe_define_constant('WOOCOMMERCE_CHECKOUT', true);
        $_POST['billing_first_name'] = $first;
        $_POST['billing_last_name']  = $last;
        $_POST['billing_phone']      = $phone;
        $_POST['billing_email']      = $email;
        foreach ($extra_data as $k => $v) {
            $_POST[$k] = $v;
        }
        $_POST['payment_method']     = $payment_method;
        $_POST['terms']              = 1;
        $_POST['ship_to_different_address'] = 0;

        if (!isset($_POST['woocommerce-process-checkout-nonce'])) {
            $_POST['woocommerce-process-checkout-nonce'] = wp_create_nonce('woocommerce-process_checkout');
        }

        WC()->checkout()->process_checkout();
        exit;
    }

    private static function create_order_for_card2card($first, $last, $phone, $email, $payment_method, $extra = []) {
        try {
            wc_maybe_define_constant('WOOCOMMERCE_CHECKOUT', true);
            $checkout = WC()->checkout();
            $order_id = $checkout->create_order(array_merge([
                'billing_first_name' => $first,
                'billing_last_name'  => $last,
                'billing_phone'      => $phone,
                'billing_email'      => $email,
            ], $extra));

            if (is_wp_error($order_id) || !$order_id) return 0;
            $order = wc_get_order($order_id);
            if (!$order) return 0;

            $order->set_payment_method($payment_method);
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

    public static function save_custom_fields($order_id) {
        $s = self::get_settings();
        if (!empty($s['enable_national_code']) && isset($_POST['billing_national_code'])) {
            update_post_meta($order_id, '_billing_national_code', sanitize_text_field($_POST['billing_national_code']));
        }
        if (!empty($s['custom_fields'])) {
            foreach ($s['custom_fields'] as $cf) {
                if (empty($cf['key']) || empty($cf['active'])) continue;
                $key = 'billing_'.$cf['key'];
                if (isset($_POST[$key])) {
                    update_post_meta($order_id, '_'.$key, sanitize_text_field($_POST[$key]));
                }
            }
        }
    }

    public static function display_custom_fields_admin($order) {
        $national = $order->get_meta('_billing_national_code');
        if ($national) {
            echo '<p><strong>کد ملی:</strong> ' . esc_html($national) . '</p>';
        }
    }

    // ========== Card2Card ==========
    public static function render_standalone_card2card() {
        if (!self::is_card2card_page()) return;
        self::enforce_login_or_redirect();

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key      = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $order = $order_id ? wc_get_order($order_id) : null;
        
        if (!$order || !$key || $order->get_order_key() !== $key) {
            status_header(404); nocache_headers(); echo 'سفارش معتبر نیست.'; exit;
        }

        $uid = get_current_user_id();
        if ($order->get_user_id() && $uid && (int)$order->get_user_id() !== (int)$uid) {
            status_header(403); nocache_headers(); echo 'دسترسی غیرمجاز'; exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::handle_card2card_upload($order);
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

        nocache_headers(); status_header(200);
        ?>
<!doctype html><html <?php language_attributes(); ?>><head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php wp_head(); ?></head>
<body <?php body_class('fcui-c2c-theme-' . esc_attr($theme)); ?>>
<?php if (function_exists('wp_body_open')) wp_body_open(); ?>
<?php include __DIR__ . '/templates/card2card-page.php'; ?>
<?php wp_footer(); ?></body></html>
        <?php exit;
    }

    private static function handle_card2card_upload($order) {
        if (!isset($_POST['fcui_nonce']) || !wp_verify_nonce($_POST['fcui_nonce'], 'fcui_card2card')) {
            wp_safe_redirect(self::get_card2card_url(['order_id'=>$order->get_id(),'key'=>$order->get_order_key(),'err'=>1])); exit;
        }

        $expires_at = (int) $order->get_meta('_fcui_c2c_expires_at');
        if ($expires_at && time() > $expires_at) {
            wp_safe_redirect(self::get_card2card_url(['order_id'=>$order->get_id(),'key'=>$order->get_order_key(),'expired'=>1])); exit;
        }

        if (empty($_FILES['receipt_1']['name'])) {
            wp_safe_redirect(self::get_card2card_url(['order_id'=>$order->get_id(),'key'=>$order->get_order_key(),'err'=>2])); exit;
        }

        $s = self::get_settings();
        $max_mb = max(1, (int)$s['c2c_max_mb']);
        $max_bytes = $max_mb * 1024 * 1024;
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $mimes = ['jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
        $urls = [];

        foreach (['receipt_1', 'receipt_2'] as $field) {
            if (empty($_FILES[$field]['name'])) continue;
            if (!empty($_FILES[$field]['size']) && (int)$_FILES[$field]['size'] > $max_bytes) {
                wp_safe_redirect(self::get_card2card_url(['order_id'=>$order->get_id(),'key'=>$order->get_order_key(),'err'=>3])); exit;
            }
            $upload = wp_handle_upload($_FILES[$field], ['test_form' => false, 'mimes' => $mimes]);
            if (!empty($upload['error'])) {
                wp_safe_redirect(self::get_card2card_url(['order_id'=>$order->get_id(),'key'=>$order->get_order_key(),'err'=>4])); exit;
            }
            if (!empty($upload['url'])) $urls[$field] = esc_url_raw($upload['url']);
        }

        if (empty($urls['receipt_1'])) {
            wp_safe_redirect(self::get_card2card_url(['order_id'=>$order->get_id(),'key'=>$order->get_order_key(),'err'=>4])); exit;
        }

        $order->update_meta_data('_fcui_c2c_receipt_1', $urls['receipt_1']);
        if (!empty($urls['receipt_2'])) $order->update_meta_data('_fcui_c2c_receipt_2', $urls['receipt_2']);
        $order->add_order_note('رسید کارت به کارت توسط کاربر آپلود شد.');
        $order->update_status('on-hold'); $order->save();

        wp_safe_redirect(home_url('/order-success/?order_id=' . $order->get_id() . '&key=' . $order->get_order_key() . '&type=c2c'));
        exit;
    }

    // ========== ADMIN ==========
    public static function admin_menu() {
        add_menu_page(
            'پرداخت سریع',
            'پرداخت سریع',
            'manage_woocommerce',
            'fcui-dashboard',
            [__CLASS__, 'admin_dashboard'],
            'dashicons-money-alt',
            56
        );
        
        add_submenu_page('fcui-dashboard', 'داشبورد', 'داشبورد', 'manage_woocommerce', 'fcui-dashboard', [__CLASS__, 'admin_dashboard']);
        add_submenu_page('fcui-dashboard', 'تنظیمات', 'تنظیمات', 'manage_woocommerce', 'fcui-settings', [__CLASS__, 'admin_settings_page']);
        add_submenu_page('fcui-dashboard', 'سفارشات کارت به کارت', 'کارت به کارت', 'manage_woocommerce', 'fcui-card2card-orders', [__CLASS__, 'admin_card2card_orders_page']);
        add_submenu_page('fcui-dashboard', 'آموزش', 'آموزش و راهنما', 'manage_woocommerce', 'fcui-tutorial', [__CLASS__, 'admin_tutorial_page']);
    }

    public static function admin_dashboard() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = self::get_settings();
        $orders_count = wc_get_orders(['limit' => -1, 'payment_method' => 'fcui_card2card', 'status' => 'on-hold', 'return' => 'ids']);
        ?>
        <div class="wrap fcui-admin" style="direction:rtl;font-family:Tahoma">
            <h1 style="display:flex;align-items:center;gap:12px">
                <span class="dashicons dashicons-money-alt" style="font-size:32px;color:#1e6bff"></span>
                پرداخت سریع - داشبورد
                <span style="background:#1e6bff;color:#fff;padding:4px 10px;border-radius:8px;font-size:12px">v<?php echo self::VERSION; ?></span>
            </h1>
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px;margin-top:30px">
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;box-shadow:0 4px 12px rgba(0,0,0,.05)">
                    <div style="display:flex;justify-content:space-between;align-items:start">
                        <div>
                            <div style="color:#64748b;font-size:13px;margin-bottom:8px">سفارشات در انتظار</div>
                            <div style="font-size:32px;font-weight:900;color:#0f172a"><?php echo count($orders_count); ?></div>
                        </div>
                        <div style="background:#fef3c7;padding:12px;border-radius:12px"><span class="dashicons dashicons-clock" style="color:#d97706"></span></div>
                    </div>
                    <a href="<?php echo admin_url('admin.php?page=fcui-card2card-orders'); ?>" style="display:inline-block;margin-top:16px;color:#1e6bff;text-decoration:none;font-weight:700">مشاهده ←</a>
                </div>
                
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;box-shadow:0 4px 12px rgba(0,0,0,.05)">
                    <div style="display:flex;justify-content:space-between;align-items:start">
                        <div>
                            <div style="color:#64748b;font-size:13px;margin-bottom:8px">صفحه پرداخت</div>
                            <div style="font-size:16px;font-weight:800;color:#0f172a;margin-top:8px"><?php echo esc_html(get_permalink(get_option(self::OPT_FAST_PAGE_ID))); ?></div>
                        </div>
                        <div style="background:#dbeafe;padding:12px;border-radius:12px"><span class="dashicons dashicons-cart" style="color:#1e6bff"></span></div>
                    </div>
                    <a href="<?php echo esc_url(get_permalink(get_option(self::OPT_FAST_PAGE_ID))); ?>" target="_blank" style="display:inline-block;margin-top:16px;color:#1e6bff;text-decoration:none;font-weight:700">مشاهده صفحه ←</a>
                </div>
                
                <div style="background:linear-gradient(135deg,#1e6bff,#0ea5e9);border-radius:16px;padding:24px;color:#fff;box-shadow:0 8px 24px rgba(30,107,255,.3)">
                    <div style="font-size:18px;font-weight:900;margin-bottom:12px">راه‌اندازی سریع</div>
                    <div style="opacity:.9;font-size:13px;line-height:1.7">برای بهترین تجربه، رنگ برند و فیلدهای فیزیکی را تنظیم کنید.</div>
                    <a href="<?php echo admin_url('admin.php?page=fcui-settings'); ?>" style="display:inline-block;margin-top:16px;background:#fff;color:#1e6bff;padding:8px 16px;border-radius:10px;text-decoration:none;font-weight:800">تنظیمات</a>
                </div>
            </div>
        </div>
        <?php
    }

    public static function admin_settings_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = self::get_settings();
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fcui_save'])) {
            check_admin_referer('fcui_save_settings');
            
            // ذخیره همه تنظیمات
            foreach (self::default_settings() as $key => $default) {
                if (isset($_POST[$key])) {
                    if (is_array($_POST[$key])) {
                        $s[$key] = array_map('sanitize_text_field', $_POST[$key]);
                    } else {
                        $s[$key] = sanitize_text_field($_POST[$key]);
                    }
                } else {
                    if (is_numeric($default) || is_bool($default)) $s[$key] = 0;
                }
            }
            
            // فیلدهای سفارشی
            if (isset($_POST['custom_fields'])) {
                $custom = [];
                foreach ($_POST['custom_fields'] as $cf) {
                    if (!empty($cf['key']) && !empty($cf['label'])) {
                        $custom[] = [
                            'key' => sanitize_key($cf['key']),
                            'label' => sanitize_text_field($cf['label']),
                            'required' => !empty($cf['required']) ? 1 : 0,
                            'active' => !empty($cf['active']) ? 1 : 0,
                            'order' => (int)$cf['order'],
                        ];
                    }
                }
                $s['custom_fields'] = $custom;
            }
            
            self::save_settings($s);
            echo '<div class="notice notice-success is-dismissible"><p>✅ تنظیمات با موفقیت ذخیره شد</p></div>';
        }
        ?>
        <div class="wrap fcui-admin" style="direction:rtl">
            <h1>تنظیمات پرداخت سریع</h1>
            
            <h2 class="nav-tab-wrapper" style="margin-top:20px">
                <a href="?page=fcui-settings&tab=general" class="nav-tab <?php echo $tab=='general'?'nav-tab-active':''; ?>">عمومی</a>
                <a href="?page=fcui-settings&tab=appearance" class="nav-tab <?php echo $tab=='appearance'?'nav-tab-active':''; ?>">ظاهر و استایل</a>
                <a href="?page=fcui-settings&tab=physical" class="nav-tab <?php echo $tab=='physical'?'nav-tab-active':''; ?>">محصولات فیزیکی</a>
                <a href="?page=fcui-settings&tab=card2card" class="nav-tab <?php echo $tab=='card2card'?'nav-tab-active':''; ?>">کارت به کارت</a>
                <a href="?page=fcui-settings&tab=messages" class="nav-tab <?php echo $tab=='messages'?'nav-tab-active':''; ?>">پیام‌ها</a>
            </h2>
            
            <form method="post" style="background:#fff;padding:24px;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 12px 12px;max-width:900px">
                <?php wp_nonce_field('fcui_save_settings'); ?>
                
                <?php if ($tab === 'general'): ?>
                <table class="form-table">
                    <tr><th>اجبار به ورود</th><td><label><input type="checkbox" name="require_login" value="1" <?php checked($s['require_login'],1); ?>> فقط کاربران وارد شده</label></td></tr>
                    <tr><th>حالت اعمال</th><td>
                        <label><input type="radio" name="apply_mode" value="all" <?php checked($s['apply_mode'],'all'); ?>> همه محصولات</label><br>
                        <label><input type="radio" name="apply_mode" value="digital" <?php checked($s['apply_mode'],'digital'); ?>> فقط دیجیتال</label>
                    </td></tr>
                    <tr><th>کد تخفیف</th><td>
                        <label><input type="checkbox" name="coupon_enabled" value="1" <?php checked($s['coupon_enabled'],1); ?>> فعال باشد</label><br><br>
                        <input type="text" name="coupon_label" value="<?php echo esc_attr($s['coupon_label']); ?>" class="regular-text" placeholder="متن لیبل">
                    </td></tr>
                </table>
                <?php endif; ?>
                
                <?php if ($tab === 'appearance'): ?>
                <table class="form-table">
                    <tr><th>رنگ اصلی</th><td><input type="text" name="primary_color" value="<?php echo esc_attr($s['primary_color']); ?>" class="fcui-color"></td></tr>
                    <tr><th>رنگ پس‌زمینه</th><td><input type="text" name="background_color" value="<?php echo esc_attr($s['background_color']); ?>" class="fcui-color"></td></tr>
                    <tr><th>گردی کارت‌ها</th><td><input type="number" name="card_radius" value="<?php echo (int)$s['card_radius']; ?>" min="0" max="30"> پیکسل</td></tr>
                    <tr><th>متن دکمه پرداخت</th><td><input type="text" name="button_pay_text" value="<?php echo esc_attr($s['button_pay_text']); ?>" class="regular-text"></td></tr>
                    <tr><th>متن دکمه بعداً</th><td><input type="text" name="button_later_text" value="<?php echo esc_attr($s['button_later_text']); ?>" class="regular-text"></td></tr>
                    <tr><th>متن راهنما</th><td><input type="text" name="hint_text" value="<?php echo esc_attr($s['hint_text']); ?>" class="large-text"></td></tr>
                </table>
                <?php endif; ?>
                
                <?php if ($tab === 'physical'): ?>
                <h3>فیلدهای محصولات فیزیکی</h3>
                <p>فیلدهای زیر در صفحه پرداخت محصولات فیزیکی نمایش داده می‌شود:</p>
                <table class="form-table">
                    <tr><th>فعال‌سازی</th><td><label><input type="checkbox" name="physical_enabled" value="1" <?php checked($s['physical_enabled'],1); ?>> پرداخت سریع برای فیزیکی فعال باشد</label></td></tr>
                    <tr><th>فیلدها</th><td>
                        <?php $fields = ['billing_state'=>'استان','billing_city'=>'شهر','billing_address_1'=>'آدرس','billing_postcode'=>'کد پستی']; 
                        foreach ($fields as $k=>$l): ?>
                        <label style="display:block;margin:5px 0"><input type="checkbox" name="physical_fields[]" value="<?php echo $k; ?>" <?php checked(in_array($k,(array)$s['physical_fields'])); ?>> <?php echo $l; ?></label>
                        <?php endforeach; ?>
                    </td></tr>
                    <tr><th>کد ملی</th><td>
                        <label><input type="checkbox" name="enable_national_code" value="1" <?php checked($s['enable_national_code'],1); ?>> نمایش کد ملی</label><br>
                        <label><input type="checkbox" name="national_code_required" value="1" <?php checked($s['national_code_required'],1); ?>> اجباری باشد</label>
                    </td></tr>
                </table>
                
                <h3 style="margin-top:30px">فیلدهای سفارشی</h3>
                <div id="fcui-custom-fields">
                    <?php foreach ((array)$s['custom_fields'] as $i=>$cf): ?>
                    <div style="background:#f8fafc;padding:12px;margin:8px 0;border-radius:8px;display:grid;grid-template-columns:120px 1fr 80px 80px 60px;gap:8px;align-items:center">
                        <input type="text" name="custom_fields[<?php echo $i; ?>][key]" value="<?php echo esc_attr($cf['key']); ?>" placeholder="کلید انگلیسی">
                        <input type="text" name="custom_fields[<?php echo $i; ?>][label]" value="<?php echo esc_attr($cf['label']); ?>" placeholder="برچسب فارسی">
                        <label><input type="checkbox" name="custom_fields[<?php echo $i; ?>][required]" value="1" <?php checked($cf['required'],1); ?>> اجباری</label>
                        <label><input type="checkbox" name="custom_fields[<?php echo $i; ?>][active]" value="1" <?php checked($cf['active'],1); ?>> فعال</label>
                        <input type="number" name="custom_fields[<?php echo $i; ?>][order]" value="<?php echo (int)$cf['order']; ?>" placeholder="ترتیب" style="width:60px">
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" onclick="addCustomField()" class="button">+ افزودن فیلد</button>
                <script>
                let cfIndex = <?php echo count((array)$s['custom_fields']); ?>;
                function addCustomField(){
                    const div = document.createElement('div');
                    div.style.cssText = 'background:#f8fafc;padding:12px;margin:8px 0;border-radius:8px;display:grid;grid-template-columns:120px 1fr 80px 80px 60px;gap:8px;align-items:center';
                    div.innerHTML = `<input type="text" name="custom_fields[${cfIndex}][key]" placeholder="کلید انگلیسی"><input type="text" name="custom_fields[${cfIndex}][label]" placeholder="برچسب فارسی"><label><input type="checkbox" name="custom_fields[${cfIndex}][required]" value="1"> اجباری</label><label><input type="checkbox" name="custom_fields[${cfIndex}][active]" value="1" checked> فعال</label><input type="number" name="custom_fields[${cfIndex}][order]" value="${cfIndex}" style="width:60px">`;
                    document.getElementById('fcui-custom-fields').appendChild(div);
                    cfIndex++;
                }
                </script>
                <?php endif; ?>
                
                <?php if ($tab === 'card2card'): ?>
                <table class="form-table">
                    <tr><th>فعال</th><td><label><input type="checkbox" name="c2c_enabled" value="1" <?php checked($s['c2c_enabled'],1); ?>> فعال باشد</label></td></tr>
                    <tr><th>شماره کارت</th><td><input type="text" name="c2c_card_number" value="<?php echo esc_attr($s['c2c_card_number']); ?>" class="regular-text" dir="ltr"></td></tr>
                    <tr><th>نام صاحب کارت</th><td><input type="text" name="c2c_holder_name" value="<?php echo esc_attr($s['c2c_holder_name']); ?>" class="regular-text"></td></tr>
                    <tr><th>نام بانک</th><td><input type="text" name="c2c_bank_name" value="<?php echo esc_attr($s['c2c_bank_name']); ?>" class="regular-text"></td></tr>
                    <tr><th>تم</th><td>
                        <select name="c2c_theme">
                            <option value="blue" <?php selected($s['c2c_theme'],'blue'); ?>>آبی</option>
                            <option value="red" <?php selected($s['c2c_theme'],'red'); ?>>قرمز</option>
                            <option value="gold" <?php selected($s['c2c_theme'],'gold'); ?>>طلایی</option>
                            <option value="dark" <?php selected($s['c2c_theme'],'dark'); ?>>مشکی</option>
                        </select>
                    </td></tr>
                    <tr><th>تایمر (دقیقه)</th><td><input type="number" name="c2c_timer_minutes" value="<?php echo (int)$s['c2c_timer_minutes']; ?>" min="1"></td></tr>
                </table>
                <?php endif; ?>
                
                <?php if ($tab === 'messages'): ?>
                <table class="form-table">
                    <tr><th>عنوان موفقیت</th><td><input type="text" name="success_page_title" value="<?php echo esc_attr($s['success_page_title']); ?>" class="large-text"></td></tr>
                    <tr><th>پیام آنلاین</th><td><textarea name="success_page_online_message" rows="3" class="large-text"><?php echo esc_textarea($s['success_page_online_message']); ?></textarea></td></tr>
                    <tr><th>پیام کارت به کارت</th><td><textarea name="success_page_c2c_message" rows="3" class="large-text"><?php echo esc_textarea($s['success_page_c2c_message']); ?></textarea></td></tr>
                    <tr><th>لینک دوره</th><td><input type="url" name="course_access_link" value="<?php echo esc_attr($s['course_access_link']); ?>" class="large-text" dir="ltr"></td></tr>
                </table>
                <?php endif; ?>
                
                <p style="margin-top:30px"><button class="button button-primary button-large" name="fcui_save" value="1">💾 ذخیره تنظیمات</button></p>
            </form>
            
            <script>jQuery(document).ready(function($){$('.fcui-color').wpColorPicker();});</script>
        </div>
        <?php
    }

    public static function admin_card2card_orders_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = self::get_settings();

        if (isset($_GET['fcui_approve']) && isset($_GET['_wpnonce'])) {
            $oid = absint($_GET['fcui_approve']);
            if (wp_verify_nonce($_GET['_wpnonce'], 'fcui_approve_' . $oid)) {
                $order = wc_get_order($oid);
                if ($order && $order->get_payment_method() === 'fcui_card2card') {
                    $status = $s['c2c_approved_status'] === 'processing' ? 'processing' : 'completed';
                    $order->update_status($status, 'تأیید کارت به کارت توسط مدیر');
                    echo '<div class="notice notice-success"><p>✅ سفارش تأیید شد</p></div>';
                }
            }
        }
        if (isset($_GET['fcui_reject']) && isset($_GET['_wpnonce'])) {
            $oid = absint($_GET['fcui_reject']);
            if (wp_verify_nonce($_GET['_wpnonce'], 'fcui_reject_' . $oid)) {
                $order = wc_get_order($oid);
                if ($order) {
                    $order->update_status('cancelled', 'رد شده توسط مدیر');
                    echo '<div class="notice notice-warning"><p>سفارش رد شد</p></div>';
                }
            }
        }

        $orders = wc_get_orders(['limit'=>50,'status'=>['on-hold'],'payment_method'=>'fcui_card2card','orderby'=>'date','order'=>'DESC']);
        ?>
        <div class="wrap" style="direction:rtl">
          <h1>سفارش‌های کارت به کارت</h1>
          <table class="widefat striped" style="margin-top:20px">
            <thead><tr><th>سفارش</th><th>مشتری</th><th>مبلغ</th><th>رسید</th><th>تاریخ</th><th>عملیات</th></tr></thead>
            <tbody>
              <?php if (empty($orders)): ?>
                <tr><td colspan="6" style="text-align:center;padding:40px">سفارشی یافت نشد</td></tr>
              <?php else: foreach ($orders as $order):
                $r1 = $order->get_meta('_fcui_c2c_receipt_1');
                $approve = wp_nonce_url(admin_url('admin.php?page=fcui-card2card-orders&fcui_approve='.$order->get_id()), 'fcui_approve_'.$order->get_id());
                $reject = wp_nonce_url(admin_url('admin.php?page=fcui-card2card-orders&fcui_reject='.$order->get_id()), 'fcui_reject_'.$order->get_id());
              ?>
              <tr>
                <td><strong>#<?php echo $order->get_id(); ?></strong></td>
                <td><?php echo esc_html($order->get_formatted_billing_full_name()); ?><br><small><?php echo esc_html($order->get_billing_phone()); ?></small></td>
                <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                <td><?php echo $r1 ? '<a href="'.esc_url($r1).'" target="_blank" class="button button-small">مشاهده</a>' : '—'; ?></td>
                <td><?php echo $order->get_date_created()->date_i18n('Y/m/d H:i'); ?></td>
                <td>
                  <a href="<?php echo esc_url($approve); ?>" class="button button-primary">✓ تأیید</a>
                  <a href="<?php echo esc_url($reject); ?>" class="button">✗ رد</a>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php
    }

    public static function admin_tutorial_page() {
        ?>
        <div class="wrap" style="direction:rtl;max-width:900px">
            <h1>📚 آموزش پرداخت سریع</h1>
            
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:30px;margin-top:20px">
                <h2 style="color:#1e6bff">🎯 معرفی افزونه</h2>
                <p style="line-height:2;font-size:15px">پرداخت سریع، فرآیند خرید ووکامرس را به یک صفحه ساده و بدون اسکرول تبدیل می‌کند. مشتری با یک کلیک وارد صفحه پرداخت می‌شود، اطلاعاتش را وارد می‌کند و پرداخت می‌کند.</p>
                
                <h3 style="margin-top:30px">✨ ویژگی‌های کلیدی</h3>
                <ul style="line-height:2.2">
                    <li>✅ <strong>پرداخت بدون اسکرول</strong> - طراحی شده برای موبایل</li>
                    <li>✅ <strong>کد تخفیف</strong> - فیلد اختصاصی در صفحه پرداخت</li>
                    <li>✅ <strong>محصولات فیزیکی</strong> - فیلد آدرس، کد پستی، کد ملی</li>
                    <li>✅ <strong>کارت به کارت</strong> - آپلود رسید + تایید مدیر</li>
                    <li>✅ <strong>شخصی‌سازی کامل</strong> - رنگ، متن، فیلدها</li>
                </ul>
                
                <h3 style="margin-top:30px">🚀 راه‌اندازی</h3>
                <ol style="line-height:2.2">
                    <li>به <strong>تنظیمات > ظاهر</strong> بروید و رنگ برند خود را انتخاب کنید</li>
                    <li>در تب <strong>محصولات فیزیکی</strong>، فیلدهای مورد نیاز را فعال کنید</li>
                    <li>شماره کارت خود را در تب <strong>کارت به کارت</strong> وارد کنید</li>
                    <li>صفحه پرداخت: <code><?php echo home_url('/fast-checkout/'); ?></code></li>
                </ol>
                
                <div style="background:#f0f9ff;border-right:4px solid #0ea5e9;padding:16px;margin-top:30px;border-radius:8px">
                    <strong>💡 نکته:</strong> برای محصولات دانلودی، فیلدها مینیمال است. برای فیزیکی، به صورت خودکار فیلد آدرس نمایش داده می‌شود.
                </div>
            </div>
        </div>
        <?php
    }
}

FCUI_Fast_Checkout::init();

add_shortcode('fcui_fast_checkout', function(){ return ''; });
add_shortcode('fcui_card2card', function(){ return ''; });

add_filter('woocommerce_get_return_url', function($url, $order){
    if (!$order) return $url;
    return home_url('/order-success/?order_id=' . $order->get_id() . '&key=' . $order->get_order_key() . '&type=online');
}, 10, 2);
