(function($){
  function safeName(text){ return (text || 'qr').replace(/[^a-z0-9-_]+/gi,'-').replace(/^-+|-+$/g,''); }

  function wrapText(ctx, text, maxWidth, maxLines){
    text = text || '';
    var words = text.split(/\s+/), line = '', lines = [];
    for (var i=0;i<words.length;i++){
      var test = line ? line + ' ' + words[i] : words[i];
      if(ctx.measureText(test).width > maxWidth && line){ lines.push(line); line = words[i]; }
      else { line = test; }
    }
    if(line) lines.push(line);
    return lines.slice(0, maxLines || 2);
  }

  function drawQrToCanvas(canvas, payload, serial, inventoryName, hospitalName, qrId){
    if (!window.HIM_QRCode || !canvas) return;
    var qr = new window.HIM_QRCode(-1, window.HIM_QRErrorCorrectLevel.M);
    qr.addData(payload || serial || 'Inventory');
    qr.make();
    var ctx = canvas.getContext('2d');
    var isInline = $(canvas).closest('.him-inline-qr-card').length > 0;
    var isMini = $(canvas).closest('.him-mini-qr-card').length > 0;
    var size = isMini ? 170 : (isInline ? 220 : 260);
    var top = isMini ? 6 : (isInline ? 34 : 38);
    var padding = isMini ? 12 : (isInline ? 12 : 14);
    var bottom = isMini ? 90 : 70;
    canvas.width = size; canvas.height = size + top + bottom;
    ctx.fillStyle = '#fff'; ctx.fillRect(0,0,canvas.width,canvas.height);

    if(!isMini){
      ctx.fillStyle = '#111';
      ctx.font = 'bold ' + (isInline ? '14px' : '16px') + ' Arial';
      ctx.textAlign = 'center';
      ctx.fillText(serial || '', size/2, isInline ? 22 : 24);
    }

    var count = qr.getModuleCount();
    var cell = Math.floor((size - padding*2) / count);
    var qrSize = cell * count;
    var left = Math.floor((size - qrSize) / 2);
    var qrTop = top + padding;
    ctx.fillStyle = '#111';
    for (var r=0;r<count;r++){
      for (var c=0;c<count;c++){
        if(qr.isDark(r,c)) ctx.fillRect(left + c*cell, qrTop + r*cell, cell, cell);
      }
    }

    ctx.fillStyle = '#111';
    ctx.textAlign = 'center';
    var maxWidth = size - (isMini ? 16 : 18);
    var y = top + padding + qrSize + (isMini ? 13 : 22);

    if(isMini){
      // Management tab QR: keep it compact but print QR ID/serial, inventory name, and hospital name inside the canvas.
      var displayId = serial || qrId || '';
      ctx.font = 'bold 15px Arial';
      wrapText(ctx, displayId ? ('ID: ' + displayId) : '', maxWidth, 1).forEach(function(l, idx){ ctx.fillText(l, size/2, y + (idx * 17)); });
      ctx.font = 'bold 14px Arial';
      wrapText(ctx, inventoryName || '', maxWidth, 2).forEach(function(l, idx){ ctx.fillText(l, size/2, y + 20 + (idx * 16)); });
      ctx.font = '12px Arial';
      wrapText(ctx, hospitalName || '', maxWidth, 2).forEach(function(l, idx){ ctx.fillText(l, size/2, y + 52 + (idx * 14)); });
    } else {
      ctx.font = 'bold ' + (isInline ? '13px' : '15px') + ' Arial';
      wrapText(ctx, inventoryName || '', maxWidth, 2).forEach(function(l, idx){ ctx.fillText(l, size/2, y + (idx * 16)); });
      ctx.font = (isInline ? '12px' : '13px') + ' Arial';
      wrapText(ctx, hospitalName || '', maxWidth, 2).forEach(function(l, idx){ ctx.fillText(l, size/2, y + 36 + (idx * 15)); });
    }
  }

  function downloadCanvas(card){
    var canvas = card.find('canvas')[0];
    var serial = card.data('serial');
    var name = card.data('name');
    var a = document.createElement('a');
    a.href = canvas.toDataURL('image/png');
    a.download = safeName(serial + '-' + name) + '.png';
    document.body.appendChild(a); a.click(); a.remove();
  }

  async function downloadZip(cards){
    if(!window.JSZip){ alert('ZIP library not loaded'); return; }
    var zip = new JSZip();
    cards.each(function(){
      var card = $(this), canvas = card.find('canvas')[0];
      var data = canvas.toDataURL('image/png').split(',')[1];
      zip.file(safeName(card.data('serial') + '-' + card.data('name')) + '.png', data, {base64:true});
    });
    var blob = await zip.generateAsync({type:'blob'});
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'inventory-qr-codes.zip';
    document.body.appendChild(a); a.click(); setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  }
  function selectedQrCards(){ return $('.him-qr-card').has('.him-qr-check:checked'); }

  function himSubmitInventoryForm(form){
    var formEl = form && form[0] ? form[0] : null;
    if(!formEl){ return; }

    form.attr('data-him-confirmed', '1');
    form.data('him-confirmed', true);

    if(!form.find('input[name="him_confirmed_add_inventory"]').length){
      form.append('<input type="hidden" name="him_confirmed_add_inventory" value="1">');
    }

    // Native submit sends the form to admin-post.php immediately and does not reopen the popup.
    if (window.HTMLFormElement && HTMLFormElement.prototype.submit) {
      HTMLFormElement.prototype.submit.call(formEl);
    } else {
      formEl.submit();
    }
  }

  function showAddConfirm(form){
    $('.him-confirm-overlay').remove();
    var overlay = $('<div class="him-confirm-overlay"><div class="him-confirm-box"><h3>Are you sure you want to add this inventory?</h3><div class="him-confirm-actions"><button type="button" class="button button-primary him-confirm-yes">Yes</button><button type="button" class="button him-confirm-no">No</button></div></div></div>');
    $('body').append(overlay);
    overlay.on('click', '.him-confirm-no', function(e){
      e.preventDefault();
      overlay.remove();
      form.attr('data-him-confirmed', '0');
      form.data('him-confirmed', false);
    });
    overlay.on('click', '.him-confirm-yes', function(e){
      e.preventDefault();
      if(!form[0]){ return; }
      overlay.find('.him-confirm-yes').prop('disabled', true).text('Saving...');
      himSubmitInventoryForm(form);
    });
  }

  function initMakeOther(){
    var select = $('.him-make-select');
    var other = $('.him-make-other-field');
    function toggle(){
      if(select.val() === 'Other'){ other.show(); }
      else { other.hide().find('input').val(''); }
    }
    select.on('change', toggle); toggle();
  }

  function initFocalHospitalAutoFill(){
    // Focal fields are manual only; do not auto-fill hospital/person from user meta or datalist.
    return;
  }

  function initUserSerialValidation(){
    var timer = null;
    var pending = null;
    var input = $('.him-user-serial-number');
    var message = $('.him-serial-check-message');
    if(!input.length || !message.length) return;

    function setMessage(text, isError){
      message.text(text || '').toggleClass('is-error', !!isError).toggleClass('is-ok', !!text && !isError);
      input.attr('data-serial-exists', isError ? '1' : '0');
    }

    function checkNow(){
      var value = (input.val() || '').toString().replace(/\D+/g, '');
      if(input.val() !== value){ input.val(value); }
      if(!value){ setMessage('', false); return; }
      if(pending && pending.readyState !== 4){ pending.abort(); }
      message.text('Checking...').removeClass('is-error is-ok');
      pending = $.post((window.HIM_DATA && HIM_DATA.ajaxurl) ? HIM_DATA.ajaxurl : ajaxurl, {
        action: 'him_check_serial_number',
        nonce: window.HIM_DATA ? HIM_DATA.nonce : '',
        serial_number: value,
        id: input.data('record-id') || 0
      }).done(function(resp){
        if(resp && resp.success && resp.data && resp.data.exists){
          setMessage(resp.data.message || 'This serial number already added, add new one please', true);
        } else {
          setMessage('Serial number available', false);
        }
      }).fail(function(xhr, status){
        if(status !== 'abort'){ setMessage('', false); }
      });
    }

    input.on('input blur', function(){
      clearTimeout(timer);
      timer = setTimeout(checkNow, 300);
    });

    $(document).on('submit', 'form.him-form', function(e){
      if(input.attr('data-serial-exists') === '1'){
        e.preventDefault();
        e.stopImmediatePropagation();
        input.focus();
        setMessage('This serial number already added, add new one please', true);
        return false;
      }
    });
  }

  function initImageLightbox(){
    var overlay = $('<div class="him-lightbox-overlay" aria-hidden="true"><button type="button" class="him-lightbox-close" aria-label="Close">&times;</button><img class="him-lightbox-full" alt="Inventory image"></div>');
    $('body').append(overlay);
    $(document).on('click', '.him-lightbox-img', function(e){
      e.preventDefault();
      var full = $(this).data('full') || $(this).attr('src');
      overlay.find('img').attr('src', full);
      overlay.addClass('is-open').attr('aria-hidden', 'false');
    });
    overlay.on('click', function(e){
      if($(e.target).is('.him-lightbox-overlay, .him-lightbox-close')){
        overlay.removeClass('is-open').attr('aria-hidden', 'true');
        overlay.find('img').attr('src', '');
      }
    });
    $(document).on('keyup', function(e){
      if(e.key === 'Escape'){ overlay.removeClass('is-open').attr('aria-hidden', 'true'); }
    });
  }

  $(document).ready(function(){
    $('.him-qr-card, .him-inline-qr-card, .him-mini-qr-card').each(function(){
      var card = $(this); drawQrToCanvas(card.find('canvas')[0], card.data('payload'), card.data('serial'), card.data('name'), card.data('hospital'), card.data('qrid'));
    });
    $('.him-download-selected-qr').on('click', function(e){
      e.preventDefault(); var cards = selectedQrCards();
      if(!cards.length){ alert('Please select QR code first.'); return; }
      if(cards.length === 1) downloadCanvas(cards.eq(0)); else downloadZip(cards);
    });
    $('.him-download-all-qr').on('click', function(e){ e.preventDefault(); var cards = $('.him-qr-card'); if(cards.length) downloadZip(cards); });

    $('.him-check-all').on('change', function(){ $('.him-row-check').prop('checked', this.checked); });

    $(document).on('click', '.him-view-link', function(e){
      e.preventDefault();
      var url = $(this).attr('href');
      if(url){ window.open(url, '_blank', 'noopener'); }
    });
    $('.him-view-btn').on('click', function(){
      var detail = $('#him-detail-' + $(this).data('id'));
      detail.slideToggle(160);
      $(this).toggleClass('active');
    });
    $('.him-download-inline-qr').on('click', function(e){
      e.preventDefault();
      downloadCanvas($(this).closest('.him-inline-qr-card'));
    });
    $('.him-export-selected').on('click', function(e){
      e.preventDefault(); var ids = $('.him-row-check:checked').map(function(){return this.value;}).get().join(',');
      window.location.href = $(this).data('base') + '&ids=' + encodeURIComponent(ids);
    });

    $(document).on('submit', 'form.him-form[data-him-new-inventory="1"]', function(e){
      var form = $(this);
      if(form.attr('data-him-confirmed') === '1' || form.data('him-confirmed') === true){
        return true;
      }

      if (this.checkValidity && !this.checkValidity()) {
        if (this.reportValidity) { this.reportValidity(); }
        return true;
      }

      e.preventDefault();
      e.stopImmediatePropagation();
      showAddConfirm(form);
      return false;
    });

    $(document).on('click', 'form.him-form[data-him-new-inventory="1"] input[type="submit"], form.him-form[data-him-new-inventory="1"] button[type="submit"]', function(e){
      var form = $(this).closest('form.him-form[data-him-new-inventory="1"]');
      if(!form.length || form.attr('data-him-confirmed') === '1' || form.data('him-confirmed') === true){
        return true;
      }
      var formEl = form[0];
      if (formEl.checkValidity && !formEl.checkValidity()) {
        if (formEl.reportValidity) { formEl.reportValidity(); }
        return true;
      }
      e.preventDefault();
      e.stopImmediatePropagation();
      showAddConfirm(form);
      return false;
    });

    initMakeOther();
    initFocalHospitalAutoFill();
    initUserSerialValidation();
    initImageLightbox();

    var frame;
    $('.him-upload-images').on('click', function(e){
      e.preventDefault();
      if(frame){ frame.open(); return; }
      frame = wp.media({title:'Select Inventory Images', button:{text:'Use Images'}, multiple:true});
      frame.on('select', function(){
        var ids = [], html='';
        frame.state().get('selection').each(function(att){
          var a = att.toJSON(); ids.push(a.id);
          var url = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
          html += '<img class="him-lightbox-img" src="'+url+'" data-full="'+a.url+'" />';
        });
        $('#him_image_ids').val(ids.join(',')); $('.him-image-preview').html(html);
      });
      frame.open();
    });
    $('.him-clear-images').on('click', function(e){ e.preventDefault(); $('#him_image_ids').val(''); $('.him-image-preview').empty(); });
  });
})(jQuery);
