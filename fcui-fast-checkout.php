<?php
/**
 * Plugin Name: سه سوته - پرداخت سریع
 * Plugin URI: https://www.rtl-theme.com
 * Description: این پلاگین صفحه ی پرداخت سریع را جایگزین فرایند سفارش ووکامرس میکند. نسخه پیشرفته با پشتیبانی محصولات فیزیکی، کد تخفیف، کمپین زماندار و پنل مدیریت حرفه‌ای ، به همراه سیستم و داشبورد کارت به کارت اختصاصی
 * Version: 2.5.5
 * Author: امیر حسین سعادتی
 * Author URI: https://www.rtl-theme.com/author/amirravi/
 * Text Domain: 3soote
 */
if (!defined('ABSPATH')) exit;

final class FCUI_Fast_Checkout {

    const OPT_FAST_PAGE_ID = 'fcui_fast_checkout_page_id';
    const OPT_C2C_PAGE_ID  = 'fcui_card2card_page_id';
    const OPT_SETTINGS     = 'fcui_settings';
    const VERSION = '2.5.5';

    const FAST_PAGE_TITLE = 'پرداخت سریع';
    const FAST_PAGE_SLUG  = 'fast-checkout';
    const C2C_PAGE_TITLE  = 'کارت به کارت';
    const C2C_PAGE_SLUG   = 'card-to-card';

    public static function init() {
        add_action('plugins_loaded', [__CLASS__, 'bootstrap']);
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('admin_head', [__CLASS__, 'suppress_foreign_admin_notices'], 0);
    }

    public static function bootstrap() {
        if (!class_exists('WooCommerce')) return;

        require_once __DIR__ . '/includes/class-fcui-gateway-card2card.php';
        add_filter('woocommerce_payment_gateways', [__CLASS__, 'register_gateway']);

        add_action('template_redirect', [__CLASS__, 'render_standalone_fast_checkout'], 1);
        add_action('template_redirect', [__CLASS__, 'render_standalone_card2card'], 1);
        add_action('template_redirect', [__CLASS__, 'maybe_redirect_from_myaccount'], 0);
        add_action('template_redirect', [__CLASS__, 'maybe_redirect_checkout_to_fast'], 2);
        
        add_filter('woocommerce_login_redirect', [__CLASS__, 'woocommerce_login_redirect'], 10, 2);
        add_filter('woocommerce_registration_redirect', [__CLASS__, 'woocommerce_registration_redirect'], 10, 1);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_head', [__CLASS__, 'output_dynamic_styles']);

        add_filter('woocommerce_product_add_to_cart_url', [__CLASS__, 'filter_add_to_cart_url'], 10, 2);
        add_filter('woocommerce_add_to_cart_redirect', [__CLASS__, 'add_to_cart_redirect'], 99, 2);
        add_filter('woocommerce_get_checkout_url', [__CLASS__, 'filter_checkout_url'], 99);
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

        // Ajax coupon validation for fast checkout
        add_action('wp_ajax_fcui_validate_coupon', [__CLASS__, 'ajax_validate_coupon']);
        add_action('wp_ajax_nopriv_fcui_validate_coupon', [__CLASS__, 'ajax_validate_coupon']);
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

    public static function get_bank_themes() {
        return [
            'melli'      => ['label' => 'بانک ملی', 'color' => '#0f4d88', 'image' => 'ملی.webp'],
            'mellat'     => ['label' => 'بانک ملت', 'color' => '#b00014', 'image' => 'ملت.webp'],
            'saderat'    => ['label' => 'بانک صادرات', 'color' => '#073a91', 'image' => 'صادرات.webp'],
            'keshavarzi' => ['label' => 'بانک کشاورزی', 'color' => '#0f5f34', 'image' => 'کشاورزی.webp'],
            'tejarat'    => ['label' => 'بانک تجارت', 'color' => '#062a60', 'image' => 'تجارت.webp'],
            'pasargad'   => ['label' => 'بانک پاسارگاد', 'color' => '#151515', 'image' => 'پاسارگاد.webp'],
            'saman'      => ['label' => 'بانک سامان', 'color' => '#0b69b7', 'image' => 'سامان.webp'],
            'sepah'      => ['label' => 'بانک سپه', 'color' => '#151515', 'image' => 'سپه.webp'],
        ];
    }


    public static function get_font_options() {
        return [
            '' => 'فونت قالب / پیش‌فرض',
            'FCUI_IRANSans' => 'ایران‌سنس عدد فارسی',
            'FCUI_IRANYekan' => 'ایران‌یکان',
            'FCUI_Dana' => 'دانا',
            'FCUI_Kalameh' => 'کلمه',
            'FCUI_Peyda' => 'پیدا',
        ];
    }

    public static function get_checkout_themes() {
        return [
            'classic' => 'کلاسیک پیش‌فرض',
            'neumorphic' => 'نئومورفیک',
            'skeuomorphic' => 'اسکئومورفیک',
            'dark_classic' => 'کلاسیک تاریک',
        ];
    }

    public static function fa_digits($value) {
        return strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }

    public static function latin_digits($value) {
        return strtr((string)$value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    }

    public static function clean_card_number($value) {
        return preg_replace('/\D+/', '', self::latin_digits($value));
    }

    public static function format_card_number($value) {
        $digits = self::clean_card_number($value);
        if (!$digits) return self::fa_digits($value);
        return self::fa_digits(trim(chunk_split($digits, 4, ' ')));
    }

    public static function price_html($amount) {
        $decimals = wc_get_price_decimals();
        $number = number_format_i18n((float)$amount, $decimals);
        if ($decimals > 0) {
            $decimal_sep = wc_get_price_decimal_separator();
            $number = rtrim(rtrim($number, '0'), $decimal_sep);
        }
        $symbol = get_woocommerce_currency_symbol();
        return '<span class="fcui-price-amount">' . esc_html(self::fa_digits($number)) . '</span> <span class="fcui-price-currency">' . esc_html($symbol) . '</span>';
    }

    private static function jalali_to_gregorian($jy, $jm, $jd) {
        $jy=(int)$jy; $jm=(int)$jm; $jd=(int)$jd; $jy += 1595;
        $days = -355668 + (365*$jy) + ((int)($jy/33))*8 + (int)((($jy%33)+3)/4) + $jd + (($jm < 7) ? (($jm-1)*31) : ((($jm-7)*30)+186));
        $gy = 400 * (int)($days / 146097); $days %= 146097;
        if ($days > 36524) { $gy += 100 * (int)(--$days / 36524); $days %= 36524; if ($days >= 365) $days++; }
        $gy += 4 * (int)($days / 1461); $days %= 1461;
        if ($days > 365) { $gy += (int)(($days-1)/365); $days = ($days-1)%365; }
        $gd = $days + 1;
        $sal_a = [0,31,(($gy%4==0 && $gy%100!=0) || ($gy%400==0))?29:28,31,30,31,30,31,31,30,31,30,31];
        for ($gm=1; $gm<=12 && $gd>$sal_a[$gm]; $gm++) $gd -= $sal_a[$gm];
        return [$gy,$gm,$gd];
    }

    private static function gregorian_to_jalali($gy, $gm, $gd) {
        $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100) + (int)(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * (int)($days / 12053));
        $days %= 12053;
        $jy += 4 * (int)($days / 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += (int)(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + (int)($days / 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + (int)(($days - 186) / 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }

    public static function jalali_datetime($timestamp = null, $with_time = true) {
        $timestamp = $timestamp ?: current_time('timestamp');
        [$jy, $jm, $jd] = self::gregorian_to_jalali((int)wp_date('Y', $timestamp), (int)wp_date('n', $timestamp), (int)wp_date('j', $timestamp));
        $out = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        if ($with_time) $out .= ' ' . wp_date('H:i', $timestamp, wp_timezone());
        return self::fa_digits($out);
    }

    private static function normalize_campaign_datetime($value) {
        $value = trim(self::latin_digits($value));
        if (!$value) return '';
        if (preg_match('/^(13|14)\d{2}[\/\-](\d{1,2})[\/\-](\d{1,2})(?:\s+(\d{1,2}):(\d{1,2}))?$/', $value, $m)) {
            [$gy,$gm,$gd] = self::jalali_to_gregorian((int)substr($m[0],0,4), (int)$m[2], (int)$m[3]);
            $hh = isset($m[4]) ? min(23, max(0, (int)$m[4])) : 0;
            $mi = isset($m[5]) ? min(59, max(0, (int)$m[5])) : 0;
            return sprintf('%04d-%02d-%02d %02d:%02d', $gy, $gm, $gd, $hh, $mi);
        }
        return sanitize_text_field($value);
    }

    public static function default_card_styles() {
        $base = [
            'number' => ['x' => 50, 'y' => 57, 'color' => '#ffffff', 'size' => 22, 'weight' => 900, 'shadow' => 1],
            'holder' => ['x' => 50, 'y' => 73, 'color' => '#ffffff', 'size' => 14, 'weight' => 800, 'shadow' => 1],
        ];
        $styles = [];
        foreach (self::get_bank_themes() as $key => $bank) {
            $styles[$key] = $base;
        }
        $styles['pasargad']['number']['color'] = '#f5d27a';
        $styles['pasargad']['holder']['color'] = '#f5d27a';
        $styles['sepah']['number']['color'] = '#f5d27a';
        $styles['sepah']['holder']['color'] = '#f5d27a';
        $styles['mellat']['number']['color'] = '#f8d36b';
        $styles['mellat']['holder']['color'] = '#ffffff';
        $styles['keshavarzi']['number']['color'] = '#f5d27a';
        $styles['keshavarzi']['holder']['color'] = '#f5d27a';
        return $styles;
    }

    private static function sanitize_card_styles($posted, $current = []) {
        $defaults = self::default_card_styles();
        $current = is_array($current) ? $current : [];
        $posted = is_array($posted) ? wp_unslash($posted) : [];
        $out = [];
        foreach (self::get_bank_themes() as $bank_key => $bank) {
            foreach (['number','holder'] as $field) {
                $src = $posted[$bank_key][$field] ?? ($current[$bank_key][$field] ?? ($defaults[$bank_key][$field] ?? []));
                $def = $defaults[$bank_key][$field];
                $out[$bank_key][$field] = [
                    'x' => max(0, min(100, (float)($src['x'] ?? $def['x']))),
                    'y' => max(0, min(100, (float)($src['y'] ?? $def['y']))),
                    'color' => sanitize_hex_color($src['color'] ?? $def['color']) ?: $def['color'],
                    'size' => max(8, min(48, absint($src['size'] ?? $def['size']))),
                    'weight' => max(100, min(1000, absint($src['weight'] ?? $def['weight']))),
                    'shadow' => !empty($src['shadow']) ? 1 : 0,
                ];
            }
        }
        return $out;
    }

    public static function get_card_style($theme = '') {
        $s = self::get_settings();
        $theme = sanitize_key($theme ?: ($s['c2c_theme'] ?? 'melli'));
        if ($theme === 'blubank') $theme = 'saman';
        $styles = self::sanitize_card_styles($s['c2c_card_styles'] ?? [], $s['c2c_card_styles'] ?? []);
        $defaults = self::default_card_styles();
        return $styles[$theme] ?? ($defaults[$theme] ?? current($defaults));
    }

    public static function get_bank_card_image_url($theme = '') {
        $theme = sanitize_key($theme ?: 'melli');
        if ($theme === 'blubank') $theme = 'saman';
        $banks = self::get_bank_themes();
        if (empty($banks[$theme]['image'])) return '';
        return plugins_url('assets/cards/' . rawurlencode($banks[$theme]['image']), __FILE__);
    }

    public static function default_settings() {
        return [
            'version' => self::VERSION,
            'require_login' => 0,
            'apply_mode' => 'all',
            
            // ظاهر
            'primary_color' => '#1e6bff',
            'secondary_color' => '#0f172a',
            'background_color' => '#f5f8ff',
            'surface_color' => '#ffffff',
            'input_background' => '#f8fafc',
            'text_color' => '#0f172a',
            'border_color' => '#e2e8f0',
            'font_family' => '',
            'checkout_theme' => 'classic',
            'popup_mode' => 0,
            'checkout_max_width' => '980',
            'card_radius' => '16',
            'button_radius' => '16',
            'shadow_strength' => '8',
            'custom_css' => '',
            
            // متون
            'button_pay_text' => 'پرداخت و ثبت‌نام آنی',
            'button_later_text' => 'بعداً پرداخت می‌کنم',
            'button_free_text' => 'ثبت‌نام رایگان',
            'hint_text' => 'بعد از پرداخت موفق، دسترسی شما همان لحظه فعال می‌شود.',
            
            // ساخت خودکار حساب کاربری
            'auto_create_account' => 0,
            'auto_login_after_create' => 1,
            'send_account_email' => 1,
            'username_source' => 'phone', // phone | email
            'account_created_role' => 'customer',

            // کد تخفیف
            'coupon_enabled' => 1,
            'coupon_label' => 'کد تخفیف دارید؟',
            'coupon_placeholder' => 'کد را وارد کنید',
            
            // کارت به کارت
            'c2c_enabled' => 1,
            'c2c_card_number' => 'شماره کارت پذیرنده را وارد کنید',
            'c2c_holder_name' => 'نام صاحب کارت را وارد کنید',
            'c2c_bank_name'   => 'نام بانک',
            'c2c_theme'       => 'melli',
            'c2c_timer_minutes' => 20,
            'c2c_approved_status' => 'completed',
            'c2c_max_mb' => 3,
            'c2c_card_styles' => self::default_card_styles(),
            'c2c_show_incomplete_payments' => 0,
            
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
        $s = wp_parse_args($s, self::default_settings());

        // حذف مقادیر پیش‌فرض شخصی نسخه‌های قبلی برای تأیید مارکت و نصب تمیز
        if (($s['c2c_card_number'] ?? '') === implode('-', ['6037','9911','1234','5678'])) {
            $s['c2c_card_number'] = self::default_settings()['c2c_card_number'];
        }
        $legacy_holder = html_entity_decode('&#1575;&#1605;&#1740;&#1585;&#1581;&#1587;&#1740;&#1606; &#1587;&#1593;&#1575;&#1583;&#1578;&#1740;', ENT_QUOTES, 'UTF-8');
        $legacy_holder_spaced = html_entity_decode('&#1575;&#1605;&#1740;&#1585; &#1581;&#1587;&#1740;&#1606; &#1587;&#1593;&#1575;&#1583;&#1578;&#1740;', ENT_QUOTES, 'UTF-8');
        if (in_array(($s['c2c_holder_name'] ?? ''), [$legacy_holder, $legacy_holder_spaced], true)) {
            $s['c2c_holder_name'] = self::default_settings()['c2c_holder_name'];
        }
        return $s;
    }

    public static function save_settings($s) {
        $s['version'] = self::VERSION;
        update_option(self::OPT_SETTINGS, $s);
    }

    public static function is_fast_checkout_page() {
        $id = (int) get_option(self::OPT_FAST_PAGE_ID);
        if ($id && is_page($id)) return true;
        if (is_page(self::FAST_PAGE_SLUG)) return true;
        global $post;
        return ($post instanceof WP_Post) && has_shortcode((string)$post->post_content, 'fcui_fast_checkout');
    }

    public static function is_card2card_page() {
        $id = (int) get_option(self::OPT_C2C_PAGE_ID);
        if ($id && is_page($id)) return true;
        if (is_page(self::C2C_PAGE_SLUG)) return true;
        global $post;
        return ($post instanceof WP_Post) && has_shortcode((string)$post->post_content, 'fcui_card2card');
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
        $s = self::get_settings();
        $theme = sanitize_key($s['checkout_theme'] ?? 'classic');
        $legacy_themes = ['glass'=>'neumorphic','minimal'=>'classic','dark'=>'dark_classic','colorful'=>'skeuomorphic'];
        if (isset($legacy_themes[$theme])) $theme = $legacy_themes[$theme];
        if (!array_key_exists($theme, self::get_checkout_themes())) $theme = 'classic';
        if (self::is_fast_checkout_page()) {
            $classes[] = 'fcui-fast-checkout';
            $classes[] = 'fcui-style-' . $theme;
            if (!empty($s['popup_mode'])) $classes[] = 'fcui-popup-mode';
        }
        if (self::is_card2card_page()) {
            $classes[] = 'fcui-card2card';
            $classes[] = 'fcui-style-' . $theme;
            if (!empty($s['popup_mode'])) $classes[] = 'fcui-popup-mode';
        }
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
        echo '<p><label>شروع:</label><input type="text" class="fcui-jalali-datetime" name="fcui_campaign_start" value="'.esc_attr($start).'" placeholder="مثلاً ۱۴۰۳/۰۵/۲۰ ۱۴:۳۰" style="width:100%;direction:ltr"></p>';
        echo '<p><label>پایان:</label><input type="text" class="fcui-jalali-datetime" name="fcui_campaign_end" value="'.esc_attr($end).'" placeholder="مثلاً ۱۴۰۳/۰۵/۲۱ ۲۳:۵۹" style="width:100%;direction:ltr"></p>';
        echo '<p style="color:#64748b;font-size:12px">با کلیک روی فیلد، تقویم شمسی مینیمال برای انتخاب تاریخ و ساعت باز می‌شود.</p>';
        echo '<script>window.FCUI_JALALI_METABOX=1;</script>';
        echo '<p style="font-size:12px;color:#666">در بازه زمانی مشخص شده، قیمت محصول خودکار تغییر می‌کند و تایمر نمایش داده می‌شود.</p>';
    }

    public static function save_campaign_meta($post_id) {
        if (!isset($_POST['fcui_campaign_nonce']) || !wp_verify_nonce($_POST['fcui_campaign_nonce'], 'fcui_campaign')) return;
        update_post_meta($post_id, '_fcui_campaign_price', sanitize_text_field($_POST['fcui_campaign_price'] ?? ''));
        update_post_meta($post_id, '_fcui_campaign_start', self::normalize_campaign_datetime($_POST['fcui_campaign_start'] ?? ''));
        update_post_meta($post_id, '_fcui_campaign_end', self::normalize_campaign_datetime($_POST['fcui_campaign_end'] ?? ''));
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

    /**
     * جلوگیری از کش شدن صفحات داینامیک پلاگین
     * این کار باعث میشه پلاگین‌های کش مثل WP Rocket, LiteSpeed Cache, W3TC و CDN ها
     * صفحه رو کش نکنن و product_id از query string حذف نشه
     */
    private static function prevent_caching() {
        if (!defined('DONOTCACHEPAGE'))   define('DONOTCACHEPAGE', true);
        if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
        if (!defined('DONOTCACHEDB'))     define('DONOTCACHEDB', true);
        if (!defined('DONOTMINIFY'))      define('DONOTMINIFY', true);

        nocache_headers();

        // هدرهای اضافی برای CDN ها
        if (!headers_sent()) {
            header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            // مخصوص CDN های ایرانی (آروان، دراکلود)
            header('X-Accel-Expires: 0');
            header('Surrogate-Control: no-store');
        }
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

        if (!is_admin()) {
            $pid = is_product() ? get_queried_object_id() : 0;
            $enabled = $pid ? (self::is_enabled_for_product($pid) ? 1 : 0) : 1;
            if (!empty($s['popup_mode'])) wp_enqueue_style('fcui-fast-checkout', plugins_url('assets/fast-checkout.css', __FILE__), [], self::VERSION);
            wp_enqueue_script('fcui-product-redirect', plugins_url('assets/product-redirect.js', __FILE__), ['jquery'], self::VERSION, true);
            wp_localize_script('fcui-product-redirect', 'FCUI_REDIRECT', [
                'fast_url' => self::get_fast_checkout_url(),
                'enabled_for_product' => $enabled,
                'product_id' => (int)$pid,
                'require_login' => !empty($s['require_login']) ? 1 : 0,
                'is_logged_in' => is_user_logged_in() ? 1 : 0,
                'login_url' => self::get_theme_login_url(self::get_fast_checkout_url()),
                'popup_mode' => !empty($s['popup_mode']) ? 1 : 0,
                'style_theme' => sanitize_key($s['checkout_theme'] ?? 'classic'),
                'cart_fast_url' => self::get_fast_url_from_cart(),
            ]);
            wp_localize_script('fcui-product-redirect', 'FCUI_COUPON', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('fcui_coupon'),
            ]);
        }

        if (self::is_fast_checkout_page()) {
            wp_enqueue_style('fcui-fast-checkout', plugins_url('assets/fast-checkout.css', __FILE__), [], self::VERSION);
            wp_enqueue_script('fcui-checkout', plugins_url('assets/fast-checkout.js', __FILE__), ['jquery'], self::VERSION, true);
            wp_localize_script('fcui-checkout', 'FCUI_COUPON', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('fcui_coupon'),
            ]);
            wp_enqueue_script('fcui-fa-digits', plugins_url('assets/fa-digits.js', __FILE__), [], self::VERSION, true);
        }

        if (self::is_card2card_page()) {
            wp_enqueue_style('fcui-card2card', plugins_url('assets/card2card.css', __FILE__), [], self::VERSION);
            wp_enqueue_script('fcui-card2card', plugins_url('assets/card2card.js', __FILE__), [], self::VERSION, true);
            wp_enqueue_script('fcui-fa-digits', plugins_url('assets/fa-digits.js', __FILE__), [], self::VERSION, true);
        }
    }
    
    public static function output_dynamic_styles() {
        $s = self::get_settings();
        if (!self::is_fast_checkout_page() && !self::is_card2card_page() && !get_query_var('fcui_order_success') && empty($s['popup_mode'])) return;
        
        ?>
        <style id="fcui-dynamic">
            :root{
                --p: <?php echo esc_attr($s['primary_color']); ?>;
                --s: <?php echo esc_attr($s['secondary_color']); ?>;
                --bg: <?php echo esc_attr($s['background_color']); ?>;
                --card: <?php echo esc_attr($s['surface_color'] ?? '#ffffff'); ?>;
                --fcui-input-bg: <?php echo esc_attr($s['input_background'] ?? '#f8fafc'); ?>;
                --fcui-text: <?php echo esc_attr($s['text_color'] ?? '#0f172a'); ?>;
                --fcui-border: <?php echo esc_attr($s['border_color'] ?? '#e2e8f0'); ?>;
                --r: <?php echo (int)$s['card_radius']; ?>px;
                --br: <?php echo (int)$s['button_radius']; ?>px;
                --fcui-shadow: 0 <?php echo max(0,(int)($s['shadow_strength'] ?? 8)); ?>px <?php echo max(0,(int)($s['shadow_strength'] ?? 8))*4; ?>px rgba(15,23,42,.12);
                --fcui-max-width: <?php echo max(360, (int)($s['checkout_max_width'] ?? 980)); ?>px;
                <?php if (!empty($s['font_family'])): ?>--fcui-font: <?php echo esc_attr($s['font_family']); ?>;<?php endif; ?>
            }
            body.fcui-fast-checkout, body.fcui-card2card { background: var(--bg) !important; }
            .fcui, .fcui-c2c { color: var(--fcui-text); <?php if (!empty($s['font_family'])): ?>font-family: var(--fcui-font) !important;<?php endif; ?> }
            .fcui__app, .fcui-c2c__app { max-width: var(--fcui-max-width) !important; }
            .fcui input, .fcui select, .fcui textarea { background: var(--fcui-input-bg) !important; border-color: var(--fcui-border) !important; color: var(--fcui-text) !important; }
            .fcui__card, .fcui__summary, .fcui-c2c__upload, .fcui-c2c__payment-info { background: var(--card) !important; border-color: var(--fcui-border) !important; box-shadow: var(--fcui-shadow) !important; }
            .fcui__btn--primary, .fcui-c2c__submit { 
                background: linear-gradient(135deg, var(--p) 0%, color-mix(in srgb, var(--p) 80%, black) 100%) !important;
                border-radius: var(--br) !important;
            }
            .fcui__card, .fcui__summary, .fcui-c2c__upload { border-radius: var(--r) !important; }
            .fcui__step { background: color-mix(in srgb, var(--p) 15%, white) !important; color: var(--p) !important; }
            <?php if (!empty($s['custom_css'])) echo wp_strip_all_tags($s['custom_css']); ?>
        </style>
        <?php
    }

    public static function suppress_foreign_admin_notices() {
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if (strpos($page, 'fcui') !== 0) return;
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        remove_all_actions('network_admin_notices');
        remove_all_actions('user_admin_notices');
        echo '<style>.notice:not(.fcui-keep-notice),.updated:not(.fcui-keep-notice),.error:not(.fcui-keep-notice),.update-nag{display:none!important}</style>';
    }

    public static function admin_assets($hook) {
        if (strpos($hook, 'fcui') === false && !in_array($hook, ['post.php','post-new.php'], true)) return;
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_media();
        wp_enqueue_style('fcui-admin', plugins_url('assets/fast-checkout.css', __FILE__), [], self::VERSION);
        wp_enqueue_script('fcui-admin-card-designer', plugins_url('assets/admin-card-designer.js', __FILE__), ['jquery', 'wp-color-picker'], self::VERSION, true);
        wp_enqueue_script('fcui-fa-digits', plugins_url('assets/fa-digits.js', __FILE__), [], self::VERSION, true);
        wp_enqueue_script('fcui-jalali-admin', plugins_url('assets/jalali-admin.js', __FILE__), ['jquery'], self::VERSION, true);
    }

    public static function filter_add_to_cart_url($url, $product) {
        $pid = $product ? $product->get_id() : 0;
        if (!$pid) return $url;
        if (!self::is_enabled_for_product($pid)) return $url;
        return self::get_fast_checkout_url(['product_id' => $pid, 'quantity' => 1]);
    }

    public static function add_to_cart_redirect($url, $adding_to_cart = null) {
        $pid = 0;
        if ($adding_to_cart && is_object($adding_to_cart) && method_exists($adding_to_cart, 'get_id')) {
            $pid = (int)$adding_to_cart->get_id();
        }
        if (!$pid && isset($_REQUEST['add-to-cart'])) $pid = absint($_REQUEST['add-to-cart']);
        if (!$pid && isset($_REQUEST['product_id'])) $pid = absint($_REQUEST['product_id']);
        if (!$pid || !self::is_enabled_for_product($pid)) return $url;
        $qty = isset($_REQUEST['quantity']) ? max(1, absint($_REQUEST['quantity'])) : 1;
        return self::get_fast_checkout_url(['product_id' => $pid, 'quantity' => $qty]);
    }


    private static function get_fast_url_from_cart() {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) return '';
        $items = WC()->cart->get_cart();
        if (count($items) !== 1) return '';
        $item = reset($items);
        $pid = !empty($item['product_id']) ? (int)$item['product_id'] : 0;
        $vid = !empty($item['variation_id']) ? (int)$item['variation_id'] : 0;
        $qty = !empty($item['quantity']) ? max(1, (int)$item['quantity']) : 1;
        $target_id = $vid ?: $pid;
        if (!$target_id || !self::is_enabled_for_product($target_id)) return '';
        $args = ['product_id' => $pid, 'quantity' => $qty];
        if ($vid) $args['variation_id'] = $vid;
        if (!empty($item['variation']) && is_array($item['variation'])) {
            foreach ($item['variation'] as $k => $v) {
                if (strpos($k, 'attribute_') === 0 && $v !== '') $args[$k] = $v;
            }
        }
        return self::get_fast_checkout_url($args);
    }

    public static function filter_checkout_url($url) {
        $fast = self::get_fast_url_from_cart();
        return $fast ?: $url;
    }

    public static function maybe_redirect_checkout_to_fast() {
        if (is_admin() || self::is_fast_checkout_page() || self::is_card2card_page()) return;
        if (!function_exists('is_checkout') || !is_checkout()) return;
        if (function_exists('is_order_received_page') && is_order_received_page()) return;
        if (isset($_GET['order-received']) || isset($_GET['key'])) return;
        $fast = self::get_fast_url_from_cart();
        if (!$fast) return;
        wp_safe_redirect($fast);
        exit;
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

    /**
     * گرفتن پارامترهای URL با چند روش fallback
     * برای دور زدن مشکلات کش، CDN، rewrite های سفارشی و پلاگین‌هایی که $_GET رو دستکاری می‌کنن
     */
    private static function get_request_params() {
        $params = [];

        // روش 1: $_GET معمولی
        if (!empty($_GET)) {
            $params = $_GET;
        }

        // روش 2: $_REQUEST (fallback اگه $_GET خالی باشه)
        if (empty($params['product_id']) && !empty($_REQUEST['product_id'])) {
            $params = array_merge($params, $_REQUEST);
        }

        // روش 3: parsing مستقیم از REQUEST_URI (اگه کش/rewrite پارامترها رو پاک کرده)
        if (empty($params['product_id']) && !empty($_SERVER['REQUEST_URI'])) {
            $uri = wp_unslash($_SERVER['REQUEST_URI']);
            $qs  = parse_url($uri, PHP_URL_QUERY);
            if ($qs) {
                $parsed = [];
                parse_str($qs, $parsed);
                if (!empty($parsed['product_id'])) {
                    $params = array_merge($params, $parsed);
                }
            }
        }

        // روش 4: parsing از QUERY_STRING (بعضی هاست‌ها متفاوت رفتار می‌کنن)
        if (empty($params['product_id']) && !empty($_SERVER['QUERY_STRING'])) {
            $parsed = [];
            parse_str($_SERVER['QUERY_STRING'], $parsed);
            if (!empty($parsed['product_id'])) {
                $params = array_merge($params, $parsed);
            }
        }

        // روش 5: بازیابی از session اگه قبلاً ذخیره شده بود
        // (برای زمانی که بعد از ریدایرکت لاگین، پارامترها از دست رفتن)
        if (empty($params['product_id']) && !headers_sent()) {
            if (!session_id() && !is_admin()) {
                @session_start();
            }
            if (!empty($_SESSION['fcui_last_product_id'])) {
                $params['product_id'] = (int)$_SESSION['fcui_last_product_id'];
                if (!empty($_SESSION['fcui_last_variation_id'])) {
                    $params['variation_id'] = (int)$_SESSION['fcui_last_variation_id'];
                }
                if (!empty($_SESSION['fcui_last_quantity'])) {
                    $params['quantity'] = (int)$_SESSION['fcui_last_quantity'];
                }
                if (!empty($_SESSION['fcui_last_attributes']) && is_array($_SESSION['fcui_last_attributes'])) {
                    foreach ($_SESSION['fcui_last_attributes'] as $k => $v) {
                        $params[$k] = $v;
                    }
                }
            }
        }

        return $params;
    }

    private static function get_context_from_query() {
        $params = self::get_request_params();

        $product_id   = !empty($params['product_id']) ? absint($params['product_id']) : 0;
        $variation_id = !empty($params['variation_id']) ? absint($params['variation_id']) : 0;
        $qty          = !empty($params['quantity']) ? max(1, absint($params['quantity'])) : 1;

        if (!$product_id) return [null, null, [], 0, 0, 1, '', '', false];

        if (!self::is_enabled_for_product($product_id)) {
            return [null, null, [], 0, 0, 1, '', 'not_allowed', false];
        }

        $display_product = wc_get_product($variation_id ? $variation_id : $product_id);
        if (!$display_product) return [null, null, [], 0, 0, 1, '', '', false];

        $parent_product = wc_get_product($product_id);
        $is_physical = self::is_physical_product($display_product);

        $variation = [];
        foreach ($params as $k => $v) {
            if (strpos($k, 'attribute_') === 0) {
                $variation[sanitize_text_field($k)] = sanitize_text_field(wp_unslash($v));
            }
        }

        // ذخیره در session برای استفاده در صورت از دست رفتن پارامترها (مثلاً بعد از لاگین)
        if (!headers_sent()) {
            if (!session_id() && !is_admin()) {
                @session_start();
            }
            $_SESSION['fcui_last_product_id']   = $product_id;
            $_SESSION['fcui_last_variation_id'] = $variation_id;
            $_SESSION['fcui_last_quantity']     = $qty;
            $_SESSION['fcui_last_attributes']   = $variation;
        }

        $product_url = get_permalink($product_id);

        return [$display_product, $parent_product, $variation, $product_id, $variation_id, $qty, $product_url, 'ok', $is_physical];
    }

    /**
     * فیلتر کردن پارامترهای ضروری از $_GET برای جلوگیری از تداخل
     * بعضی پلاگین‌ها/CDN ها پارامترهای اضافی به URL می‌چسبونن که می‌تونه باعث مشکل بشه
     */
    private static function get_safe_query_args() {
        $params = self::get_request_params();
        $safe = [];
        $allowed = ['product_id', 'variation_id', 'quantity', 'order_id', 'key'];
        foreach ($allowed as $k) {
            if (!empty($params[$k])) {
                $safe[$k] = is_numeric($params[$k]) ? (int)$params[$k] : sanitize_text_field($params[$k]);
            }
        }
        // attribute_* ها
        foreach ($params as $k => $v) {
            if (strpos($k, 'attribute_') === 0) {
                $safe[sanitize_text_field($k)] = sanitize_text_field(wp_unslash($v));
            }
        }
        return $safe;
    }

    private static function enforce_login_or_redirect() {
        $s = self::get_settings();
        $require = !empty($s['require_login']) ? 1 : 0;
        if (!$require) return;

        // اگه ساخت خودکار حساب فعاله، نیازی به اجبار لاگین نیست
        // چون بعد از پر کردن فرم، خودش حساب می‌سازه
        if (!empty($s['auto_create_account'])) return;

        if (!is_user_logged_in()) {
            $args = self::get_safe_query_args();
            $redirect = self::is_fast_checkout_page()
                ? self::get_fast_checkout_url($args)
                : self::get_card2card_url($args);
            wp_safe_redirect(self::get_theme_login_url($redirect));
            exit;
        }
    }

    public static function render_standalone_fast_checkout() {
        if (!self::is_fast_checkout_page()) return;

        // جلوگیری از کش شدن صفحه توسط پلاگین‌های کش (WP Rocket, LiteSpeed, W3TC, ...) و CDN
        self::prevent_caching();

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

    /**
     * ساخت خودکار حساب کاربری از روی اطلاعات فرم
     * اگر کاربر از قبل با همین موبایل/ایمیل وجود داشته باشه، همون کاربر برمی‌گرده
     * در غیر این صورت یک کاربر جدید ساخته میشه
     *
     * @return int|WP_Error  user_id در صورت موفقیت
     */
    private static function maybe_create_user_account($first, $last, $phone, $email) {
        $s = self::get_settings();

        // اگر این قابلیت غیرفعاله، صفر برگردون
        if (empty($s['auto_create_account'])) return 0;

        // اگر کاربر فعلاً لاگین کرده، نیازی به ساخت نیست
        if (is_user_logged_in()) return get_current_user_id();

        // اول چک کنیم با این ایمیل یا شماره موبایل، کاربر قبلاً ثبت‌نام کرده یا نه
        $existing_user_id = 0;

        // چک بر اساس ایمیل
        if ($email && is_email($email)) {
            $u = get_user_by('email', $email);
            if ($u) $existing_user_id = (int)$u->ID;
        }

        // چک بر اساس شماره موبایل (یوزرنیم یا متاهای مرسوم)
        if (!$existing_user_id && $phone) {
            $clean_phone = preg_replace('/\D+/', '', $phone);
            // یوزرنیم
            $u = get_user_by('login', $phone);
            if (!$u) $u = get_user_by('login', $clean_phone);
            if ($u) {
                $existing_user_id = (int)$u->ID;
            } else {
                // متاهای مرسوم سایر افزونه‌ها
                global $wpdb;
                $meta_keys = ['billing_phone', 'digits_phone', 'mobile', 'user_mobile', 'phone'];
                foreach ($meta_keys as $mk) {
                    $uid = $wpdb->get_var($wpdb->prepare(
                        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND (meta_value=%s OR meta_value=%s) LIMIT 1",
                        $mk, $phone, $clean_phone
                    ));
                    if ($uid) { $existing_user_id = (int)$uid; break; }
                }
            }
        }

        // اگر کاربر قبلاً وجود داشت، لاگینش کن و برگرد
        if ($existing_user_id) {
            if (!empty($s['auto_login_after_create'])) {
                wp_clear_auth_cookie();
                wp_set_current_user($existing_user_id);
                wp_set_auth_cookie($existing_user_id, true);
            }
            return $existing_user_id;
        }

        // ساخت کاربر جدید
        $source = !empty($s['username_source']) ? $s['username_source'] : 'phone';

        if ($source === 'email' && $email && is_email($email)) {
            $base_username = sanitize_user(current(explode('@', $email)), true);
        } else {
            $base_username = sanitize_user(preg_replace('/\D+/', '', $phone), true);
        }

        if (!$base_username) $base_username = 'user_' . wp_generate_password(6, false, false);

        // اگر یوزرنیم تکراری بود، عدد بهش اضافه کن
        $username = $base_username;
        $i = 1;
        while (username_exists($username)) {
            $username = $base_username . $i;
            $i++;
            if ($i > 50) { $username = $base_username . '_' . wp_generate_password(4, false, false); break; }
        }

        // اگر ایمیل خالی یا تکراریه، ایمیل ساختگی بساز
        $final_email = $email;
        if (!$final_email || !is_email($final_email) || email_exists($final_email)) {
            $domain = parse_url(home_url(), PHP_URL_HOST);
            if (!$domain) $domain = 'example.com';
            $clean_phone = preg_replace('/\D+/', '', $phone);
            $final_email = ($clean_phone ?: $username) . '@' . $domain;
            // اگر بازم تکراری بود
            $j = 1;
            while (email_exists($final_email)) {
                $final_email = ($clean_phone ?: $username) . $j . '@' . $domain;
                $j++;
                if ($j > 50) break;
            }
        }

        $password = wp_generate_password(12);

        // ساخت کاربر
        $user_id = wp_create_user($username, $password, $final_email);

        if (is_wp_error($user_id)) return $user_id;

        // ست کردن نقش
        $role = !empty($s['account_created_role']) ? $s['account_created_role'] : 'customer';
        $u = new WP_User($user_id);
        $u->set_role($role);

        // ذخیره اطلاعات کاربر
        if ($first) {
            update_user_meta($user_id, 'first_name', $first);
            update_user_meta($user_id, 'billing_first_name', $first);
        }
        if ($last) {
            update_user_meta($user_id, 'last_name', $last);
            update_user_meta($user_id, 'billing_last_name', $last);
        }
        if ($phone) {
            update_user_meta($user_id, 'billing_phone', $phone);
            update_user_meta($user_id, 'phone', $phone);
        }
        if ($email && is_email($email)) {
            update_user_meta($user_id, 'billing_email', $email);
        }

        // متای نشانه‌گذاری برای بعداً (اگه نیاز شد گزارش بگیریم)
        update_user_meta($user_id, '_fcui_created_by_plugin', 1);
        update_user_meta($user_id, '_fcui_created_at', current_time('mysql'));

        // ارسال ایمیل خوش‌آمد (اطلاعات حساب)
        if (!empty($s['send_account_email']) && is_email($final_email) && strpos($final_email, '@' . parse_url(home_url(), PHP_URL_HOST)) === false) {
            // فقط اگه ایمیل واقعی باشه (نه ساختگی) ارسال کن
            wp_new_user_notification($user_id, null, 'user');
        }

        // لاگین خودکار
        if (!empty($s['auto_login_after_create'])) {
            wp_clear_auth_cookie();
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);
        }

        return $user_id;
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
                // اول از طریق سیستم ساخت خودکار حساب (اگه فعال باشه)
                $maybe_uid = self::maybe_create_user_account($first, $last, $phone, $email);
                if ($maybe_uid && !is_wp_error($maybe_uid)) {
                    $user_id = (int)$maybe_uid;
                } else {
                    // در غیر این صورت روش قدیمی (سازگاری با قبل)
                    $username = sanitize_user($phone);
                    if (!$username) $username = 'user_' . wp_generate_password(6, false, false);
                    $i = 1; $base = $username;
                    while (username_exists($username)) { $username = $base . $i; $i++; if ($i > 50) break; }
                    $password = wp_generate_password();
                    $fallback_email = $email;
                    if (!$fallback_email || email_exists($fallback_email)) {
                        $domain = parse_url(home_url(), PHP_URL_HOST) ?: 'example.com';
                        $fallback_email = preg_replace('/\D+/', '', $phone) . '@' . $domain;
                    }
                    $user_id = wp_create_user($username, $password, $fallback_email);
                    if (!is_wp_error($user_id)) {
                        wp_set_current_user($user_id);
                        wp_set_auth_cookie($user_id);
                        update_user_meta($user_id, 'first_name', $first);
                        update_user_meta($user_id, 'last_name', $last);
                        update_user_meta($user_id, 'billing_phone', $phone);
                    }
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

        // اگه کاربر لاگین نیست و قابلیت ساخت خودکار حساب فعاله، یه حساب بساز
        if (!is_user_logged_in()) {
            $created = self::maybe_create_user_account($first, $last, $phone, $email);
            if (is_wp_error($created)) {
                wp_safe_redirect(self::get_fast_checkout_url(['product_id' => $product_id, 'err' => 'register_failed']));
                exit;
            }
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

        // جلوگیری از کش شدن صفحه
        self::prevent_caching();

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
        if ($theme === 'blubank') $theme = 'saman';
        if (!array_key_exists($theme, self::get_bank_themes())) $theme = 'melli';
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

    private static function coupon_error_message($code) {
        $messages = [
            'missing' => 'کد تخفیف را وارد کنید.',
            'not_found' => 'کد تخفیف اشتباه است.',
            'expired' => 'مهلت استفاده از این کد تخفیف تمام شده است.',
            'usage_limit' => 'سقف استفاده از این کد تخفیف تکمیل شده است.',
            'user_usage_limit' => 'شما قبلاً از این کد تخفیف استفاده کرده‌اید.',
            'not_for_product' => 'این کد تخفیف برای این محصول قابل استفاده نیست.',
            'excluded_product' => 'این کد تخفیف برای این محصول مجاز نیست.',
            'min_amount' => 'مبلغ سفارش کمتر از حداقل مبلغ لازم برای این کد تخفیف است.',
            'max_amount' => 'مبلغ سفارش بیشتر از سقف مجاز این کد تخفیف است.',
        ];
        return $messages[$code] ?? 'این کد تخفیف قابل استفاده نیست.';
    }

    public static function ajax_validate_coupon() {
        check_ajax_referer('fcui_coupon', 'nonce');
        if (!function_exists('wc_get_product')) wp_send_json_error(['message' => 'ووکامرس فعال نیست.']);

        $code = wc_format_coupon_code(wp_unslash($_POST['coupon'] ?? ''));
        if (!$code) wp_send_json_error(['message' => self::coupon_error_message('missing')]);

        $product_id = absint($_POST['product_id'] ?? 0);
        $variation_id = absint($_POST['variation_id'] ?? 0);
        $qty = max(1, absint($_POST['quantity'] ?? 1));
        $product = wc_get_product($variation_id ?: $product_id);
        $parent_product = ($variation_id && $product_id) ? wc_get_product($product_id) : null;
        if (!$product) wp_send_json_error(['message' => 'محصول مشخص نیست.']);

        try { $coupon = new WC_Coupon($code); } catch (Exception $e) { $coupon = null; }
        if (!$coupon || !$coupon->get_id()) wp_send_json_error(['message' => self::coupon_error_message('not_found')]);

        $now = current_time('timestamp');
        $expires = $coupon->get_date_expires();
        if ($expires && $expires->getTimestamp() < $now) wp_send_json_error(['message' => self::coupon_error_message('expired')]);
        if ($coupon->get_usage_limit() && $coupon->get_usage_count() >= $coupon->get_usage_limit()) wp_send_json_error(['message' => self::coupon_error_message('usage_limit')]);

        if (is_user_logged_in() && $coupon->get_usage_limit_per_user()) {
            $used_by = (array)$coupon->get_used_by();
            $uid = get_current_user_id();
            $email = wp_get_current_user()->user_email;
            $used = 0;
            foreach ($used_by as $u) if ((string)$u === (string)$uid || ($email && strtolower($u) === strtolower($email))) $used++;
            if ($used >= $coupon->get_usage_limit_per_user()) wp_send_json_error(['message' => self::coupon_error_message('user_usage_limit')]);
        }

        $pid = $product->get_id();
        $parent_id = $parent_product ? $parent_product->get_id() : $pid;
        $coupon_products = array_map('intval', (array)$coupon->get_product_ids());
        if (!empty($coupon_products) && !in_array($pid, $coupon_products, true) && !in_array($parent_id, $coupon_products, true)) {
            wp_send_json_error(['message' => self::coupon_error_message('not_for_product')]);
        }
        $excluded_products = array_map('intval', (array)$coupon->get_excluded_product_ids());
        if (!empty($excluded_products) && (in_array($pid, $excluded_products, true) || in_array($parent_id, $excluded_products, true))) {
            wp_send_json_error(['message' => self::coupon_error_message('excluded_product')]);
        }

        $product_cats = wc_get_product_cat_ids($parent_id);
        $coupon_cats = array_map('intval', (array)$coupon->get_product_categories());
        if (!empty($coupon_cats) && empty(array_intersect($product_cats, $coupon_cats))) {
            wp_send_json_error(['message' => self::coupon_error_message('not_for_product')]);
        }
        $excluded_cats = array_map('intval', (array)$coupon->get_excluded_product_categories());
        if (!empty($excluded_cats) && !empty(array_intersect($product_cats, $excluded_cats))) {
            wp_send_json_error(['message' => self::coupon_error_message('excluded_product')]);
        }

        $subtotal = (float)$product->get_price() * $qty;
        if ($coupon->get_minimum_amount() && $subtotal < (float)$coupon->get_minimum_amount()) wp_send_json_error(['message' => self::coupon_error_message('min_amount')]);
        if ($coupon->get_maximum_amount() && $subtotal > (float)$coupon->get_maximum_amount()) wp_send_json_error(['message' => self::coupon_error_message('max_amount')]);

        $discount = 0;
        $type = $coupon->get_discount_type();
        $amount = (float)$coupon->get_amount();
        if ($type === 'percent') $discount = ($subtotal * $amount) / 100;
        elseif (in_array($type, ['fixed_product'], true)) $discount = $amount * $qty;
        else $discount = $amount;
        $discount = min($subtotal, max(0, $discount));

        wp_send_json_success([
            'message' => 'کد تخفیف اعمال شد.',
            'discount' => self::price_html($discount),
            'total' => self::price_html(max(0, $subtotal - $discount)),
        ]);
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
        add_submenu_page('fcui-dashboard', 'گزارش و تحلیل', 'گزارش و تحلیل', 'manage_woocommerce', 'fcui-analytics', [__CLASS__, 'admin_analytics_page']);
        add_submenu_page('fcui-dashboard', 'سفارشات کارت به کارت', 'کارت به کارت', 'manage_woocommerce', 'fcui-card2card-orders', [__CLASS__, 'admin_card2card_orders_page']);
        add_submenu_page('fcui-dashboard', 'آموزش', 'آموزش و راهنما', 'manage_woocommerce', 'fcui-tutorial', [__CLASS__, 'admin_tutorial_page']);
    }

    public static function admin_dashboard() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = self::get_settings();
        
        // آمار پیشرفته
        $orders_c2c = wc_get_orders(['limit' => -1, 'payment_method' => 'fcui_card2card', 'status' => 'on-hold', 'meta_key' => '_fcui_c2c_receipt_1', 'meta_compare' => 'EXISTS', 'return' => 'ids']);
        $orders_fast = wc_get_orders(['limit' => -1, 'meta_key' => '_fcui_fast_checkout', 'return' => 'ids']);
        $orders_success = wc_get_orders(['limit' => -1, 'meta_key' => '_fcui_fast_checkout', 'status' => ['processing','completed'], 'return' => 'ids']);
        $orders_today = wc_get_orders(['date_created' => '>=' . wp_date('Y-m-d 00:00:00'), 'return' => 'ids']);
        
        $revenue_today = 0;
        foreach ($orders_today as $oid) {
            $o = wc_get_order($oid);
            if ($o && $o->has_status(['processing','completed'])) $revenue_today += (float)$o->get_total();
        }
        
        $total_orders = wc_get_orders(['limit' => -1, 'return' => 'ids']);
        $conversion = count($orders_fast) > 0 ? round((count($orders_success) / count($orders_fast)) * 100, 1) : 0;
        
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
                    <div style="font-size:12px;opacity:.85">درآمد: <?php echo FCUI_Fast_Checkout::price_html($revenue_today); ?></div>
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
                $new_settings['popup_mode'] = isset($_POST['popup_mode']) ? 1 : 0;

                // ساخت خودکار حساب
                $new_settings['auto_create_account'] = isset($_POST['auto_create_account']) ? 1 : 0;
                $new_settings['auto_login_after_create'] = isset($_POST['auto_login_after_create']) ? 1 : 0;
                $new_settings['send_account_email'] = isset($_POST['send_account_email']) ? 1 : 0;
                $new_settings['username_source'] = in_array(($_POST['username_source'] ?? 'phone'), ['phone','email']) ? $_POST['username_source'] : 'phone';
                $new_settings['account_created_role'] = sanitize_key($_POST['account_created_role'] ?? 'customer');
            }
            
            if ($tab === 'appearance') {
                $new_settings['primary_color'] = sanitize_hex_color($_POST['primary_color'] ?? '#1e6bff');
                $new_settings['secondary_color'] = sanitize_hex_color($_POST['secondary_color'] ?? '#0f172a');
                $new_settings['background_color'] = sanitize_hex_color($_POST['background_color'] ?? '#f5f8ff');
                $new_settings['surface_color'] = sanitize_hex_color($_POST['surface_color'] ?? '#ffffff');
                $new_settings['input_background'] = sanitize_hex_color($_POST['input_background'] ?? '#f8fafc');
                $new_settings['text_color'] = sanitize_hex_color($_POST['text_color'] ?? '#0f172a');
                $new_settings['border_color'] = sanitize_hex_color($_POST['border_color'] ?? '#e2e8f0');
                $font = sanitize_text_field($_POST['font_family'] ?? '');
                $new_settings['font_family'] = array_key_exists($font, self::get_font_options()) ? $font : '';
                $theme = sanitize_key($_POST['checkout_theme'] ?? 'classic');
                $legacy_themes = ['glass'=>'neumorphic','minimal'=>'classic','dark'=>'dark_classic','colorful'=>'skeuomorphic'];
                if (isset($legacy_themes[$theme])) $theme = $legacy_themes[$theme];
                $new_settings['checkout_theme'] = array_key_exists($theme, self::get_checkout_themes()) ? $theme : 'classic';
                $new_settings['checkout_max_width'] = max(360, absint($_POST['checkout_max_width'] ?? 980));
                $new_settings['card_radius'] = absint($_POST['card_radius'] ?? 16);
                $new_settings['button_radius'] = absint($_POST['button_radius'] ?? 16);
                $new_settings['shadow_strength'] = max(0, min(30, absint($_POST['shadow_strength'] ?? 8)));
                $new_settings['custom_css'] = wp_strip_all_tags($_POST['custom_css'] ?? '');
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
                $allowed_themes = array_keys(self::get_bank_themes());
                $new_settings['c2c_theme'] = sanitize_key($_POST['c2c_theme'] ?? 'melli');
                if (!in_array($new_settings['c2c_theme'], $allowed_themes, true)) $new_settings['c2c_theme'] = 'melli';
                $new_settings['c2c_timer_minutes'] = absint($_POST['c2c_timer_minutes'] ?? 20);
                $new_settings['c2c_max_mb'] = absint($_POST['c2c_max_mb'] ?? 3);
                $new_settings['c2c_card_styles'] = self::sanitize_card_styles($_POST['c2c_card_styles'] ?? [], $s['c2c_card_styles'] ?? []);
                $new_settings['c2c_show_incomplete_payments'] = isset($_POST['c2c_show_incomplete_payments']) ? 1 : 0;
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
                            <div style="margin-top:16px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px">
                                <label class="fcui-admin-switch-row"><input type="checkbox" name="popup_mode" value="1" <?php checked(($s['popup_mode'] ?? 0),1); ?>> <span>باز شدن فرم پرداخت به صورت پاپ‌آپ سریع</span></label>
                                <p style="margin:8px 0 0;color:#64748b;font-size:12px">در این حالت با کلیک روی افزودن به سبد خرید، فرم پرداخت بدون ترک صفحه و بدون iframe نمایش داده می‌شود.</p>
                            </div>

                            <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1px solid #93c5fd;border-radius:14px;padding:18px">
                                <label style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;margin-bottom:6px">
                                    <div>
                                        <div style="font-weight:800;margin-bottom:4px;display:flex;align-items:center;gap:6px">
                                            <span class="dashicons dashicons-id-alt" style="color:#1e6bff"></span>
                                            ساخت خودکار حساب کاربری
                                            <span style="background:#1e6bff;color:#fff;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:800">جدید</span>
                                        </div>
                                        <div style="font-size:12px;color:#475569">اگه کاربر لاگین نیست، بعد از پر کردن فرم خودکار حساب بساز و وارد کن (بدون نیاز به صفحه ثبت‌نام)</div>
                                    </div>
                                    <input type="checkbox" name="auto_create_account" value="1" <?php checked($s['auto_create_account'],1); ?> style="width:44px;height:24px">
                                </label>

                                <div style="margin-top:14px;padding-top:14px;border-top:1px dashed #cbd5e1;display:grid;gap:10px">
                                    <div>
                                        <label style="font-weight:700;display:block;margin-bottom:6px;font-size:13px">منبع نام کاربری</label>
                                        <select name="username_source" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px">
                                            <option value="phone" <?php selected($s['username_source'],'phone'); ?>>شماره موبایل</option>
                                            <option value="email" <?php selected($s['username_source'],'email'); ?>>ایمیل (قبل از @)</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label style="font-weight:700;display:block;margin-bottom:6px;font-size:13px">نقش کاربر جدید</label>
                                        <select name="account_created_role" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px">
                                            <?php
                                            $current_role = !empty($s['account_created_role']) ? $s['account_created_role'] : 'customer';
                                            $roles = function_exists('wp_roles') ? wp_roles()->get_names() : ['customer'=>'مشتری','subscriber'=>'مشترک'];
                                            foreach ($roles as $rk => $rl):
                                            ?>
                                            <option value="<?php echo esc_attr($rk); ?>" <?php selected($current_role,$rk); ?>><?php echo esc_html($rl); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
                                        <input type="checkbox" name="auto_login_after_create" value="1" <?php checked($s['auto_login_after_create'],1); ?>>
                                        <span>بعد از ساخت حساب، خودکار کاربر رو لاگین کن</span>
                                    </label>

                                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
                                        <input type="checkbox" name="send_account_email" value="1" <?php checked($s['send_account_email'],1); ?>>
                                        <span>ارسال ایمیل خوش‌آمد + رمز عبور به کاربر جدید (اگه ایمیل معتبر داده باشه)</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($tab === 'appearance'): ?>
                        <h2 style="margin-top:0;display:flex;align-items:center;gap:8px"><span class="dashicons dashicons-art"></span> ظاهر و استایل</h2>
                        <div style="display:block;margin-top:22px">
                            <div>
                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:16px;margin-bottom:16px">
                                    <h3 style="margin-top:0">تم آماده صفحه پرداخت</h3>
                                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px">
                                        <?php
                                        $current_checkout_theme = sanitize_key($s['checkout_theme'] ?? 'classic');
                                        $legacy_current_theme = ['glass'=>'neumorphic','minimal'=>'classic','dark'=>'dark_classic','colorful'=>'skeuomorphic'];
                                        if (isset($legacy_current_theme[$current_checkout_theme])) $current_checkout_theme = $legacy_current_theme[$current_checkout_theme];
                                        if (!array_key_exists($current_checkout_theme, self::get_checkout_themes())) $current_checkout_theme = 'classic';
                                        foreach (self::get_checkout_themes() as $theme_key => $theme_label): ?>
                                        <label class="fcui-theme-option" style="border:2px solid <?php echo $current_checkout_theme===$theme_key?'#1e6bff':'#e2e8f0'; ?>;border-radius:14px;padding:12px;background:#fff;cursor:pointer;text-align:center;font-weight:900">
                                            <input type="radio" name="checkout_theme" value="<?php echo esc_attr($theme_key); ?>" <?php checked($current_checkout_theme, $theme_key); ?> style="display:none">
                                            <?php echo esc_html($theme_label); ?>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                                    <div><label style="font-weight:700;display:block;margin-bottom:6px">رنگ اصلی</label><input type="text" name="primary_color" value="<?php echo esc_attr($s['primary_color']); ?>" class="fcui-color fcui-live" data-preview="primary_color"></div>
                                    <div><label style="font-weight:700;display:block;margin-bottom:6px">رنگ ثانویه</label><input type="text" name="secondary_color" value="<?php echo esc_attr($s['secondary_color']); ?>" class="fcui-color fcui-live" data-preview="secondary_color"></div>
                                    <div><label style="font-weight:700;display:block;margin-bottom:6px">پس‌زمینه صفحه</label><input type="text" name="background_color" value="<?php echo esc_attr($s['background_color']); ?>" class="fcui-color fcui-live" data-preview="background_color"></div>
                                    <div><label style="font-weight:700;display:block;margin-bottom:6px">رنگ کارت/فرم</label><input type="text" name="surface_color" value="<?php echo esc_attr($s['surface_color'] ?? '#ffffff'); ?>" class="fcui-color fcui-live" data-preview="surface_color"></div>
                                    <div><label style="font-weight:700;display:block;margin-bottom:6px">پس‌زمینه فیلدها</label><input type="text" name="input_background" value="<?php echo esc_attr($s['input_background'] ?? '#f8fafc'); ?>" class="fcui-color fcui-live" data-preview="input_background"></div>
                                    <div><label style="font-weight:700;display:block;margin-bottom:6px">رنگ متن</label><input type="text" name="text_color" value="<?php echo esc_attr($s['text_color'] ?? '#0f172a'); ?>" class="fcui-color fcui-live" data-preview="text_color"></div>
                                    <div><label style="font-weight:700;display:block;margin-bottom:6px">رنگ خط دور</label><input type="text" name="border_color" value="<?php echo esc_attr($s['border_color'] ?? '#e2e8f0'); ?>" class="fcui-color fcui-live" data-preview="border_color"></div>
                                    <div>
                                        <label style="font-weight:700;display:block;margin-bottom:6px">فونت داخلی</label>
                                        <select name="font_family" class="fcui-live" data-preview="font_family" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px">
                                            <?php foreach (self::get_font_options() as $font_key => $font_label): ?>
                                            <option value="<?php echo esc_attr($font_key); ?>" <?php selected(($s['font_family'] ?? ''), $font_key); ?>><?php echo esc_html($font_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div><label style="font-weight:700;display:block;margin-bottom:6px">حداکثر عرض صفحه (px)</label><input class="fcui-live" data-preview="checkout_max_width" type="number" name="checkout_max_width" value="<?php echo (int)($s['checkout_max_width'] ?? 980); ?>" min="360" max="1400" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px"></div>
                                    <div><label style="font-weight:700;display:block;margin-bottom:6px">گردی کارت (px)</label><input class="fcui-live" data-preview="card_radius" type="number" name="card_radius" value="<?php echo (int)$s['card_radius']; ?>" min="0" max="40" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px"></div>
                                    <div><label style="font-weight:700;display:block;margin-bottom:6px">گردی دکمه (px)</label><input class="fcui-live" data-preview="button_radius" type="number" name="button_radius" value="<?php echo (int)$s['button_radius']; ?>" min="0" max="40" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px"></div>
                                    <div><label style="font-weight:700;display:block;margin-bottom:6px">شدت سایه</label><input class="fcui-live" data-preview="shadow_strength" type="number" name="shadow_strength" value="<?php echo (int)($s['shadow_strength'] ?? 8); ?>" min="0" max="30" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px"></div>
                                </div>
                                <div style="margin-top:20px;display:grid;gap:12px">
                                    <input type="text" name="button_pay_text" value="<?php echo esc_attr($s['button_pay_text']); ?>" placeholder="متن دکمه پرداخت" style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font-weight:700">
                                    <input type="text" name="button_later_text" value="<?php echo esc_attr($s['button_later_text']); ?>" placeholder="متن دکمه بعدا" style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px">
                                    <input type="text" name="button_free_text" value="<?php echo esc_attr($s['button_free_text']); ?>" placeholder="متن ثبت نام رایگان" style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px">
                                    <input type="text" name="hint_text" value="<?php echo esc_attr($s['hint_text']); ?>" placeholder="متن راهنما" style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px">
                                    <textarea name="custom_css" rows="6" dir="ltr" placeholder="CSS اختصاصی برای صفحه پرداخت و کارت به کارت" style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font-family:monospace"><?php echo esc_textarea($s['custom_css'] ?? ''); ?></textarea>
                                </div>
                            </div>

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
                            <label style="display:flex;align-items:center;gap:10px;margin-bottom:12px"><input type="checkbox" name="c2c_enabled" value="1" <?php checked($s['c2c_enabled'],1); ?>> فعال باشد</label>
                            <label style="display:flex;align-items:center;gap:10px;margin-bottom:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px"><input type="checkbox" name="c2c_show_incomplete_payments" value="1" <?php checked(($s['c2c_show_incomplete_payments'] ?? 0),1); ?>> نمایش پرداخت‌های ناتمام در لیست کارت به کارت</label>
                            
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                                <div><label>شماره کارت</label><input type="text" name="c2c_card_number" value="<?php echo esc_attr($s['c2c_card_number']); ?>" dir="ltr" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;font-family:monospace"></div>
                                <div><label>نام صاحب کارت</label><input type="text" name="c2c_holder_name" value="<?php echo esc_attr($s['c2c_holder_name']); ?>" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px"></div>
                            </div>
                            
                            <div style="margin-top:16px">
                                <label style="font-weight:800;display:block;margin-bottom:10px">انتخاب تصویر کارت بانکی</label>
                                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px">
                                    <?php 
                                    $banks = self::get_bank_themes();
                                    foreach ($banks as $key => $b): ?>
                                    <label style="border:3px solid <?php echo $s['c2c_theme']==$key?'#1e6bff':'#e2e8f0'; ?>;border-radius:12px;padding:10px;text-align:center;cursor:pointer;background:<?php echo esc_attr($b['color']); ?>;color:#fff;position:relative">
                                        <input type="radio" name="c2c_theme" value="<?php echo esc_attr($key); ?>" <?php checked($s['c2c_theme'],$key); ?> style="position:absolute;opacity:0">
                                        <div style="font-weight:900;font-size:12px"><?php echo esc_html($b['label']); ?></div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div style="margin-top:22px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:16px">
                                <h3 style="margin-top:0">ابزار تنظیم موقعیت متن روی کارت</h3>
                                <p style="color:#64748b;margin-top:-6px">روی متن‌های روی کارت بکشید یا با اسلایدرها مختصات، رنگ، سایز، ضخامت و سایه را برای هر بانک تنظیم کنید. مختصات به صورت درصدی ذخیره می‌شود.</p>
                                <?php
                                $card_styles = self::sanitize_card_styles($s['c2c_card_styles'] ?? [], $s['c2c_card_styles'] ?? []);
                                foreach ($banks as $key => $b):
                                    $img = self::get_bank_card_image_url($key);
                                    $st = $card_styles[$key];
                                ?>
                                <div class="fcui-card-designer" data-bank="<?php echo esc_attr($key); ?>" style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px;margin-top:12px;<?php echo $s['c2c_theme']===$key?'':'display:none;'; ?>">
                                    <h4 style="margin:0 0 12px;font-weight:900"><?php echo esc_html($b['label']); ?></h4>
                                    <div style="display:grid;grid-template-columns:minmax(280px,520px) 1fr;gap:18px;margin-top:14px;align-items:start">
                                        <div class="fcui-card-preview" style="background-image:url('<?php echo esc_url($img); ?>')">
                                            <div class="fcui-card-preview__text" data-field="number" dir="ltr" data-copy-preview="<?php echo esc_attr(self::clean_card_number($s['c2c_card_number'])); ?>" style="left:<?php echo esc_attr($st['number']['x']); ?>%;top:<?php echo esc_attr($st['number']['y']); ?>%;color:<?php echo esc_attr($st['number']['color']); ?>;font-size:<?php echo (int)$st['number']['size']; ?>px;font-weight:<?php echo (int)$st['number']['weight']; ?>;text-shadow:<?php echo !empty($st['number']['shadow'])?'0 2px 6px rgba(0,0,0,.65)':'none'; ?>"><?php echo esc_html(self::format_card_number($s['c2c_card_number'])); ?></div>
                                            <div class="fcui-card-preview__text" data-field="holder" style="left:<?php echo esc_attr($st['holder']['x']); ?>%;top:<?php echo esc_attr($st['holder']['y']); ?>%;color:<?php echo esc_attr($st['holder']['color']); ?>;font-size:<?php echo (int)$st['holder']['size']; ?>px;font-weight:<?php echo (int)$st['holder']['weight']; ?>;text-shadow:<?php echo !empty($st['holder']['shadow'])?'0 2px 6px rgba(0,0,0,.65)':'none'; ?>"><?php echo esc_html($s['c2c_holder_name']); ?></div>
                                        </div>
                                        <div style="display:grid;gap:14px">
                                            <?php foreach (['number'=>'شماره کارت','holder'=>'نام صاحب کارت'] as $field_key => $field_label): $fs = $st[$field_key]; ?>
                                            <div style="border:1px solid #e2e8f0;border-radius:12px;padding:12px">
                                                <strong><?php echo esc_html($field_label); ?></strong>
                                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
                                                    <label>X %<input class="fcui-card-control" data-field="<?php echo esc_attr($field_key); ?>" data-prop="x" type="range" min="0" max="100" step="0.1" name="c2c_card_styles[<?php echo esc_attr($key); ?>][<?php echo esc_attr($field_key); ?>][x]" value="<?php echo esc_attr($fs['x']); ?>"></label>
                                                    <label>Y %<input class="fcui-card-control" data-field="<?php echo esc_attr($field_key); ?>" data-prop="y" type="range" min="0" max="100" step="0.1" name="c2c_card_styles[<?php echo esc_attr($key); ?>][<?php echo esc_attr($field_key); ?>][y]" value="<?php echo esc_attr($fs['y']); ?>"></label>
                                                    <label>رنگ<input class="fcui-card-control fcui-color" data-field="<?php echo esc_attr($field_key); ?>" data-prop="color" type="text" name="c2c_card_styles[<?php echo esc_attr($key); ?>][<?php echo esc_attr($field_key); ?>][color]" value="<?php echo esc_attr($fs['color']); ?>"></label>
                                                    <label>سایز<input class="fcui-card-control" data-field="<?php echo esc_attr($field_key); ?>" data-prop="size" type="number" min="8" max="48" name="c2c_card_styles[<?php echo esc_attr($key); ?>][<?php echo esc_attr($field_key); ?>][size]" value="<?php echo (int)$fs['size']; ?>"></label>
                                                    <label>وزن<input class="fcui-card-control" data-field="<?php echo esc_attr($field_key); ?>" data-prop="weight" type="number" min="100" max="1000" step="100" name="c2c_card_styles[<?php echo esc_attr($key); ?>][<?php echo esc_attr($field_key); ?>][weight]" value="<?php echo (int)$fs['weight']; ?>"></label>
                                                    <label style="display:flex;align-items:center;gap:8px;margin-top:18px"><input class="fcui-card-control" data-field="<?php echo esc_attr($field_key); ?>" data-prop="shadow" type="checkbox" name="c2c_card_styles[<?php echo esc_attr($key); ?>][<?php echo esc_attr($field_key); ?>][shadow]" value="1" <?php checked($fs['shadow'],1); ?>> سایه</label>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
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
            <script>jQuery(document).ready(function($){$('.fcui-color').wpColorPicker({change:function(e,ui){$(this).val(ui.color.toString()).trigger('input').trigger('change');},clear:function(){var t=$(this);setTimeout(function(){t.trigger('input').trigger('change');},20);}});});</script>
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

        $query_args = ['limit'=>50,'status'=>['on-hold'],'payment_method'=>'fcui_card2card','orderby'=>'date','order'=>'DESC'];
        if (empty($s['c2c_show_incomplete_payments'])) {
            $query_args['meta_key'] = '_fcui_c2c_receipt_1';
            $query_args['meta_compare'] = 'EXISTS';
        }
        $orders = wc_get_orders($query_args);
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

    private static function analytics_collect($days = 14, $start_date = '', $end_date = '') {
        $days = max(7, min(90, (int)$days));
        $today_key = wp_date('Y-m-d', current_time('timestamp'));
        $end_key = preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) ? $end_date : $today_key;
        $start_key = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ? $start_date : wp_date('Y-m-d', strtotime('-' . ($days - 1) . ' days', strtotime($end_key . ' 12:00:00')));
        if (strtotime($start_key) > strtotime($end_key)) { $tmp = $start_key; $start_key = $end_key; $end_key = $tmp; }
        $diff_days = min(90, max(1, (int)((strtotime($end_key) - strtotime($start_key)) / DAY_IN_SECONDS) + 1));
        $labels = $visits = $success = $revenue = [];
        for ($i=0; $i<$diff_days; $i++) {
            $ts = strtotime('+' . $i . ' days', strtotime($start_key . ' 12:00:00'));
            $key = wp_date('Y-m-d', $ts);
            $labels[$key] = self::jalali_datetime($ts, false);
            $visits[$key] = 0; $success[$key] = 0; $revenue[$key] = 0;
        }
        $orders_fast = wc_get_orders(['limit'=>-1,'meta_key'=>'_fcui_fast_checkout','date_created'=>'>=' . $start_key . ' 00:00:00','return'=>'objects']);
        foreach ($orders_fast as $order) {
            if (!$order) continue;
            $key = $order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d') : '';
            if (!isset($visits[$key])) continue;
            $visits[$key]++;
            if ($order->has_status(['processing','completed'])) {
                $success[$key]++;
                $revenue[$key] += (float)$order->get_total();
            }
        }
        return compact('labels','visits','success','revenue','orders_fast','start_key','end_key','diff_days');
    }

    public static function admin_analytics_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $days = isset($_GET['days']) ? absint($_GET['days']) : 30;
        $start = isset($_GET['start']) ? sanitize_text_field($_GET['start']) : '';
        $end = isset($_GET['end']) ? sanitize_text_field($_GET['end']) : '';
        $data = self::analytics_collect($days, $start, $end);
        $total_attempts = array_sum($data['visits']);
        $total_success = array_sum($data['success']);
        $total_revenue = array_sum($data['revenue']);
        $conversion = $total_attempts ? round(($total_success / $total_attempts) * 100, 1) : 0;
        $max_chart = max(1, max(array_merge(array_values($data['visits']), array_values($data['success']))));
        $avg_order = $total_success ? ($total_revenue / $total_success) : 0;
        ?>
        <style>
            .fcui-analytics{direction:rtl;max-width:1280px;color:#0f172a}.fcui-hero{background:linear-gradient(135deg,#0f172a,#1e3a8a 55%,#2563eb);border-radius:26px;padding:24px;color:#fff;box-shadow:0 24px 70px rgba(30,58,138,.23);margin:18px 0 18px}.fcui-hero__in{display:flex;align-items:center;justify-content:space-between;gap:18px}.fcui-hero h1{margin:0;color:#fff;font-size:28px;font-weight:950}.fcui-hero p{margin:8px 0 0;color:rgba(255,255,255,.78)}.fcui-range{display:flex;gap:8px;flex-wrap:wrap}.fcui-range a{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);color:#fff;text-decoration:none;border-radius:999px;padding:9px 14px;font-weight:900;transition:.2s}.fcui-range a.is-active,.fcui-range a:hover{background:#fff;color:#1e3a8a;transform:translateY(-1px)}
            .fcui-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}.fcui-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:18px;box-shadow:0 14px 40px rgba(15,23,42,.06);transition:.25s}.fcui-kpi:hover{transform:translateY(-3px)}.fcui-kpi__label{color:#64748b;font-weight:900}.fcui-kpi__val{font-size:30px;font-weight:950;margin-top:8px}.fcui-kpi__sub{font-size:12px;color:#94a3b8;margin-top:4px}
            .fcui-grid{display:grid;grid-template-columns:minmax(0,1fr) 310px;gap:16px;align-items:start}.fcui-panel{background:#fff;border:1px solid #e2e8f0;border-radius:26px;padding:22px;box-shadow:0 14px 45px rgba(15,23,42,.06);min-width:0}.fcui-panel__head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.fcui-panel h2{margin:0;font-size:20px;font-weight:950}.fcui-legend{display:flex;gap:12px;color:#64748b;font-weight:800;font-size:12px}.fcui-dot{width:10px;height:10px;display:inline-block;border-radius:4px;margin-left:6px}
            .fcui-chart-wrap{position:relative;overflow:hidden;border-radius:22px;background:linear-gradient(180deg,#f8fafc,#fff);padding:12px}.fcui-chart{height:324px;display:flex;align-items:end;gap:10px;padding:12px 4px 8px;overflow-x:auto;scroll-behavior:smooth}.fcui-chart-group{min-width:58px;height:282px;display:flex;flex-direction:column;align-items:center;justify-content:end;gap:7px;position:relative;transition:.25s}.fcui-bars{height:220px;display:flex;align-items:end;gap:5px}.fcui-bar{width:18px;border-radius:10px 10px 4px 4px;transform-origin:bottom;animation:fcuiGrow .75s cubic-bezier(.2,.8,.2,1) both;transition:.2s}.fcui-bar--visits{background:linear-gradient(180deg,#93c5fd,#2563eb);box-shadow:0 10px 20px rgba(37,99,235,.18)}.fcui-bar--success{background:linear-gradient(180deg,#86efac,#16a34a);box-shadow:0 10px 20px rgba(22,163,74,.18)}.fcui-chart-label{font-size:11px;color:#64748b;white-space:nowrap}.fcui-chart-group:hover{transform:translateY(-4px)}.fcui-chart-group.is-selected .fcui-chart-label{color:#1e6bff;font-weight:950}.fcui-chart-tip{position:fixed;background:#0f172a;color:#fff;border-radius:16px;padding:12px;min-width:210px;opacity:0;pointer-events:none;transition:opacity .16s,transform .16s;box-shadow:0 18px 50px rgba(15,23,42,.24);z-index:999999;text-align:right;transform:translateY(8px)}.fcui-chart-tip.is-visible{opacity:1;transform:translateY(0)}.fcui-chart-tip strong{display:block;margin-bottom:8px}.fcui-chart-tip div{display:flex;justify-content:space-between;gap:14px;font-size:12px;margin-top:4px;color:#dbeafe}@keyframes fcuiGrow{from{transform:scaleY(.08);opacity:.45}to{transform:scaleY(1);opacity:1}}
            .fcui-calendar-box{position:sticky;top:40px}.fcui-mini-cal{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}.fcui-mini-day{border:1px solid #e2e8f0;background:#fff;border-radius:12px;height:42px;cursor:pointer;transition:.2s;font-weight:900;color:#334155;position:relative;font-size:12px;padding:0}.fcui-mini-day:hover{border-color:#93c5fd;transform:translateY(-2px)}.fcui-mini-day.is-start,.fcui-mini-day.is-end{background:#2563eb;color:#fff;border-color:#2563eb}.fcui-mini-day.is-in-range{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}.fcui-mini-day small{display:block;font-size:9px;color:inherit;opacity:.75;font-weight:800}.fcui-cal-actions{display:flex;gap:8px;margin-top:12px}.fcui-cal-actions .button{flex:1;text-align:center}.fcui-cal-help{font-size:12px;color:#64748b;line-height:1.8;margin:8px 0 12px}.fcui-cal-picked{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:10px;color:#475569;font-weight:800;margin-top:12px}.fcui-week{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:6px;color:#94a3b8;font-size:11px;text-align:center;font-weight:900}
            @media(max-width:1100px){.fcui-grid{grid-template-columns:1fr}.fcui-calendar-box{position:static}.fcui-kpis{grid-template-columns:repeat(2,1fr)}.fcui-hero__in{display:block}.fcui-range{margin-top:14px}}
        </style>
        <div class="wrap fcui-admin fcui-analytics">
            <section class="fcui-hero"><div class="fcui-hero__in"><div><h1>گزارش و تحلیل پرداخت سریع</h1><p>زمان ایران: <?php echo esc_html(self::jalali_datetime(current_time('timestamp'), true)); ?> — بازه فعلی: <?php echo esc_html(self::jalali_datetime(strtotime($data['start_key']), false)); ?> تا <?php echo esc_html(self::jalali_datetime(strtotime($data['end_key']), false)); ?></p></div><div class="fcui-range"><a class="<?php echo $days===7 && !$start?'is-active':''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=fcui-analytics&days=7')); ?>">۷ روز</a><a class="<?php echo $days===14 && !$start?'is-active':''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=fcui-analytics&days=14')); ?>">۱۴ روز</a><a class="<?php echo $days===30 && !$start?'is-active':''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=fcui-analytics&days=30')); ?>">۳۰ روز</a></div></div></section>
            <section class="fcui-kpis"><?php $cards = [['کل تلاش‌ها', self::fa_digits($total_attempts), 'پرداخت‌های شروع‌شده'],['پرداخت موفق', self::fa_digits($total_success), 'آنلاین موفق + کارت تأییدشده'],['نرخ تبدیل', self::fa_digits($conversion).'٪', 'موفق نسبت به تلاش‌ها'],['میانگین سفارش', self::price_html($avg_order), 'پرداخت‌های موفق']]; foreach ($cards as $c): ?><div class="fcui-kpi"><div class="fcui-kpi__label"><?php echo esc_html($c[0]); ?></div><div class="fcui-kpi__val"><?php echo wp_kses_post($c[1]); ?></div><div class="fcui-kpi__sub"><?php echo esc_html($c[2]); ?></div></div><?php endforeach; ?></section>
            <section class="fcui-grid"><div class="fcui-panel"><div class="fcui-panel__head"><h2>نمودار عملکرد</h2><div class="fcui-legend"><span><i class="fcui-dot" style="background:#2563eb"></i>تلاش</span><span><i class="fcui-dot" style="background:#16a34a"></i>موفق</span></div></div><div class="fcui-chart-wrap"><div class="fcui-chart" id="fcuiAnalyticsChart"><?php foreach ($data['labels'] as $key => $label): $h1=max(5,round(($data['visits'][$key]/$max_chart)*220)); $h2=max(5,round(($data['success'][$key]/$max_chart)*220)); $rate=$data['visits'][$key]?round(($data['success'][$key]/$data['visits'][$key])*100,1):0; ?><div class="fcui-chart-group" data-day="<?php echo esc_attr($key); ?>" data-tip="<?php echo esc_attr(wp_json_encode(['date'=>$label,'visits'=>self::fa_digits($data['visits'][$key]),'success'=>self::fa_digits($data['success'][$key]),'rate'=>self::fa_digits($rate).'٪','revenue'=>wp_strip_all_tags(self::price_html($data['revenue'][$key]))], JSON_UNESCAPED_UNICODE)); ?>"><div class="fcui-bars"><span class="fcui-bar fcui-bar--visits" style="height:<?php echo (int)$h1; ?>px"></span><span class="fcui-bar fcui-bar--success" style="height:<?php echo (int)$h2; ?>px"></span></div><div class="fcui-chart-label"><?php echo esc_html(preg_replace('/^\d{4}\//','',$label)); ?></div></div><?php endforeach; ?></div></div></div>
                <aside class="fcui-panel fcui-calendar-box"><h2>تقویم بازه</h2><p class="fcui-cal-help">روز شروع و پایان را انتخاب کنید.</p><div class="fcui-week"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div class="fcui-mini-cal" id="fcuiMiniCal"><?php foreach ($data['labels'] as $key=>$label): ?><button type="button" class="fcui-mini-day <?php echo ($key===$data['start_key']?'is-start ':'') . ($key===$data['end_key']?'is-end ':''); ?>" data-day="<?php echo esc_attr($key); ?>"><span><?php echo esc_html(substr($label,-2)); ?></span><small><?php echo esc_html(self::fa_digits($data['visits'][$key]).'/'.self::fa_digits($data['success'][$key])); ?></small></button><?php endforeach; ?></div><div class="fcui-cal-picked" id="fcuiCalPicked">شروع: <?php echo esc_html(self::jalali_datetime(strtotime($data['start_key']), false)); ?><br>پایان: <?php echo esc_html(self::jalali_datetime(strtotime($data['end_key']), false)); ?></div><div class="fcui-cal-actions"><a class="button button-primary" id="fcuiApplyRange" href="#">نمایش بازه</a><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=fcui-analytics&days=30')); ?>">ریست</a></div></aside></section>
        </div><div class="fcui-chart-tip" id="fcuiChartTip"></div><script>(function(){var start='<?php echo esc_js($data['start_key']); ?>',end='<?php echo esc_js($data['end_key']); ?>',base='<?php echo esc_js(admin_url('admin.php?page=fcui-analytics')); ?>';function paint(){document.querySelectorAll('.fcui-mini-day').forEach(function(d){var x=d.dataset.day;d.classList.toggle('is-start',x===start);d.classList.toggle('is-end',x===end);d.classList.toggle('is-in-range',x>start&&x<end)});var picked=document.getElementById('fcuiCalPicked');if(picked)picked.innerHTML='شروع: '+start+'<br>پایان: '+(end||start);}document.querySelectorAll('.fcui-mini-day').forEach(function(btn){btn.addEventListener('click',function(){var d=this.dataset.day;if(!start||start&&end){start=d;end='';}else{end=d;if(end<start){var t=start;start=end;end=t;}}paint();var item=document.querySelector('.fcui-chart-group[data-day="'+d+'"]');if(item)item.scrollIntoView({behavior:'smooth',inline:'center',block:'nearest'});});});var apply=document.getElementById('fcuiApplyRange');if(apply)apply.addEventListener('click',function(e){e.preventDefault();if(!end)end=start;window.location=base+'&start='+encodeURIComponent(start)+'&end='+encodeURIComponent(end);});var tip=document.getElementById('fcuiChartTip');document.querySelectorAll('.fcui-chart-group').forEach(function(el){el.addEventListener('mousemove',function(e){var d=JSON.parse(this.dataset.tip);tip.innerHTML='<strong>'+d.date+'</strong><div><span>تلاش</span><b>'+d.visits+'</b></div><div><span>موفق</span><b>'+d.success+'</b></div><div><span>نرخ تبدیل</span><b>'+d.rate+'</b></div><div><span>درآمد</span><b>'+d.revenue+'</b></div>';var left=e.clientX+16,top=e.clientY+12;if(left>window.innerWidth-240)left=e.clientX-230;if(top>window.innerHeight-170)top=e.clientY-165;if(top<50)top=50;tip.style.left=left+'px';tip.style.top=top+'px';tip.classList.add('is-visible');});el.addEventListener('mouseleave',function(){tip.classList.remove('is-visible')});});paint();})();</script><?php
    }


    public static function admin_tutorial_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $fast_page = get_permalink((int)get_option(self::OPT_FAST_PAGE_ID));
        $c2c_page  = get_permalink((int)get_option(self::OPT_C2C_PAGE_ID));
        ?>
        <style>
            .fcui-help{direction:rtl;max-width:1220px;color:#0f172a}.fcui-help *{box-sizing:border-box}.fcui-help-hero{background:linear-gradient(135deg,#0f172a,#1e3a8a 58%,#2563eb);border-radius:30px;padding:32px;color:#fff;box-shadow:0 24px 70px rgba(30,58,138,.25);margin:18px 0 22px;position:relative;overflow:hidden}.fcui-help-hero:before{content:"";position:absolute;left:-80px;bottom:-90px;width:280px;height:280px;border-radius:50%;background:rgba(255,255,255,.12)}.fcui-help-hero__in{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:20px}.fcui-help h1{margin:0;color:#fff;font-size:31px;font-weight:950}.fcui-help-hero p{margin:10px 0 0;color:rgba(255,255,255,.78);font-size:14px;line-height:2}.fcui-help-actions{display:flex;gap:10px;flex-wrap:wrap}.fcui-help-actions a{display:inline-flex;align-items:center;gap:8px;background:#fff;color:#1e3a8a;text-decoration:none;border-radius:999px;padding:10px 16px;font-weight:900}.fcui-help-actions a.secondary{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);color:#fff}.fcui-help-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;margin-bottom:18px}.fcui-help-card{background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:22px;box-shadow:0 14px 40px rgba(15,23,42,.06);transition:.22s}.fcui-help-card:hover{transform:translateY(-3px);box-shadow:0 20px 54px rgba(15,23,42,.10)}.fcui-help-icon{width:46px;height:46px;border-radius:16px;background:#eff6ff;color:#1e6bff;display:flex;align-items:center;justify-content:center;margin-bottom:14px}.fcui-help-card h3{margin:0 0 10px;font-size:17px;font-weight:950}.fcui-help-card p{margin:0;color:#64748b;line-height:2}.fcui-help-layout{display:grid;grid-template-columns:280px minmax(0,1fr);gap:18px;align-items:start}.fcui-help-nav{position:sticky;top:42px;background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:14px;box-shadow:0 14px 40px rgba(15,23,42,.06)}.fcui-help-nav a{display:flex;align-items:center;gap:9px;text-decoration:none;color:#334155;padding:11px 12px;border-radius:14px;font-weight:900}.fcui-help-nav a:hover{background:#eff6ff;color:#1e6bff}.fcui-help-section{background:#fff;border:1px solid #e2e8f0;border-radius:26px;padding:26px;box-shadow:0 14px 40px rgba(15,23,42,.06);margin-bottom:18px}.fcui-help-section h2{margin:0 0 14px;font-size:22px;font-weight:950;display:flex;align-items:center;gap:10px}.fcui-help-section h2 .dashicons{color:#1e6bff}.fcui-help-section p,.fcui-help-section li{line-height:2;color:#475569;font-size:14px}.fcui-steps{counter-reset:step;display:grid;gap:12px}.fcui-step{counter-increment:step;background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px;padding:16px 18px;position:relative;padding-right:58px}.fcui-step:before{content:counter(step);position:absolute;right:16px;top:16px;width:30px;height:30px;border-radius:50%;background:#1e6bff;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:950}.fcui-step strong{display:block;color:#0f172a;margin-bottom:4px}.fcui-table{width:100%;border-collapse:separate;border-spacing:0 8px}.fcui-table th{text-align:right;color:#64748b;font-size:12px;padding:0 12px}.fcui-table td{background:#f8fafc;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;padding:13px 12px;color:#475569}.fcui-table td:first-child{border-right:1px solid #e2e8f0;border-radius:0 14px 14px 0;font-weight:900;color:#0f172a}.fcui-table td:last-child{border-left:1px solid #e2e8f0;border-radius:14px 0 0 14px}.fcui-note{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:18px;padding:16px;line-height:2}.fcui-warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:18px;padding:16px;line-height:2}.fcui-code{direction:ltr;text-align:left;background:#0f172a;color:#e5e7eb;border-radius:14px;padding:12px 14px;font-family:monospace;overflow:auto}.fcui-badges{display:flex;gap:8px;flex-wrap:wrap}.fcui-badge{background:#eef2ff;color:#3730a3;border-radius:999px;padding:6px 10px;font-weight:900;font-size:12px}.fcui-faq details{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:14px 16px;margin-bottom:10px}.fcui-faq summary{cursor:pointer;font-weight:950;color:#0f172a}.fcui-faq p{margin:10px 0 0}@media(max-width:960px){.fcui-help-hero__in{display:block}.fcui-help-actions{margin-top:18px}.fcui-help-layout{grid-template-columns:1fr}.fcui-help-nav{position:static}.fcui-help-nav{display:grid;grid-template-columns:repeat(2,1fr)}}
        </style>
        <div class="wrap fcui-admin fcui-help">
            <section class="fcui-help-hero">
                <div class="fcui-help-hero__in">
                    <div>
                        <h1>آموزش و راهنمای کامل پرداخت سریع</h1>
                        <p>راهنمای نسخه <?php echo esc_html(self::VERSION); ?> — از راه‌اندازی اولیه تا کارت به کارت تصویری، پاپ‌آپ، تقویم شمسی، گزارش‌گیری و رفع خطاهای رایج.</p>
                    </div>
                    <div class="fcui-help-actions">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=fcui-settings')); ?>"><span class="dashicons dashicons-admin-generic"></span> تنظیمات افزونه</a>
                        <a class="secondary" href="<?php echo esc_url(admin_url('admin.php?page=fcui-analytics')); ?>"><span class="dashicons dashicons-chart-line"></span> گزارش و تحلیل</a>
                    </div>
                </div>
            </section>

            <section class="fcui-help-grid">
                <div class="fcui-help-card"><div class="fcui-help-icon"><span class="dashicons dashicons-cart"></span></div><h3>پرداخت سریع محصول</h3><p>دکمه‌های خرید محصول، آرشیو و حتی سبد خرید تک‌محصولی به صفحه پرداخت سریع هدایت می‌شوند.</p></div>
                <div class="fcui-help-card"><div class="fcui-help-icon"><span class="dashicons dashicons-bank"></span></div><h3>کارت به کارت تصویری</h3><p>۸ تصویر کارت بانکی واقعی، ابزار تنظیم جایگاه متن، آپلود رسید و تأیید دستی مدیر.</p></div>
                <div class="fcui-help-card"><div class="fcui-help-icon"><span class="dashicons dashicons-art"></span></div><h3>تم و شخصی‌سازی</h3><p>تم کلاسیک، نئومورفیک، اسکئومورفیک و تاریک با رنگ، فونت، سایه، گردی و CSS اختصاصی.</p></div>
                <div class="fcui-help-card"><div class="fcui-help-icon"><span class="dashicons dashicons-chart-area"></span></div><h3>گزارش و تحلیل</h3><p>داشبورد تحلیلی با نمودار، نرخ تبدیل واقعی، درآمد موفق و تقویم شمسی بازه گزارش.</p></div>
            </section>

            <div class="fcui-help-layout">
                <nav class="fcui-help-nav">
                    <a href="#start"><span class="dashicons dashicons-yes-alt"></span> شروع سریع</a>
                    <a href="#general"><span class="dashicons dashicons-admin-generic"></span> تنظیمات عمومی</a>
                    <a href="#appearance"><span class="dashicons dashicons-art"></span> ظاهر و تم‌ها</a>
                    <a href="#card"><span class="dashicons dashicons-bank"></span> کارت بانکی</a>
                    <a href="#physical"><span class="dashicons dashicons-products"></span> محصولات فیزیکی</a>
                    <a href="#campaign"><span class="dashicons dashicons-calendar-alt"></span> کمپین شمسی</a>
                    <a href="#analytics"><span class="dashicons dashicons-chart-line"></span> گزارش‌ها</a>
                    <a href="#faq"><span class="dashicons dashicons-editor-help"></span> رفع خطا</a>
                </nav>

                <main>
                    <section id="start" class="fcui-help-section">
                        <h2><span class="dashicons dashicons-yes-alt"></span> شروع سریع در ۶ مرحله</h2>
                        <div class="fcui-steps">
                            <div class="fcui-step"><strong>فعال‌سازی ووکامرس و برگه‌های افزونه</strong> بعد از فعال‌سازی افزونه، برگه‌های «پرداخت سریع» و «کارت به کارت» ساخته می‌شوند. اگر ساخته نشدند افزونه را یک‌بار غیرفعال/فعال کنید.</div>
                            <div class="fcui-step"><strong>تنظیمات عمومی</strong> مشخص کنید پرداخت سریع برای همه محصولات فعال باشد یا فقط محصولات مجازی/دانلودی. حالت پاپ‌آپ سریع هم در همین بخش است.</div>
                            <div class="fcui-step"><strong>ظاهر و تم</strong> یکی از تم‌ها را انتخاب کنید. با انتخاب تم، رنگ‌های پیشنهادی همان تم اعمال می‌شود و بعد می‌توانید رنگ‌ها را دستی تغییر دهید.</div>
                            <div class="fcui-step"><strong>کارت بانکی</strong> شماره کارت، نام دارنده و تصویر بانک را انتخاب کنید. با ابزار Drag، جایگاه شماره و نام را دقیق روی کارت تنظیم کنید.</div>
                            <div class="fcui-step"><strong>محصولات فیزیکی</strong> اگر کالای فیزیکی دارید، فیلدهای استان، شهر، آدرس، کدپستی و کد ملی را فعال کنید.</div>
                            <div class="fcui-step"><strong>تست نهایی</strong> یک محصول تست بسازید و هر دو مسیر «پرداخت آنلاین» و «کارت به کارت + آپلود رسید» را بررسی کنید.</div>
                        </div>
                    </section>

                    <section id="general" class="fcui-help-section">
                        <h2><span class="dashicons dashicons-admin-generic"></span> تنظیمات عمومی</h2>
                        <table class="fcui-table"><tr><th>گزینه</th><th>کاربرد</th></tr>
                            <tr><td>نیاز به ورود کاربر</td><td>اگر فعال باشد کاربر قبل از پرداخت به صفحه ورود هدایت می‌شود. برای فروش عمومی بهتر است غیرفعال بماند.</td></tr>
                            <tr><td>حالت اعمال افزونه</td><td>می‌توانید افزونه را برای همه محصولات یا فقط محصولات مجازی/دانلودی فعال کنید.</td></tr>
                            <tr><td>کد تخفیف</td><td>فیلد کد تخفیف در فرم نمایش داده می‌شود. دکمه اعمال، کد را همان‌جا با AJAX بررسی می‌کند و خطای دقیق نشان می‌دهد.</td></tr>
                            <tr><td>پاپ‌آپ سریع</td><td>اگر فعال باشد فرم پرداخت بدون ترک صفحه محصول و بدون iframe نمایش داده می‌شود.</td></tr>
                            <tr><td>ساخت خودکار حساب</td><td>در صورت نیاز، برای کاربر جدید حساب ساخته و اطلاعات صورتحساب ذخیره می‌شود.</td></tr>
                        </table>
                    </section>

                    <section id="appearance" class="fcui-help-section">
                        <h2><span class="dashicons dashicons-art"></span> ظاهر، تم‌ها و فونت‌ها</h2>
                        <p>در تب ظاهر می‌توانید تم کلی صفحه، رنگ‌ها، فونت داخلی، گردی کارت‌ها و دکمه‌ها، شدت سایه و CSS اختصاصی را تنظیم کنید.</p>
                        <div class="fcui-badges"><span class="fcui-badge">کلاسیک پیش‌فرض</span><span class="fcui-badge">نئومورفیک</span><span class="fcui-badge">اسکئومورفیک</span><span class="fcui-badge">کلاسیک تاریک</span></div>
                        <div class="fcui-note" style="margin-top:16px">نکته: وقتی تم را تغییر می‌دهید، رنگ‌های پیشنهادی همان تم روی فیلدهای رنگی تنظیم می‌شود. بعد از آن می‌توانید هر رنگ را دستی تغییر دهید.</div>
                        <h3>فونت‌های داخلی</h3>
                        <p>فونت‌های ایران‌سنس، ایران‌یکان، دانا، کلمه و پیدا داخل افزونه قرار گرفته‌اند و بدون نیاز به CDN یا فایل خارجی قابل استفاده‌اند.</p>
                    </section>

                    <section id="card" class="fcui-help-section">
                        <h2><span class="dashicons dashicons-bank"></span> کارت به کارت و ابزار تنظیم کارت</h2>
                        <p>در تب کارت بانکی، تصویر کارت واقعی بانک را انتخاب کنید و شماره کارت و نام صاحب کارت را وارد کنید.</p>
                        <div class="fcui-steps">
                            <div class="fcui-step"><strong>انتخاب بانک</strong> از بین بانک‌های ملی، ملت، صادرات، کشاورزی، تجارت، پاسارگاد، سامان و سپه انتخاب کنید.</div>
                            <div class="fcui-step"><strong>تنظیم موقعیت</strong> متن شماره کارت و نام دارنده را روی تصویر بکشید یا با X/Y درصدی تنظیم کنید.</div>
                            <div class="fcui-step"><strong>تنظیم تایپوگرافی</strong> رنگ، سایز، وزن فونت و سایه برای هر متن قابل تنظیم است.</div>
                            <div class="fcui-step"><strong>لیست سفارش‌ها</strong> سفارش‌های بدون رسید، به صورت پیش‌فرض در لیست کارت به کارت نمایش داده نمی‌شوند؛ مگر گزینه نمایش پرداخت‌های ناتمام را فعال کنید.</div>
                        </div>
                        <div class="fcui-warn" style="margin-top:16px">برای کپی شماره کارت، شماره روی کارت ۴ رقم ۴ رقم نمایش داده می‌شود اما هنگام کپی، بدون فاصله کپی می‌شود.</div>
                    </section>

                    <section id="physical" class="fcui-help-section">
                        <h2><span class="dashicons dashicons-products"></span> محصولات فیزیکی</h2>
                        <p>برای محصولات فیزیکی می‌توانید فیلدهای آدرس را فعال کنید. افزونه به صورت خودکار محصول فیزیکی را تشخیص می‌دهد و فرم مناسب نمایش می‌دهد.</p>
                        <ul>
                            <li>استان، شهر، آدرس کامل و کد پستی قابل فعال/غیرفعال کردن هستند.</li>
                            <li>کد ملی می‌تواند اختیاری یا اجباری باشد.</li>
                            <li>فیلدهای سفارشی نیز قابل اضافه شدن هستند.</li>
                        </ul>
                    </section>

                    <section id="campaign" class="fcui-help-section">
                        <h2><span class="dashicons dashicons-calendar-alt"></span> کمپین زمان‌دار با تقویم شمسی</h2>
                        <p>در صفحه ویرایش محصول، بخش کمپین زمان‌دار وجود دارد. تاریخ شروع و پایان با تقویم شمسی و ساعت ایران انتخاب می‌شود.</p>
                        <div class="fcui-note">اگر زمان فعلی بین شروع و پایان کمپین باشد، قیمت کمپین روی محصول اعمال می‌شود و تایمر شمارش معکوس نمایش داده می‌شود.</div>
                    </section>

                    <section id="analytics" class="fcui-help-section">
                        <h2><span class="dashicons dashicons-chart-line"></span> گزارش و تحلیل</h2>
                        <p>تب گزارش و تحلیل، آمار تلاش پرداخت، پرداخت موفق، درآمد موفق و نرخ تبدیل واقعی را نمایش می‌دهد.</p>
                        <ul>
                            <li>پرداخت موفق فقط شامل سفارش آنلاین موفق و کارت به کارت تأییدشده است.</li>
                            <li>سفارش کارت به کارت بدون رسید یا تأیید نشده، جزو تبدیل موفق حساب نمی‌شود.</li>
                            <li>با تقویم کوچک کنار نمودار می‌توانید بازه شروع و پایان را انتخاب کنید.</li>
                            <li>با هاور روی نمودار، جزئیات روز شامل تلاش، موفق، نرخ تبدیل و درآمد نمایش داده می‌شود.</li>
                        </ul>
                    </section>

                    <section id="faq" class="fcui-help-section fcui-faq">
                        <h2><span class="dashicons dashicons-editor-help"></span> رفع خطاهای رایج</h2>
                        <details open><summary>با کلیک روی خرید، محصول مشخص نیست نمایش داده می‌شود</summary><p>معمولاً قالب یا افزونه جانبی شناسه محصول را در دکمه خرید قرار نمی‌دهد. افزونه تلاش می‌کند از data-product_id، فرم محصول، لینک add-to-cart و محصول فعلی شناسه را تشخیص دهد. اگر باز هم مشکل بود، دکمه خرید قالب را بررسی کنید که product_id معتبر داشته باشد.</p></details>
                        <details><summary>کارت به کارت در لیست سفارش‌ها دیده نمی‌شود</summary><p>به صورت پیش‌فرض فقط سفارش‌هایی که رسید آپلود کرده‌اند نمایش داده می‌شوند. اگر می‌خواهید سفارش‌های ناتمام هم دیده شوند، در تب کارت بانکی گزینه «نمایش پرداخت‌های ناتمام» را فعال کنید.</p></details>
                        <details><summary>کد تخفیف خطا می‌دهد</summary><p>افزونه همان خطای دقیق ووکامرس را بررسی می‌کند: انقضا، محدودیت محصول، دسته‌بندی، حداقل/حداکثر مبلغ، سقف استفاده و محدودیت هر کاربر.</p></details>
                        <details><summary>صفحه با قالب تداخل دارد</summary><p>صفحات پرداخت سریع و کارت به کارت مستقل رندر می‌شوند. برای تغییرات خاص می‌توانید از CSS اختصاصی در تب ظاهر استفاده کنید.</p></details>
                    </section>

                    <section class="fcui-help-section">
                        <h2><span class="dashicons dashicons-admin-links"></span> لینک‌ها و شورت‌کدها</h2>
                        <p>برگه‌های افزونه معمولاً خودکار ساخته می‌شوند:</p>
                        <div class="fcui-code">[fcui_fast_checkout]<br>[fcui_card2card]</div>
                        <div class="fcui-help-actions" style="margin-top:16px"><a href="<?php echo esc_url($fast_page ?: '#'); ?>" target="_blank">مشاهده صفحه پرداخت سریع</a><a href="<?php echo esc_url($c2c_page ?: '#'); ?>" target="_blank" class="secondary" style="background:#1e6bff;color:#fff">مشاهده صفحه کارت به کارت</a></div>
                    </section>
                </main>
            </div>
        </div>
        <?php
    }
}

FCUI_Fast_Checkout::init();

add_shortcode('fcui_fast_checkout', function(){ return '<div class="fcui-shortcode-note">در حال آماده‌سازی صفحه پرداخت سریع...</div>'; });
add_shortcode('fcui_card2card', function(){ return '<div class="fcui-shortcode-note">در حال آماده‌سازی صفحه کارت به کارت...</div>'; });

add_filter('woocommerce_get_return_url', function($url, $order){
    if (!$order) return $url;
    return home_url('/order-success/?order_id=' . $order->get_id() . '&key=' . $order->get_order_key() . '&type=online');
}, 10, 2);