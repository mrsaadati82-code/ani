jQuery(function ($) {
  if (!window.FCUI_REDIRECT) return;

  var fastBase = FCUI_REDIRECT.fast_url;

  (function injectPopupStyle(){
    if(document.getElementById('fcui-popup-style')) return;
    var st=document.createElement('style'); st.id='fcui-popup-style';
    st.textContent='html.fcui-popup-open,html.fcui-popup-open body{overflow:hidden!important}.fcui-popup-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.62);backdrop-filter:blur(8px);z-index:999998}.fcui-popup-box{position:fixed;z-index:999999;inset:clamp(10px,3vh,28px) clamp(8px,3vw,36px);max-width:760px;margin:auto;border-radius:24px;overflow:hidden;background:#fff;box-shadow:0 28px 80px rgba(0,0,0,.35)}.fcui-popup-box iframe{width:100%;height:100%;border:0;display:block;background:#fff}.fcui-popup-close{position:absolute;top:10px;left:10px;z-index:2;border:0;width:34px;height:34px;border-radius:50%;background:rgba(15,23,42,.72);color:#fff;font-size:24px;line-height:34px;cursor:pointer}';
    document.head.appendChild(st);
  })();


  function requireLoginRedirect() {
    if (FCUI_REDIRECT.require_login && !FCUI_REDIRECT.is_logged_in) {
      window.location = FCUI_REDIRECT.login_url;
      return true;
    }
    return false;
  }

  function isFastUrl(href) {
    if (!href) return false;
    return href.indexOf(fastBase) === 0;
  }


  function openPopup(url) {
    if (parseInt(FCUI_REDIRECT.popup_mode || 0, 10) !== 1) return false;
    var old = document.getElementById('fcui-popup-modal');
    if (old) old.remove();
    var modal = document.createElement('div');
    modal.id = 'fcui-popup-modal';
    modal.innerHTML = '<div class="fcui-popup-backdrop" data-fcui-close="1"></div><div class="fcui-popup-box fcui-popup-loading"><button type="button" class="fcui-popup-close" data-fcui-close="1">×</button><div class="fcui-popup-content"><div class="fcui-popup-spinner">در حال آماده‌سازی پرداخت...</div></div></div>';
    document.body.appendChild(modal);
    document.documentElement.classList.add('fcui-popup-open');
    var theme = String(FCUI_REDIRECT.style_theme || 'classic');
    if ({glass:1,minimal:1,dark:1,colorful:1}[theme]) theme = {glass:'neumorphic',minimal:'classic',dark:'dark_classic',colorful:'skeuomorphic'}[theme];
    document.body.classList.add('fcui-fast-checkout','fcui-popup-mode','fcui-style-' + theme);
    modal.addEventListener('click', function(e){
      if (e.target && e.target.getAttribute('data-fcui-close')) {
        modal.remove();
        document.documentElement.classList.remove('fcui-popup-open');
        document.body.classList.remove('fcui-fast-checkout','fcui-popup-mode','fcui-style-classic','fcui-style-neumorphic','fcui-style-skeuomorphic','fcui-style-dark_classic');
      }
    });

    fetch(url, {credentials:'same-origin'})
      .then(function(r){ return r.text(); })
      .then(function(html){
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var content = doc.querySelector('.fcui') || doc.querySelector('body');
        var box = modal.querySelector('.fcui-popup-box');
        var target = modal.querySelector('.fcui-popup-content');
        target.innerHTML = content ? (content.classList && content.classList.contains('fcui') ? content.outerHTML : content.innerHTML) : html;
        box.classList.remove('fcui-popup-loading');
        target.querySelectorAll('form').forEach(function(f){ f.setAttribute('action', url); f.setAttribute('method','post'); });
        if (window.FCUI_SCALE_BANK_CARD_TEXT) setTimeout(window.FCUI_SCALE_BANK_CARD_TEXT, 50);
        target.querySelectorAll('script').forEach(function(sc){
          var n=document.createElement('script');
          if(sc.src) n.src=sc.src; else n.textContent=sc.textContent;
          document.body.appendChild(n);
        });
      })
      .catch(function(){ window.location.href = url; });
    return true;
  }

  function getProductIdFromElement(el) {
    var $el = $(el);
    var pid = parseInt(
      $el.data('product_id') ||
      $el.attr('data-product_id') ||
      $el.val() ||
      $el.attr('value') ||
      0,
      10
    );
    if (pid) return pid;

    try {
      var href = $el.attr('href');
      if (href) {
        var url = new URL(href, window.location.origin);
        pid = parseInt(url.searchParams.get('add-to-cart') || url.searchParams.get('product_id') || 0, 10);
        if (pid) return pid;
      }
    } catch(e) {}

    return parseInt(FCUI_REDIRECT.product_id || 0, 10) || 0;
  }

  function buildFastUrl(pid, qty, extra) {
    pid = parseInt(pid || 0, 10);
    if (!pid) return '';
    var url = new URL(fastBase, window.location.origin);
    url.searchParams.set('product_id', pid);
    url.searchParams.set('quantity', parseInt(qty || 1, 10) || 1);
    if (extra) {
      Object.keys(extra).forEach(function(k){ if (extra[k]) url.searchParams.set(k, extra[k]); });
    }
    return url.toString();
  }


  // Cart page / checkout buttons: if cart has one compatible product, send to fast checkout.
  if (FCUI_REDIRECT.cart_fast_url) {
    $('a.checkout-button, a[href*="/checkout"], a[href*="checkout"], .wc-block-cart__submit-button').each(function(){
      if ($(this).is('a')) $(this).attr('href', FCUI_REDIRECT.cart_fast_url);
    });
    $(document).on('click', 'a.checkout-button, a[href*="/checkout"], a[href*="checkout"], .wc-block-cart__submit-button', function(e){
      if (!FCUI_REDIRECT.cart_fast_url) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      if (!openPopup(FCUI_REDIRECT.cart_fast_url)) window.location.href = FCUI_REDIRECT.cart_fast_url;
    });
  }

  // Convert archive/theme links and buttons like ?add-to-cart=XXXX or data-product_id to fast-checkout.
  $('a[href*="add-to-cart="], a.add_to_cart_button, a.ajax_add_to_cart').each(function () {
    var pid = getProductIdFromElement(this);
    if (!pid) return;
    var qty = parseInt($(this).data('quantity') || $(this).attr('data-quantity') || 1, 10) || 1;
    var newUrl = buildFastUrl(pid, qty);
    if (!newUrl) return;
    $(this)
      .attr('href', newUrl)
      .removeClass('ajax_add_to_cart add_to_cart_button')
      .removeAttr('data-product_id data-product_sku data-quantity aria-label rel');
  });

  // Force navigation even if other scripts preventDefault (Capture phase)
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a') : null;
    if (!a) return;
    if (!isFastUrl(a.href)) return;

    var urlObj;
    try { urlObj = new URL(a.href, window.location.origin); } catch(err) { return; }
    if (!parseInt(urlObj.searchParams.get('product_id') || 0, 10)) return;

    if (requireLoginRedirect()) {
      e.preventDefault(); e.stopImmediatePropagation(); return;
    }
    e.preventDefault();
    e.stopImmediatePropagation();
    if (!openPopup(a.href)) window.location.href = a.href;
  }, true);

  // Product forms: simple, grouped, variable, plugin/custom buttons.
  $(document).on('submit', 'form.cart', function (e) {
    if (FCUI_REDIRECT.require_login && !FCUI_REDIRECT.is_logged_in) {
      e.preventDefault(); window.location = FCUI_REDIRECT.login_url; return;
    }

    var $form = $(this);
    var isVariable = $form.hasClass('variations_form');
    var variationId = parseInt($form.find('input[name=variation_id]').val() || 0, 10);
    if (isVariable && (!variationId || variationId === 0)) return;

    var submitter = e.originalEvent && e.originalEvent.submitter ? e.originalEvent.submitter : null;
    var productId = parseInt(
      $form.find('input[name=product_id]').val() ||
      $form.find('input[name=add-to-cart]').val() ||
      (submitter ? $(submitter).val() : 0) ||
      $form.find('[name=add-to-cart]').val() ||
      FCUI_REDIRECT.product_id ||
      0,
      10
    );

    if (!productId) return; // do not break unsupported forms

    e.preventDefault();
    e.stopImmediatePropagation();

    var qty = parseInt($form.find('input.qty, input[name=quantity]').val() || 1, 10) || 1;
    var extra = {};
    if (variationId) extra.variation_id = variationId;
    $form.find('select[name^=attribute_], input[name^=attribute_]').each(function () {
      var name = $(this).attr('name');
      var val = $(this).val();
      if (name && val) extra[name] = val;
    });

    var redirect = buildFastUrl(productId, qty, extra);
    if (redirect) { if (!openPopup(redirect)) window.location.href = redirect; }
  });
});

(function(){
  function initCouponAjax(){
    document.addEventListener('click', function(e){
      var btn = e.target && e.target.closest ? e.target.closest('.fcui__couponBtn') : null;
      if(!btn || !window.FCUI_COUPON) return;
      e.preventDefault();
      var form = btn.closest('form') || document;
      var box = btn.closest('.fcui__coupon') || btn.parentNode;
      var input = box.querySelector('input[name="fcui_coupon"]');
      var hint = box.querySelector('.fcui__couponHint') || document.createElement('div');
      hint.className = 'fcui__couponHint';
      if(!hint.parentNode) box.appendChild(hint);
      var fd = new FormData();
      fd.append('action','fcui_validate_coupon'); fd.append('nonce',FCUI_COUPON.nonce); fd.append('coupon', input ? input.value.trim() : '');
      ['product_id','variation_id','quantity'].forEach(function(n){ var el=form.querySelector('[name="'+n+'"]'); if(el) fd.append(n,el.value); });
      btn.classList.add('is-loading'); btn.disabled=true; hint.className='fcui__couponHint is-loading'; hint.textContent='در حال بررسی کد تخفیف...';
      fetch(FCUI_COUPON.ajax_url,{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(function(res){
        btn.classList.remove('is-loading'); btn.disabled=false; hint.className='fcui__couponHint '+(res.success?'is-success':'is-error');
        hint.innerHTML=(res.data&&res.data.message)?res.data.message:(res.success?'کد تخفیف اعمال شد.':'کد تخفیف قابل استفاده نیست.');
        if(res.success && res.data && res.data.total) hint.innerHTML += ' <span class="fcui__couponTotal">مبلغ جدید: '+res.data.total+'</span>';
      }).catch(function(){btn.classList.remove('is-loading'); btn.disabled=false; hint.className='fcui__couponHint is-error'; hint.textContent='خطا در بررسی کد تخفیف. دوباره تلاش کنید.';});
    }, true);
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', initCouponAjax); else initCouponAjax();
})();
