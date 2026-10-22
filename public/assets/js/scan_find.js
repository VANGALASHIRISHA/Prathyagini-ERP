// Scan & Find page: looks up a scanned/typed code via api/scan_find.php and shows where it is stored.
(function () {
  'use strict';
  var root = document.getElementById('scanFind');
  if (!root) return;

  var apiUrl = root.getAttribute('data-api-url');
  var pickUrl = root.getAttribute('data-pick-url');
  var form = document.getElementById('sfForm');
  var input = document.getElementById('sfCode');
  var camBtn = document.getElementById('sfCamBtn');
  var statusEl = document.getElementById('sfStatus');
  var resultEl = document.getElementById('sfResult');
  var ctrl = null, seq = 0;

  function esc(v) {
    return String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function qty(n) { return Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 }); }
  function money(n) { return '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function arrowPath(path) { return esc(path).split(' &gt; ').join(' → '); }

  // Short beep: high for success, low and longer for errors.
  var audioCtx = null;
  function beep(ok) {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      var o = audioCtx.createOscillator(), g = audioCtx.createGain();
      o.frequency.value = ok ? 1200 : 280; g.gain.value = 0.06;
      o.connect(g); g.connect(audioCtx.destination);
      o.start(); o.stop(audioCtx.currentTime + (ok ? 0.08 : 0.35));
    } catch (e) { /* audio unavailable */ }
  }

  function setStatus(text, kind) {
    statusEl.className = 'sf-status' + (kind ? ' sf-' + kind : '');
    statusEl.textContent = text || '';
  }

  function readyForNextScan() {
    input.focus();
    input.select();
  }

  // ---------- Rendering ----------
  function photo(p) {
    return '<div class="sf-photo">' + (p.image ? '<img src="' + esc(p.image) + '" alt="' + esc(p.name) + '">' : '<span class="sf-noimg">No photo</span>') + '</div>';
  }

  function productHeader(p) {
    var attrs = [['SKU', p.sku], ['Size', p.size], ['Colour', p.color]]
      .filter(function (a) { return a[1]; })
      .map(function (a) { return '<span class="badge">' + a[0] + ': <b>' + esc(a[1]) + '</b></span>'; }).join('');
    var prices = p.retail_price !== null && p.retail_price !== undefined
      ? '<div class="sf-prices">Selling <b>' + money(p.retail_price) + '</b> <span class="muted">MRP ' + money(p.mrp) + '</span></div>' : '';
    return '<div class="panel sf-product">' + photo(p) + '<div><h2>' + esc(p.name) + '</h2>' +
      '<div class="muted">Barcode ' + esc(p.barcode) + '</div><div class="sf-attrs">' + attrs + '</div>' + prices + '</div></div>';
  }

  function qtyText(l) {
    var s = 'Available <b>' + qty(l.sellable_qty) + '</b>';
    if (l.qty !== null && l.qty !== undefined) s += ' · In rack ' + qty(l.qty) + ' · Reserved ' + qty(l.reserved_qty);
    return s;
  }

  function whereBlock(label, loc, extra) {
    return '<div class="sf-where"><div class="sf-label">' + esc(label) + '</div>' +
      '<div class="sf-path">' + arrowPath(loc.path) + '</div>' +
      '<div class="sf-code">' + esc(loc.code) + '</div>' + (extra ? '<div class="sf-qty">' + extra + '</div>' : '') + '</div>';
  }

  function renderProduct(d) {
    var html = productHeader(d.product);
    var scan = d.scan || {};
    var locs = d.locations || [];

    if (scan.type === 'serial') {
      if (scan.in_scope && scan.location) {
        html += whereBlock('This garment is at', scan.location, 'Status <b>' + esc(scan.status) + '</b> · Batch ' + esc(scan.batch_no));
      } else {
        html += '<div class="notice">This garment is not in your assigned locations.</div>';
      }
    } else if (locs.length) {
      html += whereBlock(locs.length > 1 ? 'Main location (most stock)' : 'Location', locs[0], qtyText(locs[0]));
    }
    if (scan.type === 'batch') html += '<div class="notice">Batch <b>' + esc(scan.batch_no) + '</b> scanned.</div>';

    html += '<div class="panel sf-section"><h3>Quantity in each location</h3>';
    if (locs.length) {
      html += '<ul class="sf-list">' + locs.map(function (l) {
        var hit = scan.location && scan.location.id === l.id ? ' class="sf-hit"' : '';
        var batch = l.batch_sellable_qty !== undefined ? '<div class="muted">This batch: ' + qty(l.batch_sellable_qty) + '</div>' : '';
        return '<li' + hit + '><div><div class="sf-loc-path">' + arrowPath(l.path) + '</div><div class="muted">' + esc(l.code) + '</div>' + batch + '</div>' +
          '<div class="sf-num"><b>' + qty(l.sellable_qty) + '</b><span class="muted">' + (l.qty !== null ? 'of ' + qty(l.qty) + ' · ' + qty(l.reserved_qty) + ' reserved' : 'available') + '</span></div></li>';
      }).join('') + '</ul>';
    } else {
      html += '<div class="muted">No stock of this product in your locations.</div>';
    }
    html += '</div>';

    if (d.orders_visible) {
      var orders = d.pending_orders || [];
      html += '<div class="panel sf-section"><h3>Waiting online orders (' + orders.length + ')</h3>';
      html += orders.length ? '<ul class="sf-list">' + orders.map(function (o) {
        return '<li><div><b>' + esc(o.order_no) + '</b> <span class="badge">' + esc(o.status) + '</span>' +
          '<div class="muted">' + esc(o.customer_name || '') + ' · Pick from ' + esc(o.location_code) + '</div>' +
          '<div>Picked ' + qty(o.picked_qty) + ' / ' + qty(o.qty) + '</div></div>' +
          '<a class="btn" href="' + esc(pickUrl) + '?id=' + encodeURIComponent(o.order_id) + '">Pick</a></li>';
      }).join('') + '</ul>' : '<div class="muted">No online orders are waiting for this product.</div>';
      html += '</div>';
    }
    resultEl.innerHTML = html;
  }

  function renderLocation(d) {
    var l = d.location, items = d.items || [];
    var total = items.reduce(function (s, i) { return s + Number(i.sellable_qty || 0); }, 0);
    var html = whereBlock('Rack / bin scanned', l, items.length + ' product' + (items.length === 1 ? '' : 's') + ' · ' + qty(total) + ' available' + (l.active ? '' : ' · <b>Inactive location</b>'));
    html += '<div class="panel sf-section"><h3>Stored here</h3>';
    html += items.length ? '<ul class="sf-list">' + items.map(function (i) {
      var p = i.product;
      var variant = [p.color, p.size].filter(Boolean).map(esc).join(' / ');
      return '<li><div class="sf-item">' + photo(p).replace('sf-photo', 'sf-photo sf-photo-sm') +
        '<div><b>' + esc(p.name) + '</b><div class="muted">' + esc(p.sku) + (variant ? ' · ' + variant : '') + '</div></div></div>' +
        '<div class="sf-num"><b>' + qty(i.sellable_qty) + '</b><span class="muted">' + (i.qty !== null ? 'of ' + qty(i.qty) : 'available') + '</span></div></li>';
    }).join('') + '</ul>' : '<div class="muted">Nothing is stored in this location.</div>';
    html += '</div>';
    resultEl.innerHTML = html;
  }

  // ---------- Lookup ----------
  async function lookup(code) {
    code = String(code || '').trim();
    if (!code) { setStatus('Scan or type a code.', 'err'); beep(false); readyForNextScan(); return; }
    input.value = code;
    if (ctrl) ctrl.abort();
    ctrl = new AbortController();
    var mySeq = ++seq;
    setStatus('Looking up ' + code + '…', 'busy');
    try {
      var res = await fetch(apiUrl + '?code=' + encodeURIComponent(code), {
        credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: ctrl.signal,
      });
      var type = res.headers.get('content-type') || '';
      if (res.redirected || res.status === 401 || type.indexOf('application/json') === -1) {
        throw new Error('Your session has expired. Refresh the page and log in again.');
      }
      var d = await res.json();
      if (mySeq !== seq) return;
      if (!res.ok || !d.ok) {
        resultEl.innerHTML = '';
        setStatus((d.error === 'Not found' ? 'Product not found' : d.error || 'Lookup failed') + ': ' + code, 'err');
        beep(false);
        return;
      }
      if (d.kind === 'location') renderLocation(d); else renderProduct(d);
      setStatus('Scanned successfully: ' + code, 'ok');
      beep(true);
    } catch (e) {
      if (e.name === 'AbortError') return;
      resultEl.innerHTML = '';
      setStatus(e.message === 'Failed to fetch' ? 'Network error. Check your connection and try again.' : e.message, 'err');
      beep(false);
    } finally {
      if (mySeq === seq) readyForNextScan();
    }
  }

  form.addEventListener('submit', function (ev) { ev.preventDefault(); lookup(input.value); });

  camBtn.addEventListener('click', function () {
    if (!window.CameraScan) { setStatus('Camera scanner failed to load. Type the code instead.', 'err'); return; }
    window.CameraScan.open({ title: 'Scan product or rack label', onDetect: lookup });
  });

  // Keep USB/Bluetooth scanner input going to the code box: printable keys typed elsewhere are redirected.
  document.addEventListener('keydown', function (ev) {
    var t = ev.target;
    if (t === input || ev.ctrlKey || ev.metaKey || ev.altKey || ev.key.length !== 1) return;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT|BUTTON|A)$/.test(t.tagName))) return;
    if (document.querySelector('.cs-overlay:not([hidden])')) return;
    input.value = '';
    input.focus();
  });

  // Broken image paths fall back to the "No photo" placeholder.
  resultEl.addEventListener('error', function (ev) {
    var img = ev.target;
    if (img && img.tagName === 'IMG' && img.parentElement) img.parentElement.innerHTML = '<span class="sf-noimg">No photo</span>';
  }, true);

  var initial = root.getAttribute('data-initial-code');
  if (initial) lookup(initial); else input.focus();
})();
