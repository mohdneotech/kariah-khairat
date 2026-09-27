(function () {
  var N = function (v) { return parseFloat(v) || 0; };
  var rm = function (n) { return 'RM ' + (Math.round(n * 100) / 100).toFixed(2); };
  document.querySelectorAll('[data-pk-form]').forEach(function (form) {
    var type = form.getAttribute('data-pk-form');
    var bayar = form.querySelector('[data-pk-bayar]');
    var touched = bayar && bayar.value !== '';
    if (bayar) bayar.addEventListener('input', function () { touched = true; });

    function nextIndex(box) { var n = parseInt(box.getAttribute('data-pk-next') || box.children.length, 10); box.setAttribute('data-pk-next', n + 1); return n; }
    form.querySelectorAll('[data-pk-add]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-pk-add');
        var box = form.querySelector('[data-pk-rows="' + key + '"]');
        var tpl = form.querySelector('[data-pk-tpl="' + key + '"]');
        var max = parseInt(box.getAttribute('data-pk-max') || '20', 10);
        if (box.children.length >= max) return;
        var html = tpl.innerHTML.replace(/__i__/g, 'n' + nextIndex(box));
        box.insertAdjacentHTML('beforeend', html);
        calc();
      });
    });
    form.addEventListener('click', function (e) {
      var del = e.target.closest('[data-pk-del]');
      if (!del) return;
      var row = del.closest('.pk-row'); var box = row.parentNode;
      if (box.children.length > 1) row.remove(); else row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
      calc();
    });

    function total() {
      if (type === 'khairat') {
        var baru = (form.querySelector('[name="jenis_mohon"]:checked') || {}).value === 'baru';
        var tg = baru ? 0 : form.querySelectorAll('[name="tunggakan[]"]:checked').length;
        var tw = form.querySelector('.pk-tunggakan'); if (tw) tw.style.display = baru ? 'none' : '';
        return (baru ? N(PK.daftar) : 0) + N(PK.tahunan) * (1 + tg);
      }
      var b = 0;
      form.querySelectorAll('[data-pk-rows="ps"] .pk-row').forEach(function (r) {
        var n = r.querySelector('input'); var s = r.querySelector('[data-pk-bahagian]');
        if (n && n.value.trim() !== '') b += parseInt(s.value || '1', 10);
      });
      var bh = form.querySelector('[data-pk-bah]'); if (bh) bh.textContent = b;
      return b * N(PK.harga);
    }
    function calc() {
      var t = total();
      var ans = (form.querySelector('[name="cara"]:checked') || {}).value === 'ansuran';
      form.querySelectorAll('.pk-ansuran-only').forEach(function (el) { el.style.display = ans ? '' : 'none'; });
      var bil = parseInt((form.querySelector('[data-pk-bil]') || {}).value || '1', 10);
      var monthly = ans ? Math.ceil(t / bil * 100) / 100 : t;
      var te = form.querySelector('[data-pk-total]'); if (te) te.textContent = rm(t);
      var se = form.querySelector('[data-pk-sebulan]'); if (se) se.textContent = rm(monthly);
      if (bayar && !touched) bayar.value = monthly.toFixed(2);
    }
    form.addEventListener('change', calc);
    form.addEventListener('input', function (e) { if (e.target.matches('[data-pk-rows] input')) calc(); });
    calc();
  });
})();
(function () {
  // Portal tabs. Uses Bootstrap's API when the theme ships it, otherwise a tiny built-in switcher.
  var btns = document.querySelectorAll('.pk-ui [data-bs-toggle="tab"], .pk-ui [data-bs-toggle="pill"]');
  if (!btns.length) return;
  function show(btn) {
    if (window.bootstrap && window.bootstrap.Tab) { window.bootstrap.Tab.getOrCreateInstance(btn).show(); return; }
    var nav = btn.closest('.nav'), target = document.querySelector(btn.getAttribute('data-bs-target'));
    if (!target) return;
    nav.querySelectorAll('.nav-link').forEach(function (b) { b.classList.remove('active'); b.setAttribute('aria-selected', 'false'); });
    btn.classList.add('active'); btn.setAttribute('aria-selected', 'true');
    Array.prototype.forEach.call(target.parentNode.children, function (p) { if (p.classList.contains('tab-pane')) p.classList.remove('active', 'show'); });
    target.classList.add('active', 'show');
  }
  btns.forEach(function (b) {
    b.setAttribute('role', 'tab');
    b.addEventListener('click', function (e) { if (window.bootstrap && window.bootstrap.Tab) return; e.preventDefault(); show(b); });
  });
  // re-open the tab named in the URL hash after a portal save
  var h = location.hash && location.hash.slice(1);
  if (h && /^[a-z0-9_-]+$/i.test(h)) {
    var btn = document.querySelector('.pk-ui [data-bs-target="#' + h + '"]');
    if (btn) show(btn);
  }
})();
