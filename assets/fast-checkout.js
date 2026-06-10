document.addEventListener('DOMContentLoaded', function(){
  // Auto format phone
  const phoneInputs = document.querySelectorAll('input[name="billing_phone"]');
  phoneInputs.forEach(inp => {
    inp.addEventListener('input', function(){
      let v = this.value.replace(/\D/g,'');
      if(v.startsWith('98')) v = '0' + v.slice(2);
      if(v.length > 11) v = v.slice(0,11);
      this.value = v;
    });
  });
  
  // National code validation
  const nc = document.querySelector('input[name="billing_national_code"]');
  if(nc){
    nc.addEventListener('input', function(){
      this.value = this.value.replace(/\D/g,'').slice(0,10);
    });
  }
});
(function(){
  function scaleBankCardText(){
    document.querySelectorAll('.fcui-c2c__card').forEach(function(card){
      var w = card.getBoundingClientRect().width || 520;
      var scale = w / 520;
      card.querySelectorAll('[data-fcui-card-text]').forEach(function(el){
        var base = parseFloat(el.getAttribute('data-base-size') || '16');
        el.style.fontSize = (base * scale) + 'px';
      });
    });
  }
  window.FCUI_SCALE_BANK_CARD_TEXT = scaleBankCardText;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scaleBankCardText); else scaleBankCardText();
  window.addEventListener('resize', scaleBankCardText);
})();

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
      var code = input ? input.value.trim() : '';
      btn.classList.add('is-loading'); btn.disabled = true;
      hint.className = 'fcui__couponHint is-loading';
      hint.textContent = 'در حال بررسی کد تخفیف...';
      var fd = new FormData();
      fd.append('action','fcui_validate_coupon');
      fd.append('nonce',FCUI_COUPON.nonce);
      fd.append('coupon',code);
      ['product_id','variation_id','quantity'].forEach(function(n){ var el=form.querySelector('[name="'+n+'"]'); if(el) fd.append(n,el.value); });
      fetch(FCUI_COUPON.ajax_url,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(res){
        btn.classList.remove('is-loading'); btn.disabled=false;
        hint.className = 'fcui__couponHint ' + (res.success ? 'is-success' : 'is-error');
        hint.innerHTML = res.data && res.data.message ? res.data.message : (res.success ? 'کد تخفیف اعمال شد.' : 'کد تخفیف قابل استفاده نیست.');
        if(res.success && res.data && res.data.total){
          hint.innerHTML += ' <span class="fcui__couponTotal">مبلغ جدید: '+res.data.total+'</span>';
        }
      }).catch(function(){
        btn.classList.remove('is-loading'); btn.disabled=false;
        hint.className='fcui__couponHint is-error';
        hint.textContent='خطا در بررسی کد تخفیف. دوباره تلاش کنید.';
      });
    }, true);
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', initCouponAjax); else initCouponAjax();
})();
