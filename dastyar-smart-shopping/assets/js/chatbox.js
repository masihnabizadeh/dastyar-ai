(function($){
  function sid(){
    try{
      var s = sessionStorage.getItem('dss_sid');
      if(!s){ s = 'sid_'+Math.random().toString(36).slice(2)+Date.now(); sessionStorage.setItem('dss_sid', s); }
      return s;
    }catch(e){
      return 'sid_'+Date.now()+'_'+Math.random().toString(36).slice(2);
    }
  }
  function openChat(){ $('#dss-chat').fadeIn(120); setTimeout(showGreeting, 60); }
  function closeChat(){ $('#dss-chat').fadeOut(120); }
  function showGreeting(){
    var g = (window.DSS_CONFIG && DSS_CONFIG.greeting) ? DSS_CONFIG.greeting : 'سلام! آماده‌ام راهنمایی‌ت کنم.';
    $('#dss-chat .dss-msg.dss-bot').text(g);
    renderQuick(['قیمت','دسته‌بندی','نوع استفاده']);
  }
  function appendBot(text){
    var el = $('<div class="dss-msg dss-bot"></div>');
    el.text(text);
    $('#dss-chat .dss-body').append(el);
    scrollBody();
  }
  function renderCards(cards){
    if(!cards || !cards.length) return;
    var wrap = $('<div class="dss-cards"></div>');
    cards.forEach(function(c){
      var card = $('<div class="dss-card"></div>');
      if(c.image){ card.append($('<div class="dss-card-img"></div>').append($('<img/>').attr('src', c.image).attr('alt', c.title||''))); }
      card.append($('<div class="dss-card-title"></div>').text(c.title||''));
      if(c.price){ card.append($('<div class="dss-card-price"></div>').text(c.price)); }
      if(c.url){ card.append($('<a class="dss-card-link" target="_blank" rel="noopener">مشاهده محصول</a>').attr('href', c.url)); }
      wrap.append(card);
    });
    $('#dss-chat .dss-body').append(wrap);
    scrollBody();
  }
  function renderQuick(options){
    if(!options || !options.length) return;
    var bar = $('<div class="dss-quick"></div>');
    options.forEach(function(t){
      $('<button type="button" class="dss-quick-btn"></button>').text(t).appendTo(bar);
    });
    $('#dss-chat .dss-body').append(bar);
    scrollBody();
  }
  function scrollBody(){
    var sc = document.querySelector('#dss-chat .dss-body'); if(sc){ sc.scrollTop = sc.scrollHeight; }
  }

  $(document).on('click','#dss-launcher',openChat);
  $(document).on('click','#dss-chat .dss-close',closeChat);

  $(document).on('click','.dss-send',function(){
    var val = $('#dss-chat input').val().trim(); if(!val) return;
    $('#dss-chat input').val('');
    $('<div class="dss-msg dss-user"></div>').text(val).appendTo('#dss-chat .dss-body');
    scrollBody();
    sendToBot(val);
  });

  $(document).on('click','.dss-quick-btn',function(){
    var val = $(this).text();
    $('<div class="dss-msg dss-user"></div>').text(val).appendTo('#dss-chat .dss-body');
    $(this).closest('.dss-quick').remove();
    scrollBody();
    sendToBot(val);
  });

  function sendToBot(text){
    $.ajax({
      url: DSS_CONFIG.ajax_url,
      method: 'POST',
      data: { action: 'dss_chat_reply', nonce: DSS_CONFIG.nonce, message: text, sid: sid() },
      success: function(res){
        if(res && res.success && res.data){
          if(res.data.text){ appendBot(res.data.text); }
          if(res.data.cards){ renderCards(res.data.cards); }
          if(res.data.quick && res.data.quick.length){ renderQuick(res.data.quick); }
        }else{
          appendBot('خطا در دریافت پاسخ.');
        }
      },
      error: function(){ appendBot('ارتباط با سرور برقرار نشد.'); }
    });
  }
})(jQuery);
