(function(){
  var map = {'0':'۰','1':'۱','2':'۲','3':'۳','4':'۴','5':'۵','6':'۶','7':'۷','8':'۸','9':'۹'};
  function fa(s){ return String(s).replace(/[0-9]/g, function(d){ return map[d] || d; }); }
  function walk(node){
    if(!node) return;
    var skip = /^(SCRIPT|STYLE|TEXTAREA|INPUT|SELECT|OPTION|CODE|PRE)$/;
    if(node.nodeType === 1 && skip.test(node.nodeName)) return;
    if(node.nodeType === 3){
      if(/[0-9]/.test(node.nodeValue)) node.nodeValue = fa(node.nodeValue);
      return;
    }
    var kids = Array.prototype.slice.call(node.childNodes || []);
    kids.forEach(walk);
  }
  function convertAttrs(){
    document.querySelectorAll('[placeholder],[title],[aria-label]').forEach(function(el){
      ['placeholder','title','aria-label'].forEach(function(a){
        var v = el.getAttribute(a); if(v && /[0-9]/.test(v)) el.setAttribute(a, fa(v));
      });
    });
  }
  function run(){ walk(document.body); convertAttrs(); }
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
  window.FCUIFaDigits = fa;
})();
