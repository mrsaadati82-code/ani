<?php
$title = $parent_product ? $parent_product->get_name() : $product->get_name();
$image_html = $product->get_image('thumbnail');
if (!$image_html && $parent_product) $image_html = $parent_product->get_image('thumbnail');

$regular = (float) ($parent_product ? $parent_product->get_regular_price() : $product->get_regular_price());
$price   = (float) $product->get_price();
$is_free = ($price <= 0);

$has_discount = ($regular > 0 && $price > 0 && $price < $regular);
$discount_pct = $has_discount ? (int) round((($regular - $price) / $regular) * 100) : 0;

$first_gateway = '';
if (!empty($gateways)) { foreach ($gateways as $k => $v) { $first_gateway = $k; break; } }

$s = FCUI_Fast_Checkout::get_settings();
$fields = (array)$s['physical_fields'];
?>
<div class="fcui fcui-physical">
<div class="fcui__app">

<div class="fcui__topbar">
<a class="fcui__back" href="<?php echo esc_url($product_url); ?>">بازگشت</a>
<div class="fcui__topmeta">ارسال به سراسر ایران</div>
</div>

<main class="fcui__main">

<section class="fcui__summary" style="padding:10px">
<div class="fcui__thumb" style="width:56px;height:56px"><?php echo $image_html; ?></div>
<div class="fcui__sumBody">
<div class="fcui__name" style="font-size:13px"><?php echo esc_html($title); ?></div>
<div class="fcui__sumRow">
<div class="fcui__sumPrice" style="font-size:16px"><?php echo wp_kses_post(FCUI_Fast_Checkout::price_html($price)); ?></div>
<?php if ($has_discount): ?>
<span class="fcui__badge"><?php echo esc_html($discount_pct); ?>%</span>
<?php endif; ?>
</div>
</div>
</section>

<?php if (!empty($_GET['err'])): ?>
<div class="fcui__alert">لطفاً اطلاعات را کامل کنید.</div>
<?php endif; ?>

<form class="fcui__form" method="post">
<?php wp_nonce_field('fcui_fast_checkout', 'fcui_nonce'); ?>
<?php wp_nonce_field('woocommerce-process_checkout', 'woocommerce-process-checkout-nonce'); ?>

<input type="hidden" name="product_id" value="<?php echo (int)$product_id; ?>">
<input type="hidden" name="variation_id" value="<?php echo (int)$variation_id; ?>">
<input type="hidden" name="quantity" value="<?php echo (int)$qty; ?>">
<?php foreach ($variation as $k => $v): ?>
<input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>">
<?php endforeach; ?>

<section class="fcui__card" style="padding:10px">
<div class="fcui__cardHead" style="margin-bottom:8px">
<span class="fcui__step">1</span>
<span class="fcui__cardTitle">اطلاعات گیرنده</span>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
<div class="fcui__field">
<label style="font-size:11px;margin-bottom:4px">نام و نام خانوادگی *</label>
<input name="billing_full_name" value="<?php echo esc_attr($prefill['full_name']); ?>" required style="padding:9px 10px;font-size:13px">
</div>
<div class="fcui__field">
<label style="font-size:11px;margin-bottom:4px">موبایل *</label>
<input name="billing_phone" value="<?php echo esc_attr($prefill['phone']); ?>" required inputmode="tel" style="padding:9px 10px;font-size:13px">
</div>
</div>

<?php if (in_array('billing_state', $fields) || in_array('billing_city', $fields)): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px">
<?php if (in_array('billing_state', $fields)): ?>
<div class="fcui__field">
<label style="font-size:11px;margin-bottom:4px">استان *</label>
<input name="billing_state" value="<?php echo esc_attr($prefill['state']); ?>" required style="padding:9px 10px;font-size:13px">
</div>
<?php endif; ?>
<?php if (in_array('billing_city', $fields)): ?>
<div class="fcui__field">
<label style="font-size:11px;margin-bottom:4px">شهر *</label>
<input name="billing_city" value="<?php echo esc_attr($prefill['city']); ?>" required style="padding:9px 10px;font-size:13px">
</div>
<?php endif; ?>
</div>
<?php endif; ?>

<?php if (in_array('billing_address_1', $fields)): ?>
<div class="fcui__field" style="margin-top:8px">
<label style="font-size:11px;margin-bottom:4px">آدرس کامل *</label>
<input name="billing_address_1" value="<?php echo esc_attr($prefill['address']); ?>" required placeholder="خیابان، کوچه، پلاک" style="padding:9px 10px;font-size:13px">
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px">
<?php if (in_array('billing_postcode', $fields)): ?>
<div class="fcui__field">
<label style="font-size:11px;margin-bottom:4px">کد پستی</label>
<input name="billing_postcode" value="<?php echo esc_attr($prefill['postcode']); ?>" inputmode="numeric" style="padding:9px 10px;font-size:13px">
</div>
<?php endif; ?>
<?php if (!empty($s['enable_national_code'])): ?>
<div class="fcui__field">
<label style="font-size:11px;margin-bottom:4px"><?php echo esc_html($s['national_code_label']); ?><?php echo !empty($s['national_code_required'])?' *':''; ?></label>
<input name="billing_national_code" <?php echo !empty($s['national_code_required'])?'required':''; ?> inputmode="numeric" style="padding:9px 10px;font-size:13px">
</div>
<?php endif; ?>
</div>

<input type="hidden" name="billing_email" value="<?php echo esc_attr($prefill['email']); ?>">
</section>

<?php if(!$is_free && !empty($s['coupon_enabled'])): ?>
<div class="fcui__coupon fcui__coupon--compact">
<label class="fcui__couponLabel"><?php echo esc_html($s['coupon_label']); ?></label>
<div class="fcui__couponRow">
<input type="text" name="fcui_coupon" placeholder="<?php echo esc_attr($s['coupon_placeholder']); ?>">
<button type="button" class="fcui__couponBtn">اعمال</button>
</div>
</div>
<?php endif; ?>

<?php if(!$is_free): ?>
<section class="fcui__card" style="padding:10px;margin-top:8px">
<div class="fcui__cardHead" style="margin-bottom:8px">
<span class="fcui__step">2</span>
<span class="fcui__cardTitle" style="font-size:12px">پرداخت</span>
</div>
<div class="fcui__gateways" style="gap:8px">
<?php foreach ($gateways as $id => $gateway): ?>
<label class="fcui__gw">
<input type="radio" name="payment_method" value="<?php echo esc_attr($id); ?>" <?php checked($id,$first_gateway); ?>>
<span class="fcui__gwBox" style="min-height:70px;padding:8px;flex:0 0 100px">
<span class="fcui__gwIcon" style="transform:scale(.85)"><?php echo wp_kses_post($gateway->get_icon()); ?></span>
<span class="fcui__gwTitle" style="font-size:11px"><?php echo esc_html($gateway->get_title()); ?></span>
<span class="fcui__gwCheck"></span>
</span>
</label>
<?php endforeach; ?>
</div>
</section>
<?php endif; ?>

<footer class="fcui__bottom" style="margin-top:8px;padding:8px;gap:8px">
<?php if($is_free): ?>
<button type="submit" name="fcui_free_register" value="1" class="fcui__btn fcui__btn--primary" style="padding:10px;font-size:13px">
<?php echo esc_html($s['button_free_text']); ?>
</button>
<?php else: ?>
<button type="submit" name="fcui_pay_now" value="1" class="fcui__btn fcui__btn--primary" style="padding:10px;font-size:13px">
<?php echo esc_html($s['button_pay_text']); ?>
</button>
<?php endif; ?>
<button type="submit" name="fcui_add_to_cart_later" value="1" class="fcui__btn fcui__btn--ghost" style="padding:8px;font-size:12px">
<?php echo esc_html($s['button_later_text']); ?>
</button>
</footer>

</form>
</main>
</div>
</div>