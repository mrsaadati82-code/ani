<?php
if (!defined('ABSPATH')) exit;

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$key      = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
$type     = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'online';

$order = wc_get_order($order_id);

if (!$order || $order->get_order_key() !== $key) {
    wp_die('سفارش معتبر نیست');
}

/*
|--------------------------------------------------------------------------
| اطلاعات سفارش
|--------------------------------------------------------------------------
*/

$product_name = '';

foreach ($order->get_items() as $item) {
    $product_name = $item->get_name();
    break;
}

$total = $order->get_formatted_order_total();

/*
|--------------------------------------------------------------------------
| تنظیمات افزونه
|--------------------------------------------------------------------------
*/

$settings = get_option('fcui_fast_checkout_settings', []);

$title = $settings['success_page_title'] ?? 'پرداخت با موفقیت انجام شد';

$online_msg = $settings['success_page_online_message']
?? 'پرداخت شما با موفقیت انجام شد و دسترسی به دوره برای شما فعال شده است.';

$c2c_msg = $settings['success_page_c2c_message']
?? 'رسید شما ثبت شد. پس از بررسی توسط پشتیبانی، دسترسی به دوره فعال خواهد شد.';

/*
|--------------------------------------------------------------------------
| لینک دوره
|--------------------------------------------------------------------------
| اگر در تنظیمات وارد شده باشد همان استفاده می‌شود
| در غیر این صورت -> صفحه سفارش‌های حساب کاربری
|--------------------------------------------------------------------------
*/

$course_link = !empty($settings['course_access_link'])
    ? esc_url($settings['course_access_link'])
    : wc_get_account_endpoint_url('orders');

/*
|--------------------------------------------------------------------------
| متن دکمه
|--------------------------------------------------------------------------
*/

$button_text = ($type === 'online')
    ? 'رفتن به دوره'
    : 'مشاهده وضعیت سفارش';

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>

<meta charset="<?php bloginfo('charset'); ?>">

<meta name="viewport" content="width=device-width, initial-scale=1">

<?php wp_head(); ?>

<link rel="stylesheet"
href="<?php echo esc_url(plugins_url('../assets/fast-checkout.css', __FILE__)); ?>">

<style>

.fcui-success-box{
    text-align:center;
}

.fcui-order-meta{
    margin-top:10px;
    font-size:15px;
    line-height:1.9;
}

.fcui-success-btn{
    display:block;
    width:100%;
    text-align:center;
    text-decoration:none;
    margin-top:22px;
}

</style>

</head>

<body class="fcui-fast-checkout">

<div class="fcui fcui__app">

<div class="fcui__main">

<div class="fcui__card fcui-success-box">

    <!-- آیکن موفقیت -->

    <div class="fcui__cardHead">

        <span class="fcui__step">✓</span>

        <span class="fcui__cardTitle">
            <?php echo esc_html($title); ?>
        </span>

    </div>

    <!-- پیام موفقیت -->

    <div class="fcui__alert">

        <?php if ($type === 'online') : ?>

            <?php echo esc_html($online_msg); ?>

        <?php else : ?>

            <?php echo esc_html($c2c_msg); ?>

        <?php endif; ?>

    </div>

    <!-- اطلاعات سفارش -->

    <div class="fcui-order-meta">

        <div>
            <strong>شماره سفارش:</strong>
            #<?php echo esc_html($order->get_id()); ?>
        </div>

        <div>
            <strong>محصول:</strong>
            <?php echo esc_html($product_name); ?>
        </div>

        <div>
            <strong>مبلغ:</strong>
            <?php echo wp_kses_post($total); ?>
        </div>

    </div>

    <!-- دکمه -->

    <a href="<?php echo esc_url($course_link); ?>"
       class="fcui__btn fcui__btn--primary fcui-success-btn">

        <?php echo esc_html($button_text); ?>

    </a>

</div>

</div>

</div>

<?php wp_footer(); ?>

</body>
</html>
