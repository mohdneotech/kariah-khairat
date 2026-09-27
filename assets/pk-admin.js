(function () {
  // repeater rows
  document.querySelectorAll('[data-pk-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var key = btn.getAttribute('data-pk-add');
      var scope = btn.closest('form') || document;
      var box = scope.querySelector('[data-pk-rows="' + key + '"]');
      var tpl = scope.querySelector('[data-pk-tpl="' + key + '"]');
      var n = parseInt(box.getAttribute('data-next') || box.children.length, 10);
      box.setAttribute('data-next', n + 1);
      box.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, 'n' + n));
    });
  });
  document.addEventListener('click', function (e) {
    var d = e.target.closest('[data-pk-del]'); if (!d) return;
    var row = d.closest('.pk-row'); if (!row) return;
    if (row.parentNode.children.length > 1) row.remove(); else row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
  });
  // show instalment fields only for "ansuran"
  document.querySelectorAll('[data-pk-cara]').forEach(function (sel) {
    var f = sel.closest('form');
    var sync = function () { f.querySelectorAll('.pk-ans').forEach(function (el) { el.style.display = sel.value === 'ansuran' ? '' : 'none'; }); };
    sel.addEventListener('change', sync); sync();
  });
  // plan presets
  document.querySelectorAll('[data-preset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = JSON.parse(b.getAttribute('data-preset')), f = b.closest('form');
      Object.keys(p).forEach(function (k) { var el = f.querySelector('[name="' + k + '"]'); if (el) el.value = p[k]; });
    });
  });
  // media picker (Tetapan → QR image)
  document.querySelectorAll('[data-pk-media]').forEach(function (b) {
    var frame;
    b.addEventListener('click', function () {
      if (!window.wp || !wp.media) return;
      var input = document.querySelector(b.getAttribute('data-pk-media'));
      frame = frame || wp.media({ title: 'Pilih imej QR', button: { text: 'Guna imej ini' }, library: { type: 'image' }, multiple: false });
      frame.off('select').on('select', function () { input.value = frame.state().get('selection').first().toJSON().url; });
      frame.open();
    });
  });
  // check all
  document.querySelectorAll('[data-pk-checkall]').forEach(function (c) {
    c.addEventListener('change', function () { c.closest('table').querySelectorAll('tbody input[type=checkbox]').forEach(function (x) { x.checked = c.checked; }); });
  });

  // ---------------------------------------------------------------- dashboard charts
  var dataEl = document.getElementById('pk-dash-data');
  if (!dataEl) return;
  var D = JSON.parse(dataEl.textContent);
  function go() {
    if (!window.Chart) return setTimeout(go, 100);
    var C = window.Chart;
    C.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    C.defaults.font.size = 12;
    C.defaults.color = '#52514e';
    C.defaults.plugins.legend.labels.boxWidth = 12;
    C.defaults.plugins.legend.labels.boxHeight = 12;
    C.defaults.plugins.tooltip.backgroundColor = '#1c1a1b';
    C.defaults.elements.bar.borderRadius = 4;
    C.defaults.elements.bar.borderSkipped = 'start';
    C.defaults.maintainAspectRatio = false;
    // palette: brand green for single series; validated categorical order for multi-series
    var G = '#527c3a', S = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
    var STATUS = { aktif: '#3f8f2e', menunggu: '#e0a100', tidak_aktif: '#9aa39a', meninggal: '#6b6f69', ditolak: '#c94040' };
    var grid = { color: 'rgba(0,0,0,.06)', drawTicks: false }, noGrid = { display: false };
    var rm = function (v) { return 'RM ' + Number(v).toLocaleString('ms-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var intTicks = { precision: 0, padding: 6 };
    function mk(id, cfg) {
      var el = document.getElementById(id); if (!el) return;
      el.parentNode.style.height = (el.getAttribute('height') || 220) + 'px';
      new C(el, cfg);
      addTable(el, cfg);
    }
    function e(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function addTable(el, cfg) {
      var d = document.createElement('details'); d.className = 'pk-tbl';
      var h = '<summary>Lihat jadual</summary><table class="widefat striped"><thead><tr><th></th>';
      cfg.data.datasets.forEach(function (s) { h += '<th>' + e(s.label || 'Bil.') + '</th>'; });
      h += '</tr></thead><tbody>';
      cfg.data.labels.forEach(function (l, i) { h += '<tr><td>' + e(l) + '</td>'; cfg.data.datasets.forEach(function (s) { h += '<td>' + e(s.data[i]) + '</td>'; }); h += '</tr>'; });
      d.innerHTML = h + '</tbody></table>';
      el.parentNode.parentNode.appendChild(d);
    }
    var stackedBar = function (labels, sets, money) {
      return { type: 'bar', data: { labels: labels, datasets: sets.map(function (s, i) { return { label: s[0], data: s[1], backgroundColor: S[i], borderColor: '#fff', borderWidth: { top: 2 }, maxBarThickness: 28 }; }) },
        options: { interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'top', align: 'end' }, tooltip: money ? { callbacks: { label: function (c) { return c.dataset.label + ': ' + rm(c.parsed.y); } } } : {} },
          scales: { x: { stacked: true, grid: noGrid }, y: { stacked: true, grid: grid, border: { display: false }, ticks: money ? { callback: function (v) { return 'RM' + v; } } : intTicks } } } };
    };
    mk('pkc-reg', stackedBar(D.labels, [['Dalam talian', D.reg.online], ['Manual / import', D.reg.manual]]));
    mk('pkc-col', stackedBar(D.labels, [['Khairat', D.col.khairat], ['Korban/Aqiqah', D.col.korban]], true));
    mk('pkc-age', stackedBar(D.age.labels, [['Lelaki', D.age.L], ['Perempuan', D.age.P]]));
    mk('pkc-status', { type: 'doughnut', data: { labels: D.status.map(function (s) { return s.label; }), datasets: [{ label: 'Ahli', data: D.status.map(function (s) { return s.n; }), backgroundColor: D.status.map(function (s) { return STATUS[s.key]; }), borderColor: '#fff', borderWidth: 2 }] },
      options: { cutout: '62%', plugins: { legend: { position: 'bottom' } } } });
    mk('pkc-mode', { type: 'bar', data: { labels: ['Khairat', 'Korban/Aqiqah'], datasets: [
        { label: 'Sekaligus', data: [D.mode.khairat.sekaligus, D.mode.korban.sekaligus], backgroundColor: S[0], borderColor: '#fff', borderWidth: { right: 2 } },
        { label: 'Ansuran', data: [D.mode.khairat.ansuran, D.mode.korban.ansuran], backgroundColor: S[1], borderColor: '#fff', borderWidth: { right: 2 } }] },
      options: { indexAxis: 'y', interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'top', align: 'end' } }, scales: { x: { stacked: true, grid: grid, border: { display: false }, ticks: intTicks }, y: { stacked: true, grid: noGrid } } } });
    var hbar = function (rows, label) {
      return { type: 'bar', data: { labels: rows.map(function (r) { return r.k; }), datasets: [{ label: label, data: rows.map(function (r) { return +r.n; }), backgroundColor: G, maxBarThickness: 22 }] },
        options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { grid: grid, border: { display: false }, ticks: intTicks }, y: { grid: noGrid } } } };
    };
    mk('pkc-kaw', hbar(D.kaw, 'Ahli'));
    mk('pkc-sek', hbar(D.sek, 'Ahli'));
    mk('pkc-hub', hbar(D.hub, 'Tanggungan'));
    mk('pkc-hh', { type: 'bar', data: { labels: D.hh.labels.map(function (l) { return l + ' orang'; }), datasets: [{ label: 'Isi rumah', data: D.hh.n, backgroundColor: G, maxBarThickness: 28 }] },
      options: { plugins: { legend: { display: false } }, scales: { x: { grid: noGrid }, y: { grid: grid, border: { display: false }, ticks: intTicks } } } });
  }
  go();
})();
(function () {
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-pk-view]'); if (!a || e.ctrlKey || e.metaKey) return;
    e.preventDefault();
    var m = document.createElement('div'); m.className = 'pk-modal';
    var href = a.href; if (!/^https?:\/\//i.test(href)) return;
    var media = document.createElement(a.getAttribute('data-pk-view') === 'pdf' ? 'iframe' : 'img');
    media.src = href; if (media.tagName === 'IMG') media.alt = '';
    var x = document.createElement('button'); x.type = 'button'; x.className = 'pk-x'; x.setAttribute('aria-label', 'Tutup'); x.textContent = '\u00d7';
    var o = document.createElement('a'); o.className = 'pk-open'; o.href = href; o.target = '_blank'; o.rel = 'noopener'; o.textContent = 'Buka di tab baharu \u2197';
    m.appendChild(media); m.appendChild(x); m.appendChild(o);
    m.addEventListener('click', function (ev) { if (ev.target === m || ev.target.classList.contains('pk-x')) m.remove(); });
    document.addEventListener('keydown', function esc(ev) { if (ev.key === 'Escape') { m.remove(); document.removeEventListener('keydown', esc); } });
    document.body.appendChild(m);
  });
})();
