jQuery(function($){
  function fa(n){return String(n).replace(/[0-9]/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d]);}
  function latin(s){return String(s||'').replace(/[۰-۹]/g,ch=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(ch));}
  var today={jy:1403,jm:1,jd:1};
  // rough current from Intl if available
  try{var parts=new Intl.DateTimeFormat('fa-IR-u-ca-persian',{year:'numeric',month:'numeric',day:'numeric'}).formatToParts(new Date()); today={jy:+latin(parts.find(p=>p.type==='year').value),jm:+latin(parts.find(p=>p.type==='month').value),jd:+latin(parts.find(p=>p.type==='day').value)};}catch(e){}
  function daysInMonth(y,m){return m<=6?31:(m<=11?30:29)}
  function openPicker($input){
    $('.fcui-jcal').remove();
    var val=latin($input.val()), m=val.match(/(13|14)\d{2}[\/\-](\d{1,2})[\/\-](\d{1,2})(?:\s+(\d{1,2}):(\d{1,2}))?/);
    var state=m?{jy:+m[0].slice(0,4),jm:+m[2],jd:+m[3],hh:+(m[4]||12),mi:+(m[5]||0)}:{jy:today.jy,jm:today.jm,jd:today.jd,hh:12,mi:0};
    var box=$('<div class="fcui-jcal"></div>').appendTo('body');
    function render(){
      var html='<div class="fcui-jcal-head"><button type="button" data-nav="-1">‹</button><strong>'+fa(state.jy)+'/'+fa(String(state.jm).padStart(2,'0'))+'</strong><button type="button" data-nav="1">›</button></div><div class="fcui-jcal-week"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div class="fcui-jcal-days">';
      for(var d=1; d<=daysInMonth(state.jy,state.jm); d++) html+='<button type="button" data-day="'+d+'" class="'+(d===state.jd?'is-active':'')+'">'+fa(d)+'</button>';
      html+='</div><div class="fcui-jcal-time"><input type="number" min="0" max="23" value="'+state.hh+'"><span>:</span><input type="number" min="0" max="59" value="'+state.mi+'"></div><button type="button" class="fcui-jcal-ok">انتخاب</button>';
      box.html(html);
      var off=$input.offset(); box.css({top:off.top+$input.outerHeight()+6,left:off.left});
    }
    render();
    box.on('click','[data-nav]',function(){state.jm+=parseInt($(this).data('nav'),10); if(state.jm<1){state.jm=12;state.jy--} if(state.jm>12){state.jm=1;state.jy++} render();});
    box.on('click','[data-day]',function(){state.jd=parseInt($(this).data('day'),10); box.find('[data-day]').removeClass('is-active'); $(this).addClass('is-active');});
    box.on('click','.fcui-jcal-ok',function(){state.hh=+box.find('.fcui-jcal-time input').eq(0).val()||0; state.mi=+box.find('.fcui-jcal-time input').eq(1).val()||0; $input.val(fa(state.jy)+'/'+fa(String(state.jm).padStart(2,'0'))+'/'+fa(String(state.jd).padStart(2,'0'))+' '+fa(String(state.hh).padStart(2,'0'))+':'+fa(String(state.mi).padStart(2,'0'))); box.remove();});
  }
  $(document).on('focus click','.fcui-jalali-datetime',function(){openPicker($(this));});
  $(document).on('mousedown',function(e){if(!$(e.target).closest('.fcui-jcal,.fcui-jalali-datetime').length) $('.fcui-jcal').remove();});
});
