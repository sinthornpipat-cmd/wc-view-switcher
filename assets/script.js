(function ($) {
  'use strict';

  /* ---------- View switcher (Grid / List) ---------- */
  var buttons = document.querySelectorAll('.wcvs-btn');

  function setView(view) {
    document.body.classList.remove('wcvs-view-grid', 'wcvs-view-list');
    document.body.classList.add('wcvs-view-' + view);
    buttons.forEach(function (b) {
      var on = b.dataset.view === view;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    document.cookie = 'wcvs_view=' + view + ';path=/;max-age=31536000;SameSite=Lax';
    try { localStorage.setItem('wcvs_view', view); } catch (e) {}
  }

  buttons.forEach(function (b) {
    b.addEventListener('click', function () { setView(b.dataset.view); });
  });

  /* ---------- Quick View popup ---------- */
  var $modal, $body, lastTrigger, xhr;

  function build() {
    if ($modal) return;
    $modal = $(
      '<div class="wcvs-modal" aria-hidden="true">' +
        '<div class="wcvs-modal-backdrop" data-close></div>' +
        '<div class="wcvs-modal-dialog" role="dialog" aria-modal="true" tabindex="-1">' +
          '<button type="button" class="wcvs-modal-close" data-close aria-label="Close">' +
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>' +
          '</button>' +
          '<div class="wcvs-modal-body"></div>' +
        '</div>' +
      '</div>'
    ).appendTo(document.body);
    $body = $modal.find('.wcvs-modal-body');

    $modal.on('click', '[data-close]', closeModal);

    // เปลี่ยนรูปจาก thumbnail
    $modal.on('click', '.wcvs-qv-thumb', function () {
      var $t = $(this);
      $modal.find('.wcvs-qv-thumb').removeClass('is-active');
      $t.addClass('is-active');
      $modal.find('.wcvs-qv-main-img').attr('src', $t.data('full'));
    });

    // จำนวนสินค้า
    function setQty($wrap, val) {
      var $in = $wrap.find('.wcvs-qty-input');
      var min = parseInt($in.attr('min'), 10) || 1;
      var max = parseInt($in.attr('max'), 10) || Infinity;
      val = Math.max(min, Math.min(max, parseInt(val, 10) || min));
      $in.val(val);
      var $add = $wrap.siblings('.wcvs-qv-add');
      $add.attr('data-quantity', val).data('quantity', val);
      try {
        var u = new URL($add.attr('href'), window.location.href);
        u.searchParams.set('quantity', val);
        $add.attr('href', u.toString());
      } catch (e) {}
    }
    $modal.on('click', '.wcvs-qty-minus', function () {
      var $w = $(this).closest('.wcvs-qty');
      setQty($w, (parseInt($w.find('input').val(), 10) || 1) - 1);
    });
    $modal.on('click', '.wcvs-qty-plus', function () {
      var $w = $(this).closest('.wcvs-qty');
      setQty($w, (parseInt($w.find('input').val(), 10) || 1) + 1);
    });
    $modal.on('change input', '.wcvs-qty-input', function () {
      setQty($(this).closest('.wcvs-qty'), $(this).val());
    });

    // Keyboard: Esc ปิด, Tab วนอยู่ใน popup
    $(document).on('keydown', function (e) {
      if (!$modal.hasClass('is-open')) return;
      if (e.key === 'Escape') { closeModal(); return; }
      if (e.key === 'Tab') {
        var $f = $modal.find('a[href],button:not([disabled]),input:not([disabled]),select,textarea').filter(':visible');
        if (!$f.length) return;
        var first = $f[0], last = $f[$f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });
  }

  function openModal(id, trigger) {
    build();
    lastTrigger = trigger;
    $body.html('<div class="wcvs-loading"><span class="wcvs-spinner"></span></div>');
    $modal.addClass('is-open').attr('aria-hidden', 'false');
    document.documentElement.classList.add('wcvs-lock');
    $modal.find('.wcvs-modal-dialog').trigger('focus');

    if (xhr) xhr.abort();
    xhr = $.ajax({
      url: WCVS.ajax_url,
      data: { action: 'wcvs_quick_view', product_id: id },
      dataType: 'json'
    }).done(function (res) {
      if (res && res.success) {
        $body.html(res.data.html);
        // เปิดใช้งานฟอร์มสินค้าแบบมีตัวเลือก
        if ($.fn.wc_variation_form) {
          $body.find('.variations_form').each(function () { $(this).wc_variation_form(); });
        }
      } else {
        $body.html('<p class="wcvs-error">' + WCVS.error + '</p>');
      }
    }).fail(function (jq, status) {
      if (status !== 'abort') $body.html('<p class="wcvs-error">' + WCVS.error + '</p>');
    });
  }

  function closeModal() {
    if (!$modal) return;
    if (xhr) xhr.abort();
    $modal.removeClass('is-open').attr('aria-hidden', 'true');
    document.documentElement.classList.remove('wcvs-lock');
    if (lastTrigger) { try { lastTrigger.focus(); } catch (e) {} }
  }

  $(document).on('click', '.wcvs-qv-btn', function (e) {
    e.preventDefault();
    openModal($(this).data('product-id'), this);
  });

})(jQuery);

/* ==========================================================
   Filter panel (v2.0.0)
   ========================================================== */
(function ($) {
  'use strict';

  var $body = $(document.body);
  var mq = window.matchMedia ? window.matchMedia('(max-width: 960px)') : { matches: false };

  function isMobile() { return mq.matches; }
  function setCookie(v) {
    document.cookie = 'wcvs_filter=' + v + ';path=/;max-age=31536000;SameSite=Lax';
  }
  function getCookie() {
    var m = document.cookie.match(/(?:^|; )wcvs_filter=([^;]*)/);
    return m ? m[1] : null;
  }

  function sync() {
    var open = isMobile() ? $body.hasClass('wcvs-drawer-open') : $body.hasClass('wcvs-filter-open');
    $('.wcvs-filter-toggle').attr('aria-expanded', open ? 'true' : 'false');
  }

  function setDesktop(open) {
    $body.toggleClass('wcvs-filter-open', open).toggleClass('wcvs-filter-closed', !open);
    setCookie(open ? 'open' : 'closed');
    sync();
  }
  function setDrawer(open) {
    $body.toggleClass('wcvs-drawer-open', open);
    sync();
  }

  // หน้าถูกแคช: ใช้ค่าล่าสุดจาก cookie บนจอใหญ่
  if ($('#wcvs-filter').length && !isMobile()) {
    var c = getCookie();
    if (c === 'open' || c === 'closed') {
      $body.toggleClass('wcvs-filter-open', c === 'open').toggleClass('wcvs-filter-closed', c === 'closed');
    }
  }
  sync();

  $(document).on('click', '.wcvs-filter-toggle', function () {
    if (isMobile()) setDrawer(!$body.hasClass('wcvs-drawer-open'));
    else setDesktop(!$body.hasClass('wcvs-filter-open'));
  });
  $(document).on('click', '.wcvs-filter-close, .wcvs-filter-backdrop', function () {
    setDrawer(false);
  });
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && $body.hasClass('wcvs-drawer-open')) setDrawer(false);
  });
  $(window).on('resize', function () {
    if (!isMobile() && $body.hasClass('wcvs-drawer-open')) setDrawer(false);
    sync();
  });

  // Price slider
  $('.wcvs-price').each(function () {
    var $w = $(this);
    var lo = parseFloat($w.data('min')), hi = parseFloat($w.data('max'));
    var $rmin = $w.find('.wcvs-range-min'), $rmax = $w.find('.wcvs-range-max');
    var $nmin = $w.find('.wcvs-in-min'), $nmax = $w.find('.wcvs-in-max');
    var $fill = $w.find('.wcvs-range-fill');

    function paint() {
      var a = parseFloat($rmin.val()), b = parseFloat($rmax.val());
      var span = (hi - lo) || 1;
      $fill.css({ left: ((a - lo) / span * 100) + '%', right: (100 - (b - lo) / span * 100) + '%' });
    }
    $rmin.on('input', function () {
      if (parseFloat($rmin.val()) > parseFloat($rmax.val())) $rmin.val($rmax.val());
      $nmin.val($rmin.val()); paint();
    });
    $rmax.on('input', function () {
      if (parseFloat($rmax.val()) < parseFloat($rmin.val())) $rmax.val($rmin.val());
      $nmax.val($rmax.val()); paint();
    });
    $nmin.on('change', function () {
      var v = Math.max(lo, Math.min(parseFloat($nmin.val()) || lo, parseFloat($nmax.val()) || hi));
      $nmin.val(v); $rmin.val(v); paint();
    });
    $nmax.on('change', function () {
      var v = Math.min(hi, Math.max(parseFloat($nmax.val()) || hi, parseFloat($nmin.val()) || lo));
      $nmax.val(v); $rmax.val(v); paint();
    });
    paint();

    // ไม่ส่งค่าที่เท่ากับขอบเขตเดิม เพื่อให้ URL สะอาดและไม่นับเป็นตัวกรอง
    $w.closest('form').on('submit', function () {
      if (!$nmin.val() || parseFloat($nmin.val()) <= lo) $nmin.prop('disabled', true);
      if (!$nmax.val() || parseFloat($nmax.val()) >= hi) $nmax.prop('disabled', true);
    });
  });

  // กลับมาด้วยปุ่ม Back แล้วช่องราคายังใช้งานได้
  window.addEventListener('pageshow', function () {
    $('#wcvs-filter .wcvs-in-min, #wcvs-filter .wcvs-in-max').prop('disabled', false);
  });
})(jQuery);
