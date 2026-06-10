(function($){
  var faMap = {'0':'۰','1':'۱','2':'۲','3':'۳','4':'۴','5':'۵','6':'۶','7':'۷','8':'۸','9':'۹'};
  function toLatin(s){ return String(s||'').replace(/[۰-۹٠-٩]/g, function(ch){ return '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩'.indexOf(ch)%10; }); }
  function fa(s){ return String(s||'').replace(/[0-9]/g, function(d){ return faMap[d] || d; }); }
  function cleanCard(s){ return toLatin(s).replace(/\D+/g,''); }
  function formatCard(s){ var d = cleanCard(s); return fa(d.replace(/(.{4})/g,'$1 ').trim()); }
  function copyText(text){
    text = cleanCard(text || $('input[name="c2c_card_number"]').val());
    if(navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(text);
    var ta=document.createElement('textarea'); ta.value=text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
  }
  function ensureCopyButtons(){
    $('.fcui-card-preview').each(function(){
      if(!$(this).find('.fcui-card-preview__copy').length){
        $(this).append('<button type="button" class="fcui-card-preview__copy">کپی شماره کارت</button>');
      }
    });
  }
  function refreshCardTexts(){
    var num = $('input[name="c2c_card_number"]').val();
    var holder = $('input[name="c2c_holder_name"]').val();
    $('.fcui-card-preview__text[data-field="number"]').text(formatCard(num)).attr('data-copy-preview', cleanCard(num));
    $('.fcui-card-preview__text[data-field="holder"]').text(fa(holder));
    scaleDesignerText();
  }

  function scaleDesignerText(){
    $('.fcui-card-designer').each(function(){
      var $designer=$(this), w=$designer.find('.fcui-card-preview').outerWidth() || 520, scale=w/520;
      ['number','holder'].forEach(function(field){
        var base=parseFloat($designer.find('.fcui-card-control[data-field="'+field+'"][data-prop="size"]').val() || 16);
        $designer.find('.fcui-card-preview__text[data-field="'+field+'"]').css('font-size',(base*scale)+'px');
      });
    });
  }

  function updateText($designer, field, prop, value){
    var $txt = $designer.find('.fcui-card-preview__text[data-field="'+field+'"]');
    if(!$txt.length) return;
    if(prop === 'x') $txt.css('left', value + '%');
    if(prop === 'y') $txt.css('top', value + '%');
    if(prop === 'color') $txt.css('color', value);
    if(prop === 'size') { scaleDesignerText(); return; }
    if(prop === 'weight') $txt.css('font-weight', value);
    if(prop === 'shadow') $txt.css('text-shadow', value ? '0 2px 6px rgba(0,0,0,.65)' : 'none');
  }

  function showSelectedBank(bank){
    $('.fcui-card-designer').hide().filter('[data-bank="'+bank+'"]').show();
    $('input[name="c2c_theme"]').each(function(){
      var $label = $(this).closest('label');
      $label.css('border-color', this.value === bank ? '#1e6bff' : '#e2e8f0');
      $label.css('box-shadow', this.value === bank ? '0 0 0 3px rgba(30,107,255,.15)' : 'none');
    });
    scaleDesignerText();
  }

  $(document).on('change', 'input[name="c2c_theme"]', function(){ showSelectedBank(this.value); });
  $(document).on('input', 'input[name="c2c_card_number"], input[name="c2c_holder_name"]', refreshCardTexts);
  $(document).on('click', '.fcui-card-preview__copy', function(){
    copyText($(this).closest('.fcui-card-preview').find('[data-copy-preview]').attr('data-copy-preview'));
    var b=$(this), t=b.text(); b.text('کپی شد'); setTimeout(function(){b.text(t)},1200);
  });

  $(document).on('input change', '.fcui-card-control', function(){
    var $input = $(this), $designer = $input.closest('.fcui-card-designer');
    updateText($designer, $input.data('field'), $input.data('prop'), $input.is(':checkbox') ? $input.is(':checked') : $input.val());
  });

  $(document).on('mousedown touchstart', '.fcui-card-preview__text', function(e){
    var $txt=$(this), $designer=$txt.closest('.fcui-card-designer'), $preview=$txt.closest('.fcui-card-preview'), field=$txt.data('field'), isTouch=e.type==='touchstart';
    $txt.addClass('is-dragging'); e.preventDefault();
    function point(ev){ var o=isTouch?(ev.originalEvent.touches[0]||ev.originalEvent.changedTouches[0]):ev; return {x:o.clientX,y:o.clientY}; }
    function move(ev){
      var p=point(ev), r=$preview[0].getBoundingClientRect();
      var x=Math.round(Math.max(0,Math.min(100,((p.x-r.left)/r.width)*100))*10)/10;
      var y=Math.round(Math.max(0,Math.min(100,((p.y-r.top)/r.height)*100))*10)/10;
      $txt.css({left:x+'%',top:y+'%'});
      $designer.find('.fcui-card-control[data-field="'+field+'"][data-prop="x"]').val(x);
      $designer.find('.fcui-card-control[data-field="'+field+'"][data-prop="y"]').val(y);
      ev.preventDefault();
    }
    function up(){ $txt.removeClass('is-dragging'); $(document).off('.fcuiCard'); }
    $(document).on('mousemove.fcuiCard touchmove.fcuiCard', move).on('mouseup.fcuiCard touchend.fcuiCard touchcancel.fcuiCard', up);
  });


  function applyThemePreset(theme){
    var presets={
      classic:{primary_color:'#1e6bff',secondary_color:'#0f172a',background_color:'#f5f8ff',surface_color:'#ffffff',input_background:'#f8fafc',text_color:'#0f172a',border_color:'#e2e8f0'},
      neumorphic:{primary_color:'#3b82f6',secondary_color:'#64748b',background_color:'#e9eff7',surface_color:'#e9eff7',input_background:'#e9eff7',text_color:'#1e293b',border_color:'#cbd5e1'},
      skeuomorphic:{primary_color:'#b7791f',secondary_color:'#7c2d12',background_color:'#f7efe2',surface_color:'#fffaf0',input_background:'#fff7ed',text_color:'#2f2417',border_color:'#d6b98c'},
      dark_classic:{primary_color:'#60a5fa',secondary_color:'#a78bfa',background_color:'#070b14',surface_color:'#111827',input_background:'#0b1020',text_color:'#e5e7eb',border_color:'#334155'}
    };
    var p=presets[theme]; if(!p) return;
    Object.keys(p).forEach(function(name){
      var $i=$('[name="'+name+'"]'); if(!$i.length) return;
      $i.val(p[name]).trigger('input').trigger('change');
      if($i.hasClass('wp-color-picker')) { try{$i.wpColorPicker('color', p[name]);}catch(e){} }
      $i.closest('.wp-picker-container').find('.wp-color-result').css('background-color',p[name]);
    });
  }

  function updateStylePreview(){
    var $p=$('#fcui-style-preview'); if(!$p.length) return;
    var map={primary_color:'--p', secondary_color:'--s', background_color:'--bg', surface_color:'--card', input_background:'--input', text_color:'--txt', border_color:'--bd'};
    $('.fcui-live').each(function(){ var key=$(this).data('preview'), val=$(this).val(); if(map[key]) $p[0].style.setProperty(map[key],val); if(key==='button_radius') $p[0].style.setProperty('--br',val+'px'); if(key==='card_radius') $p[0].style.setProperty('--r',val+'px'); if(key==='font_family'){ $p.css('font-family',val||'inherit'); $('.fcui-card-preview').css('font-family',val||'inherit'); } });
    var theme=$('input[name="checkout_theme"]:checked').val()||'classic';
    var legacy={glass:'neumorphic',minimal:'classic',dark:'dark_classic',colorful:'skeuomorphic'};
    if(legacy[theme]) theme=legacy[theme];
    $p.removeClass(function(i,c){return (c.match(/fcui-preview-theme-\S+/g)||[]).join(' ');}).addClass('fcui-preview-theme-'+theme);
  }
  $(document).on('input change','.fcui-live, input[name="checkout_theme"]',updateStylePreview);

  $(document).on('click','[data-preview-tab]',function(){
    var tab=$(this).data('preview-tab');
    $(this).addClass('is-active').siblings().removeClass('is-active');
    $('.fcui-preview-pane').removeClass('is-active').filter('[data-preview-pane="'+tab+'"]').addClass('is-active');
  });

  $(document).on('change','input[name="checkout_theme"]',function(){ $('.fcui-theme-option').css({borderColor:'#e2e8f0',boxShadow:'none'}); $(this).closest('.fcui-theme-option').css({borderColor:'#1e6bff',boxShadow:'0 0 0 3px rgba(30,107,255,.15)'}); applyThemePreset(this.value); });

  $(window).on('resize', scaleDesignerText);
  $(function(){ ensureCopyButtons(); refreshCardTexts(); $('.fcui-card-preview').css('font-family', $('select[name="font_family"]').val() || 'inherit'); var checked=$('input[name="c2c_theme"]:checked').val(); if(checked) showSelectedBank(checked); updateStylePreview(); });
})(jQuery);
