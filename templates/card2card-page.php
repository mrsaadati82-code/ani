<?php
/** @var WC_Order $order */
$s = FCUI_Fast_Checkout::get_settings();

$card_number = (string)$s['c2c_card_number'];
$holder_name = (string)$s['c2c_holder_name'];
$bank_name   = (string)$s['c2c_bank_name'];

$expires_at  = (int)$order->get_meta('_fcui_c2c_expires_at');
$amount_html = wc_price($order->get_total());

$r1 = (string)$order->get_meta('_fcui_c2c_receipt_1');
$ok = !empty($_GET['ok']);
$expired = !empty($_GET['expired']);
$err = isset($_GET['err']) ? (int)$_GET['err'] : 0;

function fcui_err_msg($err){
    switch ($err) {
        case 2: return 'آپلود رسید  الزامی است.';
        case 3: return 'حجم فایل بیشتر از حد مجاز است.';
        case 4: return 'خطا در آپلود فایل. دوباره تلاش کنید.';
        default: return 'خطایی رخ داد. دوباره تلاش کنید.';
    }
}
?>
<div class="fcui-c2c">
  <div class="fcui-c2c__app">
    <div class="fcui-c2c__topbar">
      <a class="fcui-c2c__back" href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>">سفارش‌های من</a>
      <div class="fcui-c2c__meta">سفارش #<?php echo (int)$order->get_id(); ?></div>
    </div>

    <main class="fcui-c2c__main">
      <?php if ($ok): ?>
        <div class="fcui-c2c__success">رسید شما ثبت شد. پس از بررسی، دسترسی فعال می‌شود.</div>
      <?php endif; ?>
      <?php if ($expired): ?>
        <div class="fcui-c2c__warn">زمان واریز به پایان رسیده است. لطفاً با پشتیبانی هماهنگ کنید.</div>
      <?php endif; ?>
      <?php if ($err): ?>
        <div class="fcui-c2c__warn"><?php echo esc_html(fcui_err_msg($err)); ?></div>
      <?php endif; ?>

      <section class="fcui-c2c__card">
        <div class="fcui-c2c__bank"><?php echo esc_html($bank_name); ?></div>
        <div class="fcui-c2c__number" data-copy="<?php echo esc_attr($card_number); ?>">
          <?php echo esc_html($card_number); ?>
          <button type="button" class="fcui-c2c__copy" data-copy-btn="<?php echo esc_attr($card_number); ?>">کپی</button>
        </div>
        <div class="fcui-c2c__row">
          <div class="fcui-c2c__label">نام صاحب کارت</div>
          <div class="fcui-c2c__value" data-copy="<?php echo esc_attr($holder_name); ?>">
            <?php echo esc_html($holder_name); ?>
          </div>
        </div>
        <div class="fcui-c2c__row">
          <div class="fcui-c2c__label">مبلغ قابل واریز</div>
          <div class="fcui-c2c__value"><?php echo wp_kses_post($amount_html); ?></div>
        </div>

        <div class="fcui-c2c__timer" data-expires="<?php echo (int)$expires_at; ?>">
          <span>زمان باقی‌مانده:</span>
          <strong class="fcui-c2c__time">--:--</strong>
        </div>
      </section>

      <section class="fcui-c2c__upload">
        <form method="post" enctype="multipart/form-data">
          <?php wp_nonce_field('fcui_card2card', 'fcui_nonce'); ?>

          <div class="fcui-c2c__urow">
            <label class="fcui-c2c__ulabel">رسید ۱ (اجباری)</label>
            <input id="fcui-receipt-input" type="file" name="receipt_1" accept="image/*,.pdf" required>

            <button type="button" id="fcui-view-receipt" class="fcui-c2c__link" style="display:none">
              مشاهده رسید
            </button>

            <?php if ($r1): ?>
            <a class="fcui-c2c__link" target="_blank" href="<?php echo esc_url($r1); ?>">مشاهده رسید</a>
            <?php endif; ?>

          </div>

          

          <button type="submit" class="fcui-c2c__submit" <?php echo $expired ? 'disabled' : ''; ?>>
            ثبت رسید و ارسال برای بررسی
          </button>

          <div class="fcui-c2c__note">
            دسترسی پس از تأیید توسط مدیر فعال می‌شود.
          </div>
        </form>
      </section>
    </main>
  </div>
  <script>
document.addEventListener("DOMContentLoaded",function(){

const input = document.getElementById("fcui-receipt-input")
const btn = document.getElementById("fcui-view-receipt")

if(!input) return

input.addEventListener("change",function(){

const file = this.files[0]
if(!file) return

const url = URL.createObjectURL(file)

btn.style.display="inline-block"

btn.onclick=function(){

let modal=document.getElementById("fcui-receipt-modal")

if(!modal){

modal=document.createElement("div")
modal.id="fcui-receipt-modal"
modal.style.position="fixed"
modal.style.inset="0"
modal.style.background="rgba(0,0,0,.7)"
modal.style.display="flex"
modal.style.alignItems="center"
modal.style.justifyContent="center"
modal.style.zIndex="99999"

modal.innerHTML=`
<div style="background:#fff;padding:10px;border-radius:10px;max-width:90vw">
<img src="${url}" style="max-width:80vw;max-height:80vh">
</div>
`

modal.onclick=function(){
modal.remove()
}

document.body.appendChild(modal)

}

}

})

})
</script>

</div>