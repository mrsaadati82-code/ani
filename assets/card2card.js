(function () {
  function copyText(text) {
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text);
      return;
    }
    var ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch(e) {}
    document.body.removeChild(ta);
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-copy-btn]');
    if (!btn) return;
    e.preventDefault();
    copyText(btn.getAttribute('data-copy-btn'));
    btn.textContent = 'کپی شد';
    setTimeout(function(){ btn.textContent = 'کپی'; }, 1200);
  });

  // show receipt2 after receipt1 chosen
  document.addEventListener('change', function (e) {
    if (!e.target || e.target.name !== 'receipt_1') return;
    var r2 = document.getElementById('fcui-receipt2');
    if (r2) r2.classList.remove('is-hidden');
  });

  // Timer
  var timer = document.querySelector('.fcui-c2c__timer');
  if (!timer) return;

  var expires = parseInt(timer.getAttribute('data-expires') || '0', 10);
  var out = document.querySelector('.fcui-c2c__time');

  function pad(n){ return n < 10 ? '0'+n : ''+n; }

  function tick() {
    var now = Math.floor(Date.now()/1000);
    var diff = expires - now;
    if (diff <= 0) {
      if (out) out.textContent = '00:00';
      var btn = document.querySelector('.fcui-c2c__submit');
      if (btn) btn.setAttribute('disabled','disabled');
      return;
    }
    var m = Math.floor(diff/60);
    var s = diff % 60;
    if (out) out.textContent = pad(m) + ':' + pad(s);
    requestAnimationFrame(function(){
      // update about each second
    });
  }

  tick();
  setInterval(tick, 1000);
})();
document.addEventListener("DOMContentLoaded", function(){

  document.querySelectorAll("form").forEach(function(form){

    form.addEventListener("submit", function(){

      const btn = form.querySelector("button[type=submit]");

      if(!btn) return;

      btn.disabled = true;

      const txt = btn.innerText;

      btn.dataset.original = txt;

      btn.innerText = "در حال ارسال...";

    });

  });

});
