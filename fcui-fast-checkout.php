<?php
/**
 * Plugin Name: پرداخت سریع - سلام وردپرس
 * Description: این پلاگین صفحه ی پرداخت سریع را جایگزین فرایند سفارش ووکامرس میکند. نسخه پیشرفته با پشتیبانی محصولات فیزیکی، کد تخفیف، کمپین زماندار و پنل مدیریت حرفه‌ای
 * Version: 2.2.0
 * Author: امیرحسین سعادتی
 * Text Domain: fcui
 */

if (!defined('ABSPATH')) exit;

final class FCUI_Fast_Checkout {

    const OPT_FAST_PAGE_ID = 'fcui_fast_checkout_page_id';
    const OPT_C2C_PAGE_ID  = 'fcui_card2card_page_id';
    const OPT_SETTINGS     = 'fcui_settings';
    const VERSION = '2.2.0';

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

        // Campaign system
        add_action('add_meta_boxes', [__CLASS__, 'add_campaign_metabox']);
        add_action('save_post_product', [__CLASS__, 'save_campaign_meta']);
        add_filter('woocommerce_product_get_price', [__CLASS__, 'apply_campaign_price'], 20, 2);
        add_filter('woocommerce_product_get_regular_price', [__CLASS__, 'apply_campaign_regular_price'], 20, 2);
        add_filter('woocommerce_product_variation_get_price', [__CLASS__, 'apply_campaign_price'], 20, 2);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'show_campaign_timer'], 11);
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
            'c2c_theme'       => 'melli',
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

    // Campaign functions
    public static function add_campaign_metabox() {
        add_meta_box('fcui_campaign', '⏰ کمپین تخفیف زماندار', [__CLASS__, 'campaign_metabox_html'], 'product', 'side', 'high');
    }

    public static function campaign_metabox_html($post) {
        $start = get_post_meta($post->ID, '_fcui_campaign_start', true);
        $end = get_post_meta($post->ID, '_fcui_campaign_end', true);
        $price = get_post_meta($post->ID, '_fcui_campaign_price', true);
        wp_nonce_field('fcui_campaign', 'fcui_campaign_nonce');
        echo '<p><label>قیمت کمپین:</label><input type="number" name="fcui_campaign_price" value="'.esc_attr($price).'" style="width:100%" placeholder="مثلا 99000"></p>';
        echo '<p><label>شروع:</label><input type="datetime-local" name="fcui_campaign_start" value="'.esc_attr($start).'" style="width:100%"></p>';
        echo '<p><label>پایان:</label><input type="datetime-local" name="fcui_campaign_end" value="'.esc_attr($end).'" style="width:100%"></p>';
        echo '<p style="font-size:12px;color:#666">در بازه زمانی مشخص شده، قیمت محصول خودکار تغییر می‌کند و تایمر نمایش داده می‌شود.</p>';
    }

    public static function save_campaign_meta($post_id) {
        if (!isset($_POST['fcui_campaign_nonce']) || !wp_verify_nonce($_POST['fcui_campaign_nonce'], 'fcui_campaign')) return;
        update_post_meta($post_id, '_fcui_campaign_price', sanitize_text_field($_POST['fcui_campaign_price'] ?? ''));
        update_post_meta($post_id, '_fcui_campaign_start', sanitize_text_field($_POST['fcui_campaign_start'] ?? ''));
        update_post_meta($post_id, '_fcui_campaign_end', sanitize_text_field($_POST['fcui_campaign_end'] ?? ''));
    }

    public static function apply_campaign_price($price, $product) {
        $id = $product->get_id();
        $camp_price = get_post_meta($id, '_fcui_campaign_price', true);
        $start = get_post_meta($id, '_fcui_campaign_start', true);
        $end = get_post_meta($id, '_fcui_campaign_end', true);
        
        if (!$camp_price || !$start || !$end) return $price;
        
        $now = current_time('timestamp');
        $start_ts = strtotime($start);
        $end_ts = strtotime($end);
        
        if ($now >= $start_ts && $now <= $end_ts) {
            return $camp_price;
        }
        return $price;
    }

    public static function apply_campaign_regular_price($price, $product) {
        return $price; // Keep original as regular
    }

    public static function show_campaign_timer() {
        global $product;
        $id = $product->get_id();
        $end = get_post_meta($id, '_fcui_campaign_end', true);
        $start = get_post_meta($id, '_fcui_campaign_start', true);
        $camp_price = get_post_meta($id, '_fcui_campaign_price', true);
        
        if (!$end || !$camp_price) return;
        
        $now = current_time('timestamp');
        $end_ts = strtotime($end);
        $start_ts = strtotime($start);
        
        if ($now < $start_ts || $now > $end_ts) return;
        
        echo '<div class="fcui-campaign-timer" data-end="'.esc_attr($end_ts).'" style="background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;padding:12px;border-radius:12px;margin:15px 0;text-align:center;font-weight:900">
            ⏰ پیشنهاد ویژه - زمان باقیمانده: <span class="fcui-timer">--:--:--</span>
        </div>
        <script>
        (function(){
            const el = document.querySelector(".fcui-campaign-timer .fcui-timer");
            const end = '.($end_ts*1000).';
            function tick(){
                const diff = end - Date.now();
                if(diff<=0){el.textContent="00:00:00";return}
                const h = Math.floor(diff/3600000);
                const m = Math.floor((diff%3600000)/60000);
                const s = Math.floor((diff%60000)/1000);
                el.textContent = String(h).padStart(2,"0")+":"+String(m).padStart(2,"0")+":"+String(s).padStart(2,"0");
            }
            tick(); setInterval(tick,1000);
        })();
        </script>';
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
        wp_enqueue_style('fcui-admin', plugins_url('assets/fast-checkout.css', __FILE__), [], self::VERSION);
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
            
            if (!empty($s['enable_national_code'])) {
                $fields['billing']['billing_national_code'] = [
                    'label' => $s['national_code_label'],
                    'required' => !empty($s['national_code_required']),
                    'priority' => 35,
                    'class' => ['form-row-first'],
                ];
            }
            
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
            // Campaign timer for checkout
            $end = get_post_meta($product_id, '_fcui_campaign_end', true);
            $start = get_post_meta($product_id, '_fcui_campaign_start', true);
            if ($end && $start) {
                $now = current_time('timestamp');
                if ($now >= strtotime($start) && $now <= strtotime($end)) {
                    echo '<div style="max-width:680px;margin:10px auto 0;padding:0 14px"><div class="fcui-campaign-timer" data-end="'.strtotime($end).'" style="background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;padding:10px;border-radius:12px;text-align:center;font-weight:900;font-size:13px">⏰ زمان باقیمانده تخفیف: <span class="fcui-timer">--:--:--</span></div></div>';
                    echo '<script>const el=document.querySelector(".fcui-timer");const end='.strtotime($end).'*1000;function t(){const d=end-Date.now();if(d<=0)return;const h=Math.floor(d/36e5),m=Math.floor(d%36e5/6e4),s=Math.floor(d%6e4/1e3);el.textContent=`${String(h).padStart(2,0)}:${String(m).padStart(2,0)}:${String(s).padStart(2,0)}`}t();setInterval(t,1e3)</script>';
                }
            }
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

        if (isset($_POST['fcui_add_to_cart_later'])) {
            WC()->cart->add_to_cart($product_id, $qty, $variation_id, $variation);
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

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
            $order->update_meta_data('_fcui_fast_checkout', 1);
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

        add_action('woocommerce_checkout_update_order_meta', function($order_id){
            update_post_meta($order_id, '_fcui_fast_checkout', 1);
        });

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
            $order->update_meta_data('_fcui_fast_checkout', 1);
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
        
        // آمار پیشرفته
        $orders_c2c = wc_get_orders(['limit' => -1, 'payment_method' => 'fcui_card2card', 'status' => 'on-hold', 'return' => 'ids']);
        $orders_fast = wc_get_orders(['limit' => -1, 'meta_key' => '_fcui_fast_checkout', 'return' => 'ids']);
        $orders_today = wc_get_orders(['date_created' => '>=' . date('Y-m-d 00:00:00'), 'return' => 'ids']);
        
        $revenue_today = 0;
        foreach ($orders_today as $oid) {
            $o = wc_get_order($oid);
            if ($o && $o->has_status(['processing','completed'])) $revenue_today += (float)$o->get_total();
        }
        
        $total_orders = wc_get_orders(['limit' => -1, 'return' => 'ids']);
        $conversion = count($total_orders) > 0 ? round((count($orders_fast) / count($total_orders)) * 100, 1) : 0;
        
        $campaigns_active = 0;
        $products = wc_get_products(['limit' => -1, 'return' => 'ids']);
        foreach ($products as $pid) {
            $end = get_post_meta($pid, '_fcui_campaign_end', true);
            if ($end && strtotime($end) > time()) $campaigns_active++;
        }
        ?>
        <div class="wrap fcui-admin" style="direction:rtl;font-family:Tahoma">
            <h1 style="display:flex;align-items:center;gap:12px;margin-bottom:30px">
                <span class="dashicons dashicons-money-alt" style="font-size:36px;color:#1e6bff"></span>
                <div>
                    <div>پرداخت سریع</div>
                    <div style="font-size:13px;color:#64748b;font-weight:400">نسخه <?php echo self::VERSION; ?> - داشبورد هوشمند</div>
                </div>
            </h1>
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px">
                <div style="background:linear-gradient(135deg,#1e6bff,#0ea5e9);color:#fff;border-radius:20px;padding:24px;box-shadow:0 10px 30px rgba(30,107,255,.25);position:relative;overflow:hidden">
                    <div style="position:absolute;top:-20px;right:-20px;width:100px;height:100px;background:rgba(255,255,255,.1);border-radius:50%"></div>
                    <div style="font-size:13px;opacity:.9">سفارشات امروز</div>
                    <div style="font-size:38px;font-weight:900;margin:8px 0"><?php echo count($orders_today); ?></div>
                    <div style="font-size:12px;opacity:.85">درآمد: <?php echo wc_price($revenue_today); ?></div>
                </div>
                
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:24px;box-shadow:0 4px 12px rgba(0,0,0,.04)">
                    <div style="display:flex;justify-content:space-between">
                        <div>
                            <div style="color:#64748b;font-size:13px">نرخ تبدیل</div>
                            <div style="font-size:32px;font-weight:900;color:#0f172a;margin-top:6px"><?php echo $conversion; ?>%</div>
                        </div>
                        <div style="background:#dcfce7;width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center"><span class="dashicons dashicons-chart-line" style="color:#16a34a"></span></div>
                    </div>
                    <div style="margin-top:12px;height:6px;background:#f1f5f9;border-radius:6px;overflow:hidden"><div style="width:<?php echo $conversion; ?>%;height:100%;background:linear-gradient(90deg,#16a34a,#22c55e)"></div></div>
                </div>
                
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:24px;box-shadow:0 4px 12px rgba(0,0,0,.04)">
                    <div style="display:flex;justify-content:space-between">
                        <div>
                            <div style="color:#64748b;font-size:13px">کارت به کارت</div>
                            <div style="font-size:32px;font-weight:900;color:#d97706;margin-top:6px"><?php echo count($orders_c2c); ?></div>
                        </div>
                        <div style="background:#fef3c7;width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center"><span class="dashicons dashicons-clock" style="color:#d97706"></span></div>
                    </div>
                    <a href="<?php echo admin_url('admin.php?page=fcui-card2card-orders'); ?>" style="display:inline-block;margin-top:14px;color:#1e6bff;text-decoration:none;font-weight:700;font-size:13px">مدیریت ←</a>
                </div>
                
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:24px;box-shadow:0 4px 12px rgba(0,0,0,.04)">
                    <div style="display:flex;justify-content:space-between">
                        <div>
                            <div style="color:#64748b;font-size:13px">کمپین فعال</div>
                            <div style="font-size:32px;font-weight:900;color:#dc2626;margin-top:6px"><?php echo $campaigns_active; ?></div>
                        </div>
                        <div style="background:#fee2e2;width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center"><span class="dashicons dashicons-megaphone" style="color:#dc2626"></span></div>
                    </div>
                    <div style="font-size:12px;color:#64748b;margin-top:8px">محصولات با تایمر</div>
                </div>
                
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:24px;box-shadow:0 4px 12px rgba(0,0,0,.04)">
                    <div style="display:flex;justify-content:space-between">
                        <div>
                            <div style="color:#64748b;font-size:13px">کل پرداخت سریع</div>
                            <div style="font-size:32px;font-weight:900;color:#0f172a;margin-top:6px"><?php echo count($orders_fast); ?></div>
                        </div>
                        <div style="background:#dbeafe;width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center"><span class="dashicons dashicons-yes-alt" style="color:#1e6bff"></span></div>
                    </div>
                </div>
                
                <div style="background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;border-radius:20px;padding:24px;box-shadow:0 10px 30px rgba(15,23,42,.25)">
                    <div style="font-size:16px;font-weight:900;margin-bottom:8px">شروع سریع</div>
                    <div style="opacity:.8;font-size:13px;line-height:1.6;margin-bottom:14px">کمپین بساز، تم بانک رو انتخاب کن</div>
                    <a href="<?php echo admin_url('admin.php?page=fcui-settings'); ?>" style="background:#fff;color:#0f172a;padding:8px 14px;border-radius:10px;text-decoration:none;font-weight:800;font-size:13px;display:inline-block">تنظیمات</a>
                </div>
            </div>
            
            <div style="margin-top:30px;background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:24px">
                <h3 style="margin:0 0 16px">لینک‌های مفید</h3>
                <div style="display:flex;gap:12px;flex-wrap:wrap">
                    <a href="<?php echo esc_url(get_permalink(get_option(self::OPT_FAST_PAGE_ID))); ?>" target="_blank" style="padding:10px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;text-decoration:none;color:#0f172a;font-weight:700">صفحه پرداخت سریع</a>
                    <a href="<?php echo admin_url('edit.php?post_type=product'); ?>" style="padding:10px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;text-decoration:none;color:#0f172a;font-weight:700">مدیریت محصولات</a>
                    <a href="<?php echo admin_url('admin.php?page=wc-orders'); ?>" style="padding:10px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;text-decoration:none;color:#0f172a;font-weight:700">همه سفارشات</a>
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
            
            // ذخیره بر اساس تب - رفع باگ
            $new_settings = $s;
            
            if ($tab === 'general') {
                $new_settings['require_login'] = isset($_POST['require_login']) ? 1 : 0;
                $new_settings['apply_mode'] = sanitize_text_field($_POST['apply_mode'] ?? 'all');
                $new_settings['coupon_enabled'] = isset($_POST['coupon_enabled']) ? 1 : 0;
                $new_settings['coupon_label'] = sanitize_text_field($_POST['coupon_label'] ?? '');
                $new_settings['coupon_placeholder'] = sanitize_text_field($_POST['coupon_placeholder'] ?? '');
            }
            
            if ($tab === 'appearance') {
                $new_settings['primary_color'] = sanitize_hex_color($_POST['primary_color'] ?? '#1e6bff');
                $new_settings['secondary_color'] = sanitize_hex_color($_POST['secondary_color'] ?? '#0f172a');
                $new_settings['background_color'] = sanitize_hex_color($_POST['background_color'] ?? '#f5f8ff');
                $new_settings['card_radius'] = absint($_POST['card_radius'] ?? 16);
                $new_settings['button_radius'] = absint($_POST['button_radius'] ?? 16);
                $new_settings['button_pay_text'] = sanitize_text_field($_POST['button_pay_text'] ?? '');
                $new_settings['button_later_text'] = sanitize_text_field($_POST['button_later_text'] ?? '');
                $new_settings['button_free_text'] = sanitize_text_field($_POST['button_free_text'] ?? '');
                $new_settings['hint_text'] = sanitize_text_field($_POST['hint_text'] ?? '');
            }
            
            if ($tab === 'physical') {
                $new_settings['physical_enabled'] = isset($_POST['physical_enabled']) ? 1 : 0;
                $new_settings['physical_fields'] = isset($_POST['physical_fields']) ? array_map('sanitize_text_field', $_POST['physical_fields']) : [];
                $new_settings['enable_national_code'] = isset($_POST['enable_national_code']) ? 1 : 0;
                $new_settings['national_code_required'] = isset($_POST['national_code_required']) ? 1 : 0;
                $new_settings['national_code_label'] = sanitize_text_field($_POST['national_code_label'] ?? 'کد ملی');
                
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
                    $new_settings['custom_fields'] = $custom;
                }
            }
            
            if ($tab === 'card2card') {
                $new_settings['c2c_enabled'] = isset($_POST['c2c_enabled']) ? 1 : 0;
                $new_settings['c2c_card_number'] = sanitize_text_field($_POST['c2c_card_number'] ?? '');
                $new_settings['c2c_holder_name'] = sanitize_text_field($_POST['c2c_holder_name'] ?? '');
                $new_settings['c2c_bank_name'] = sanitize_text_field($_POST['c2c_bank_name'] ?? '');
                $new_settings['c2c_theme'] = sanitize_key($_POST['c2c_theme'] ?? 'melli');
                $new_settings['c2c_timer_minutes'] = absint($_POST['c2c_timer_minutes'] ?? 20);
                $new_settings['c2c_max_mb'] = absint($_POST['c2c_max_mb'] ?? 3);
            }
            
            if ($tab === 'messages') {
                $new_settings['success_page_title'] = sanitize_text_field($_POST['success_page_title'] ?? '');
                $new_settings['success_page_online_message'] = sanitize_textarea_field($_POST['success_page_online_message'] ?? '');
                $new_settings['success_page_c2c_message'] = sanitize_textarea_field($_POST['success_page_c2c_message'] ?? '');
                $new_settings['course_access_link'] = esc_url_raw($_POST['course_access_link'] ?? '');
            }
            
            self::save_settings($new_settings);
            $s = $new_settings;
            echo '<div class="notice notice-success is-dismissible" style="border-right:4px solid #16a34a"><p>✅ تنظیمات با موفقیت ذخیره شد - نسخه '.self::VERSION.'</p></div>';
        }
        
        $tabs = [
            'general' => ['label' => 'عمومی', 'icon' => 'admin-generic'],
            'appearance' => ['label' => 'ظاهر', 'icon' => 'art'],
            'physical' => ['label' => 'فیزیکی', 'icon' => 'location'],
            'card2card' => ['label' => 'کارت بانکی', 'icon' => 'bank'],
            'messages' => ['label' => 'پیام‌ها', 'icon' => 'format-chat'],
        ];
        ?>
        <div class="wrap fcui-admin" style="direction:rtl">
            <h1 style="display:flex;align-items:center;gap:10px">
                <span class="dashicons dashicons-admin-settings" style="color:#1e6bff"></span>
                تنظیمات پرداخت سریع
                <span style="background:linear-gradient(135deg,#1e6bff,#0ea5e9);color:#fff;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:800">PRO v2.2</span>
            </h1>
            
            <div style="display:flex;gap:20px;margin-top:24px;align-items:flex-start">
                <div style="width:220px;flex-shrink:0">
                    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:8px;position:sticky;top:32px">
                        <?php foreach ($tabs as $key => $t): ?>
                        <a href="?page=fcui-settings&tab=<?php echo $key; ?>" style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;text-decoration:none;margin-bottom:4px;<?php echo $tab==$key ? 'background:linear-gradient(135deg,#1e6bff,#0ea5e9);color:#fff;font-weight:800;box-shadow:0 4px 12px rgba(30,107,255,.25)' : 'color:#334155'; ?>">
                            <span class="dashicons dashicons-<?php echo $t['icon']; ?>" style="<?php echo $tab==$key ? 'color:#fff' : 'color:#64748b'; ?>"></span>
                            <?php echo $t['label']; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div style="flex:1;max-width:800px">
                    <form method="post" style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px;box-shadow:0 4px 16px rgba(0,0,0,.04)">
                        <?php wp_nonce_field('fcui_save_settings'); ?>
                        <input type="hidden" name="fcui_tab" value="<?php echo esc_attr($tab); ?>">
                        
                        <?php if ($tab === 'general'): ?>
                        <h2 style="margin-top:0;display:flex;align-items:center;gap:8px"><span class="dashicons dashicons-admin-generic"></span> تنظیمات عمومی</h2>
                        
                        <div style="display:grid;gap:20px;margin-top:24px">
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:18px">
                                <label style="display:flex;align-items:center;justify-content:space-between;cursor:pointer">
                                    <div>
                                        <div style="font-weight:800;margin-bottom:4px">اجبار به ورود</div>
                                        <div style="font-size:12px;color:#64748b">کاربران باید وارد حساب شوند</div>
                                    </div>
                                    <input type="checkbox" name="require_login" value="1" <?php checked($s['require_login'],1); ?> style="width:44px;height:24px">
                                </label>
                            </div>
                            
                            <div>
                                <label style="font-weight:800;display:block;margin-bottom:10px">حالت اعمال</label>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                                    <label style="border:2px solid <?php echo $s['apply_mode']=='all'?'#1e6bff':'#e2e8f0'; ?>;border-radius:12px;padding:14px;cursor:pointer;background:<?php echo $s['apply_mode']=='all'?'#eff6ff':'#fff'; ?>">
                                        <input type="radio" name="apply_mode" value="all" <?php checked($s['apply_mode'],'all'); ?> style="margin-left:8px"> همه محصولات
                                    </label>
                                    <label style="border:2px solid <?php echo $s['apply_mode']=='digital'?'#1e6bff':'#e2e8f0'; ?>;border-radius:12px;padding:14px;cursor:pointer;background:<?php echo $s['apply_mode']=='digital'?'#eff6ff':'#fff'; ?>">
                                        <input type="radio" name="apply_mode" value="digital" <?php checked($s['apply_mode'],'digital'); ?> style="margin-left:8px"> فقط دیجیتال
                                    </label>
                                </div>
                            </div>
                            
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:18px">
                                <label style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;margin-bottom:12px">
                                    <div style="font-weight:800">کد تخفیف</div>
                                    <input type="checkbox" name="coupon_enabled" value="1" <?php checked($s['coupon_enabled'],1); ?>>
                                </label>
                                <input type="text" name="coupon_label" value="<?php echo esc_attr($s['coupon_label']); ?>" placeholder="متن لیبل" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;margin-bottom:8px">
                                <input type="text" name="coupon_placeholder" value="<?php echo esc_attr($s['coupon_placeholder']); ?>" placeholder="متن placeholder" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px">
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($tab === 'appearance'): ?>
                        <h2 style="margin-top:0;display:flex;align-items:center;gap:8px"><span class="dashicons dashicons-art"></span> ظاهر و استایل</h2>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:24px">
                            <div><label style="font-weight:700;display:block;margin-bottom:6px">رنگ اصلی</label><input type="text" name="primary_color" value="<?php echo esc_attr($s['primary_color']); ?>" class="fcui-color" data-default-color="#1e6bff"></div>
                            <div><label style="font-weight:700;display:block;margin-bottom:6px">رنگ ثانویه</label><input type="text" name="secondary_color" value="<?php echo esc_attr($s['secondary_color']); ?>" class="fcui-color"></div>
                            <div><label style="font-weight:700;display:block;margin-bottom:6px">پس‌زمینه</label><input type="text" name="background_color" value="<?php echo esc_attr($s['background_color']); ?>" class="fcui-color"></div>
                            <div></div>
                            <div><label style="font-weight:700;display:block;margin-bottom:6px">گردی کارت (px)</label><input type="number" name="card_radius" value="<?php echo (int)$s['card_radius']; ?>" min="0" max="30" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px"></div>
                            <div><label style="font-weight:700;display:block;margin-bottom:6px">گردی دکمه (px)</label><input type="number" name="button_radius" value="<?php echo (int)$s['button_radius']; ?>" min="0" max="30" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px"></div>
                        </div>
                        <div style="margin-top:20px;display:grid;gap:12px">
                            <input type="text" name="button_pay_text" value="<?php echo esc_attr($s['button_pay_text']); ?>" placeholder="متن دکمه پرداخت" style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font-weight:700">
                            <input type="text" name="button_later_text" value="<?php echo esc_attr($s['button_later_text']); ?>" placeholder="متن دکمه بعدا" style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px">
                            <input type="text" name="button_free_text" value="<?php echo esc_attr($s['button_free_text']); ?>" placeholder="متن ثبت نام رایگان" style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px">
                            <input type="text" name="hint_text" value="<?php echo esc_attr($s['hint_text']); ?>" placeholder="متن راهنما" style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px">
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($tab === 'physical'): ?>
                        <h2 style="margin-top:0">محصولات فیزیکی</h2>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin:20px 0">
                            <label><input type="checkbox" name="physical_enabled" value="1" <?php checked($s['physical_enabled'],1); ?>> فعال‌سازی پرداخت سریع برای محصولات فیزیکی</label>
                        </div>
                        <h3>فیلدهای آدرس</h3>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                            <?php $fields = ['billing_state'=>'استان','billing_city'=>'شهر','billing_address_1'=>'آدرس کامل','billing_postcode'=>'کد پستی']; 
                            foreach ($fields as $k=>$l): ?>
                            <label style="background:#fff;border:1px solid #e2e8f0;padding:12px;border-radius:10px"><input type="checkbox" name="physical_fields[]" value="<?php echo $k; ?>" <?php checked(in_array($k,(array)$s['physical_fields'])); ?>> <?php echo $l; ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top:20px;background:#f8fafc;padding:16px;border-radius:12px">
                            <label><input type="checkbox" name="enable_national_code" value="1" <?php checked($s['enable_national_code'],1); ?>> نمایش کد ملی</label>
                            <label style="margin-right:20px"><input type="checkbox" name="national_code_required" value="1" <?php checked($s['national_code_required'],1); ?>> اجباری</label>
                            <input type="text" name="national_code_label" value="<?php echo esc_attr($s['national_code_label']); ?>" style="margin-top:8px;padding:8px;border:1px solid #cbd5e1;border-radius:8px">
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($tab === 'card2card'): ?>
                        <h2 style="margin-top:0;display:flex;align-items:center;gap:8px"><span class="dashicons dashicons-bank"></span> کارت به کارت - بانک‌های ایرانی</h2>
                        <div style="margin-top:20px">
                            <label style="display:flex;align-items:center;gap:10px;margin-bottom:16px"><input type="checkbox" name="c2c_enabled" value="1" <?php checked($s['c2c_enabled'],1); ?>> فعال باشد</label>
                            
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                                <div><label>شماره کارت</label><input type="text" name="c2c_card_number" value="<?php echo esc_attr($s['c2c_card_number']); ?>" dir="ltr" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;font-family:monospace"></div>
                                <div><label>نام صاحب کارت</label><input type="text" name="c2c_holder_name" value="<?php echo esc_attr($s['c2c_holder_name']); ?>" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px"></div>
                            </div>
                            
                            <div style="margin-top:16px">
                                <label style="font-weight:800;display:block;margin-bottom:10px">انتخاب تم بانک</label>
                                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
                                    <?php 
                                    $banks = [
                                        'melli' => ['بانک ملی', '#00a95c'],
                                        'mellat' => ['بانک ملت', '#d81e05'],
                                        'saderat' => ['صادرات', '#004e92'],
                                        'keshavarzi' => ['کشاورزی', '#4caf50'],
                                        'tejarat' => ['تجارت', '#0054a6'],
                                        'pasargad' => ['پاسارگاد', '#fdb813'],
                                        'blubank' => ['بلوبانک', '#0099ff'],
                                        'sepah' => ['سپه', '#8b4513'],
                                    ];
                                    foreach ($banks as $key => $b): ?>
                                    <label style="border:3px solid <?php echo $s['c2c_theme']==$key?'#1e6bff':'#e2e8f0'; ?>;border-radius:12px;padding:10px;text-align:center;cursor:pointer;background:<?php echo $b[1]; ?>;color:#fff;position:relative">
                                        <input type="radio" name="c2c_theme" value="<?php echo $key; ?>" <?php checked($s['c2c_theme'],$key); ?> style="position:absolute;opacity:0">
                                        <div style="font-weight:900;font-size:12px"><?php echo $b[0]; ?></div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px">
                                <div><label>تایمر (دقیقه)</label><input type="number" name="c2c_timer_minutes" value="<?php echo (int)$s['c2c_timer_minutes']; ?>" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px"></div>
                                <div><label>حداکثر حجم (MB)</label><input type="number" name="c2c_max_mb" value="<?php echo (int)$s['c2c_max_mb']; ?>" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px"></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($tab === 'messages'): ?>
                        <h2>پیام‌ها</h2>
                        <div style="display:grid;gap:14px;margin-top:20px">
                            <input type="text" name="success_page_title" value="<?php echo esc_attr($s['success_page_title']); ?>" placeholder="عنوان صفحه موفقیت" style="padding:12px;border:1px solid #cbd5e1;border-radius:10px">
                            <textarea name="success_page_online_message" rows="3" placeholder="پیام پرداخت آنلاین" style="padding:12px;border:1px solid #cbd5e1;border-radius:10px"><?php echo esc_textarea($s['success_page_online_message']); ?></textarea>
                            <textarea name="success_page_c2c_message" rows="3" placeholder="پیام کارت به کارت" style="padding:12px;border:1px solid #cbd5e1;border-radius:10px"><?php echo esc_textarea($s['success_page_c2c_message']); ?></textarea>
                            <input type="url" name="course_access_link" value="<?php echo esc_attr($s['course_access_link']); ?>" placeholder="لینک دسترسی به دوره" dir="ltr" style="padding:12px;border:1px solid #cbd5e1;border-radius:10px">
                        </div>
                        <?php endif; ?>
                        
                        <div style="margin-top:30px;padding-top:20px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center">
                            <div style="font-size:12px;color:#64748b">تغییرات در تب «<?php echo $tabs[$tab]['label']; ?>» ذخیره می‌شود</div>
                            <button class="button button-primary" name="fcui_save" value="1" style="padding:10px 24px;height:auto;font-size:14px;font-weight:800;background:linear-gradient(135deg,#1e6bff,#0ea5e9);border:0;border-radius:10px;box-shadow:0 4px 12px rgba(30,107,255,.3)">💾 ذخیره تنظیمات</button>
                        </div>
                    </form>
                </div>
            </div>
            <script>jQuery(document).ready(function($){$('.fcui-color').wpColorPicker({change:function(){}});});</script>
        </div>
        <?php
    }

    public static function admin_card2card_orders_page() {
        // ... (same as before, omitted for brevity - keep original)
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
            <h1>📚 آموزش پرداخت سریع v2.2</h1>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:30px;margin-top:20px">
                <h2 style="color:#1e6bff">🎯 ویژگی‌های جدید</h2>
                <ul style="line-height:2.2">
                    <li>✅ <strong>کمپین زماندار</strong> - در ویرایش محصول، قیمت و زمان شروع/پایان را تنظیم کنید</li>
                    <li>✅ <strong>تایمر شمارش معکوس</strong> - در صفحه محصول و پرداخت نمایش داده می‌شود</li>
                    <li>✅ <strong>تم‌های بانکی ایرانی</strong> - 8 بانک: ملی، ملت، صادرات، کشاورزی، تجارت، پاسارگاد، بلوبانک، سپه</li>
                    <li>✅ <strong>داشبورد هوشمند</strong> - نرخ تبدیل، درآمد امروز، کمپین‌های فعال</li>
                    <li>✅ <strong>تنظیمات گرافیکی</strong> - رابط کاربری جدید و مدرن</li>
                </ul>
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