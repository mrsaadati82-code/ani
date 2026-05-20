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
?>
<div class="fcui">
<div class="fcui__app">

<div class="fcui__topbar">
<a class="fcui__back" href="<?php echo esc_url($product_url); ?>">بازگشت</a>
<div class="fcui__topmeta">پرداخت امن • دسترسی آنی</div>
</div>

<main class="fcui__main">

<section class="fcui__summary">
<div class="fcui__thumb"><?php echo $image_html; ?></div>
<div class="fcui__sumBody">
<div class="fcui__name"><?php echo esc_html($title); ?></div>
<div class="fcui__sumRow">
<div class="fcui__sumPrice"><?php echo wp_kses_post(wc_price($price)); ?></div>
<?php if ($has_discount): ?>
<div class="fcui__sumDiscount">
<span class="fcui__sumRegular"><?php echo wp_kses_post(wc_price($regular)); ?></span>
<span class="fcui__badge"><?php echo esc_html($discount_pct); ?>% تخفیف</span>
</div>
<?php endif; ?>
</div>
<div class="fcui__hint"><?php echo esc_html($s['hint_text']); ?></div>
</div>
</section>

<?php if (!empty($_GET['err']) && $_GET['err'] === 'outofstock'): ?>
<div class="fcui__alert">این محصول در حال حاضر موجود نیست.</div>
<?php elseif (!empty($_GET['err'])): ?>
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

<section class="fcui__card">
<div class="fcui__cardHead">
<span class="fcui__step">1</span>
<span class="fcui__cardTitle">اطلاعات شما</span>
</div>
<div class="fcui__grid2">
<div class="fcui__field">
<label>نام و نام خانوادگی *</label>
<input name="billing_full_name" value="<?php echo esc_attr($prefill['full_name']); ?>" placeholder="مثال: علی محمدی" required>
</div>
<div class="fcui__field">
<label>شماره موبایل *</label>
<input name="billing_phone" value="<?php echo esc_attr($prefill['phone']); ?>" placeholder="0912 345 6789" required inputmode="tel">
</div>
</div>
<input type="hidden" name="billing_email" value="<?php echo esc_attr($prefill['email']); ?>">
</section>

<?php if(!$is_free && !empty($s['coupon_enabled'])): ?>
<section class="fcui__card fcui__coupon">
<div style="display:flex;gap:8px;align-items:center">
<input type="text" name="fcui_coupon" placeholder="<?php echo esc_attr($s['coupon_placeholder']); ?>" style="flex:1;padding:10px 12px;border:1px solid rgba(15,23,42,.12);border-radius:12px;background:#f8fafc;font-size:13px">
<span style="font-size:12px;color:#64748b;white-space:nowrap"><?php echo esc_html($s['coupon_label']); ?></span>
</div>
</section>
<?php endif; ?>

<?php if(!$is_free): ?>
<section class="fcui__card">
<div class="fcui__cardHead">
<span class="fcui__step">2</span>
<span class="fcui__cardTitle">انتخاب روش پرداخت</span>
</div>
<?php if (empty($gateways)): ?>
<div class="fcui__alert">هیچ روش پرداختی فعال نیست</div>
<?php else: ?>
<div class="fcui__gateways">
<?php foreach ($gateways as $id => $gateway): ?>
<label class="fcui__gw">
<input type="radio" name="payment_method" value="<?php echo esc_attr($id); ?>" <?php checked($id,$first_gateway); ?>>
<span class="fcui__gwBox">
<span class="fcui__gwIcon"><?php echo wp_kses_post($gateway->get_icon()); ?></span>
<span class="fcui__gwTitle"><?php echo esc_html($gateway->get_title()); ?></span>
<span class="fcui__gwCheck"></span>
</span>
</label>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>
<?php endif; ?>

<div class="fcui__spacer"></div>

<footer class="fcui__bottom">
<?php if($is_free): ?>
<button type="submit" name="fcui_free_register" value="1" class="fcui__btn fcui__btn--primary">
<?php echo esc_html($s['button_free_text']); ?>
</button>
<?php else: ?>
<button type="submit" name="fcui_pay_now" value="1" class="fcui__btn fcui__btn--primary">
<?php echo esc_html($s['button_pay_text']); ?>
</button>
<?php endif; ?>

<button type="submit" name="fcui_add_to_cart_later" value="1" class="fcui__btn fcui__btn--ghost">
<span class="fcui__btnLine"><?php echo esc_html($s['button_later_text']); ?></span>
<span class="fcui__btnSub">افزودن به سبد خرید</span>
</button>
</footer>

</form>
</main>
</div>
</div>