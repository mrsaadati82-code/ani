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