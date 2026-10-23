(function () {
  'use strict';
  const root = document.getElementById('productFinder');
  if (!root) return;

  const cfg = {
    searchUrl: root.dataset.searchUrl,
    stockUrl: root.dataset.stockUrl,
    zxingUrl: root.dataset.zxingUrl,
    showPrices: root.dataset.showPrices === '1',
    showReserved: root.dataset.showReserved === '1',
  };
  const MIN_CHARS = 2;
  const DEBOUNCE_MS = 250;
  const SCANNER_KEY_GAP_MS = 50;
  const SCANNER_MIN_LEN = 3;

  const $ = (id) => document.getElementById(id);
  const form = $('pfForm'), input = $('pfQuery'), statusEl = $('pfStatus');
  const resultsEl = $('pfResults'), detailEl = $('pfDetail');
  const scanBtn = $('pfScanBtn'), scanPanel = $('pfScanner'), scanClose = $('pfScanClose');
  const video = $('pfVideo'), scanMsg = $('pfScanMsg');

  let debounceTimer = null, searchCtrl = null, detailCtrl = null, searchSeq = 0;
  let selectedId = null, lastLookup = null;

  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const qty = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });
  const money = (n) => '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const STATUS = { IN: ['In Stock', 'pf-in'], LOW: ['Low Stock', 'pf-low'], OUT: ['Out of Stock', 'pf-out'] };
  const statusBadge = (s) => { const [label, cls] = STATUS[s] || STATUS.OUT; return `<span class="pf-badge ${cls}">${label}</span>`; };
  const MATCH = { name: 'Name', sku: 'SKU', product_barcode: 'Product barcode', batch_barcode: 'Batch barcode', serial_barcode: 'Piece barcode', batch_or_serial: 'Batch / piece barcode' };
  const variant = (p) => [p.color, p.size].filter(Boolean).join(' / ');

  function setStatus(msg, type) {
    statusEl.className = 'pf-status' + (type === 'error' ? ' pf-error' : type === 'ok' ? ' pf-ok' : ' muted');
    statusEl.textContent = msg || '';
  }

  async function getJSON(url, signal) {
    let res;
    try {
      res = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal });
    } catch (e) {
      if (e.name === 'AbortError') throw e;
      throw new Error('Network error. Check your connection and try again.');
    }
    const type = res.headers.get('content-type') || '';
    if (res.redirected || !type.includes('application/json')) {
      if (res.redirected || res.status === 401) throw new Error('Your session has expired. Please refresh the page and log in again.');
      const text = (await res.text().catch(() => '')).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
      throw new Error(text || `Request failed (HTTP ${res.status}).`);
    }
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || 'Request failed.');
    return data;
  }

  function updateUrl() {
    const u = new URL(window.location.href);
    const q = input.value.trim();
    q ? u.searchParams.set('q', q) : u.searchParams.delete('q');
    selectedId ? u.searchParams.set('id', selectedId) : u.searchParams.delete('id');
    history.replaceState(null, '', u.pathname + u.search);
  }

  // ---------- Search ----------
  function renderResults(results, q) {
    if (!results.length) {
      resultsEl.innerHTML = `<div class="pf-empty"><b>No products found</b><div class="muted">Nothing matches “${esc(q)}”. Check the spelling, try part of the SKU, or scan the barcode again.</div></div>`;
      return;
    }
    resultsEl.innerHTML = results.map((p) => `
      <button type="button" class="pf-card${p.id === selectedId ? ' selected' : ''}" data-id="${p.id}">
        <span class="pf-thumb">${p.image ? `<img src="${esc(p.image)}" alt="" loading="lazy">` : '<span class="pf-noimg">No photo</span>'}</span>
        <span class="pf-card-body">
          <span class="pf-card-title">${esc(p.name)}</span>
          <span class="muted">${esc(p.sku)}${variant(p) ? ' · ' + esc(variant(p)) : ''}${p.brand ? ' · ' + esc(p.brand) : ''}</span>
          <span class="pf-card-meta">${statusBadge(p.status)} <span class="pf-qty">Sellable <b>${qty(p.sellable)}</b></span>
            <span class="badge">${esc(MATCH[p.match] || p.match)}</span>${p.active ? '' : ' <span class="badge pf-inactive">Inactive</span>'}</span>
        </span>
      </button>`).join('');
  }

  async function runSearch(q, { exact = false } = {}) {
    clearTimeout(debounceTimer);
    if (searchCtrl) searchCtrl.abort();
    searchCtrl = new AbortController();
    const seq = ++searchSeq;
    resultsEl.setAttribute('aria-busy', 'true');
    setStatus(exact ? 'Looking up barcode…' : 'Searching…');
    root.classList.add('pf-loading');
    try {
      const data = await getJSON(`${cfg.searchUrl}?q=${encodeURIComponent(q)}${exact ? '&exact=1' : ''}`, searchCtrl.signal);
      if (seq !== searchSeq) return null;
      return data.results || [];
    } catch (e) {
      if (e.name === 'AbortError') return null;
      if (seq === searchSeq) {
        setStatus(e.message, 'error');
        resultsEl.innerHTML = `<div class="pf-empty pf-error-box"><b>Search failed</b><div>${esc(e.message)}</div></div>`;
      }
      return null;
    } finally {
      if (seq === searchSeq) { root.classList.remove('pf-loading'); resultsEl.removeAttribute('aria-busy'); }
    }
  }

  async function liveSearch() {
    const q = input.value.trim();
    updateUrl();
    if (q.length < MIN_CHARS) {
      if (searchCtrl) searchCtrl.abort();
      searchSeq++;
      root.classList.remove('pf-loading');
      setStatus(q.length ? `Type at least ${MIN_CHARS} characters.` : '');
      resultsEl.innerHTML = '<div class="pf-empty muted">Start typing at least 2 characters, or scan a barcode.</div>';
      return;
    }
    const results = await runSearch(q);
    if (results === null) return;
    renderResults(results, q);
    setStatus(results.length ? `${results.length} product${results.length === 1 ? '' : 's'} found${results.length >= 30 ? ' (showing first 30 — refine your search)' : ''}.` : 'No products found.');
  }

  // Exact barcode/code lookup used by Enter, keyboard-wedge scanners and the camera.
  async function lookupCode(code, source) {
    code = code.trim();
    if (!code) return;
    lastLookup = code;
    input.value = code;
    updateUrl();
    const results = await runSearch(code, { exact: true });
    if (results === null) return;
    const exact = results.filter((r) => r.exact);
    if (exact.length === 1) {
      renderResults(exact, code);
      setStatus(`${MATCH[exact[0].match] || 'Code'} matched: ${exact[0].name}`, 'ok');
      openDetail(exact[0].id, exact[0].scan || null);
      return;
    }
    if (exact.length > 1) {
      renderResults(exact, code);
      setStatus(`“${code}” matches ${exact.length} products. Select one.`, 'ok');
      return;
    }
    if (source === 'enter' && code.length >= MIN_CHARS) {
      const partial = await runSearch(code);
      if (partial === null) return;
      renderResults(partial, code);
      if (partial.length) { setStatus(`No exact barcode match. ${partial.length} similar product${partial.length === 1 ? '' : 's'} found.`); return; }
    } else {
      renderResults([], code);
    }
    setStatus(`Unknown barcode or code: “${code}”. No product matches it.`, 'error');
    if (source !== 'enter') beep(false);
  }

  // ---------- Detail ----------
  function scanNote(scan) {
    if (!scan) return '';
    if (scan.type === 'serial') {
      if (!scan.in_scope) return `<div class="notice">Scanned piece <b>${esc(scan.code)}</b> belongs to this product but is not in your assigned locations.</div>`;
      const cls = scan.status === 'AVAILABLE' ? 'success' : 'error';
      return `<div class="notice ${cls}">Scanned piece <b>${esc(scan.code)}</b> · Status <b>${esc(scan.status)}</b> · Batch ${esc(scan.batch_no)} · Location ${esc(scan.location_code)}</div>`;
    }
    if (scan.type === 'batch') return `<div class="notice">Scanned batch barcode <b>${esc(scan.code)}</b> (batch ${esc(scan.batch_no)}).</div>`;
    return '';
  }

  function locationText(l) {
    return [l.warehouse, l.floor && `Floor ${l.floor}`, l.section, l.rack && `Rack ${l.rack}`, l.shelf && `Shelf ${l.shelf}`, l.bin && `Bin ${l.bin}`].filter(Boolean).map(esc).join(' · ');
  }

  function renderDetail(d, scan) {
    const p = d.product, s = d.stock, R = cfg.showReserved;
    const attrs = [['Brand', p.brand], ['Category', p.category], ['Colour', p.color], ['Size', p.size], ['Fabric', p.fabric], ['Product barcode', p.product_barcode], ['Tracking', p.tracking === 'SERIAL' ? 'Unique piece barcodes' : 'Batch barcode'], ['Min stock', p.min_stock > 0 ? qty(p.min_stock) : null]]
      .filter(([, v]) => v !== null && v !== undefined && v !== '')
      .map(([k, v]) => `<div><dt>${k}</dt><dd>${esc(v)}</dd></div>`).join('');
    const prices = cfg.showPrices && 'mrp' in p ? `
      <div class="pf-prices">
        <div><span class="muted">MRP</span><b>${money(p.mrp)}</b></div>
        <div><span class="muted">Selling price</span><b>${money(p.selling_price)}</b></div>
        ${p.offer_price !== null ? `<div><span class="muted">Offer price</span><b>${money(p.offer_price)}</b></div>` : ''}
      </div>` : '';
    const metrics = `
      <div class="pf-metrics">
        ${R ? `<div class="card"><div class="muted">Total physical</div><div class="metric">${qty(s.physical)}</div></div>
        <div class="card"><div class="muted">Reserved</div><div class="metric">${qty(s.reserved)}</div></div>` : ''}
        <div class="card pf-metric-main"><div class="muted">Available / sellable</div><div class="metric">${qty(s.sellable)}</div></div>
        <div class="card"><div class="muted">Status</div><div class="pf-metric-status">${statusBadge(s.status)}</div></div>
      </div>`;
    const locRows = d.locations.map((l) => `<tr><td data-label="Location"><b>${esc(l.code)}</b><div class="muted">${esc(l.name)}</div></td><td data-label="Rack / Bin">${locationText(l) || '<span class="muted">—</span>'}</td>${R ? `<td data-label="Physical">${qty(l.physical)}</td><td data-label="Reserved">${qty(l.reserved)}</td>` : ''}<td data-label="Sellable" class="qty-good">${qty(l.sellable)}</td><td data-label="Status">${statusBadge(l.status)}</td></tr>`).join('');
    const locTable = d.locations.length
      ? `<div class="pf-table-wrap"><table class="pf-table"><thead><tr><th>Location</th><th>Rack / Bin</th>${R ? '<th>Physical</th><th>Reserved</th>' : ''}<th>Sellable</th><th>Status</th></tr></thead><tbody>${locRows}</tbody></table></div>`
      : `<div class="pf-empty muted">No stock in ${d.scope === 'all' ? 'any location' : 'your assigned locations'}.</div>`;
    const batchRows = d.batches.map((b) => `<tr><td data-label="Batch"><b>${esc(b.batch_no)}</b><div class="muted">${esc(b.batch_barcode)}</div></td><td data-label="Received">${esc(b.purchased_at)}</td><td data-label="Location">${esc(b.location_code)}</td>${R ? `<td data-label="Physical">${qty(b.physical)}</td><td data-label="Reserved">${qty(b.reserved)}</td>` : ''}<td data-label="Sellable" class="qty-good">${qty(b.sellable)}</td></tr>`).join('');
    const batchTable = d.batches.length ? `<h3>Batches</h3><div class="pf-table-wrap"><table class="pf-table"><thead><tr><th>Batch</th><th>Received</th><th>Location</th>${R ? '<th>Physical</th><th>Reserved</th>' : ''}<th>Sellable</th></tr></thead><tbody>${batchRows}</tbody></table></div>` : '';
    let serials = '';
    if (d.serials) {
      serials = `<details class="pf-serials"${d.serials.total && d.serials.total <= 20 ? ' open' : ''}><summary><b>Serial / piece barcodes</b> <span class="badge">${d.serials.total}</span>${d.serials.total > d.serials.shown ? ` <span class="muted">showing first ${d.serials.shown}</span>` : ''}</summary>`
        + (d.serials.items.length ? `<div class="pf-table-wrap"><table class="pf-table"><thead><tr><th>Piece barcode</th><th>Batch</th><th>Location</th><th>Status</th></tr></thead><tbody>${d.serials.items.map((x) => `<tr${scan && scan.code === x.serial_barcode ? ' class="pf-hit"' : ''}><td data-label="Barcode"><code>${esc(x.serial_barcode)}</code></td><td data-label="Batch">${esc(x.batch_no)}</td><td data-label="Location">${esc(x.location_code)}</td><td data-label="Status"><span class="badge">${esc(x.status)}</span></td></tr>`).join('')}</tbody></table></div>` : '<div class="muted">No pieces in your locations.</div>')
        + '</details>';
    }
    detailEl.innerHTML = `
      <div class="pf-detail-head">
        <div class="pf-photo">${p.image ? `<img src="${esc(p.image)}" alt="${esc(p.name)}">` : '<span class="pf-noimg">No photo</span>'}</div>
        <div class="pf-detail-title">
          <h2>${esc(p.name)}</h2>
          <div class="muted">SKU <b>${esc(p.sku)}</b>${p.active ? '' : ' · <span class="badge pf-inactive">Inactive</span>'}</div>
          ${prices}
        </div>
        <button type="button" class="btn secondary pf-close" aria-label="Close details">×</button>
      </div>
      ${scanNote(scan)}
      ${metrics}
      <dl class="pf-attrs">${attrs}</dl>
      <h3>Stock by location</h3>${locTable}
      ${batchTable}
      ${serials}`;
  }

  async function openDetail(id, scan) {
    selectedId = Number(id);
    updateUrl();
    resultsEl.querySelectorAll('.pf-card').forEach((c) => c.classList.toggle('selected', Number(c.dataset.id) === selectedId));
    if (detailCtrl) detailCtrl.abort();
    detailCtrl = new AbortController();
    detailEl.hidden = false;
    detailEl.setAttribute('aria-busy', 'true');
    detailEl.innerHTML = '<div class="pf-loading-box"><span class="pf-spinner" aria-hidden="true"></span> Loading stock…</div>';
    if (window.matchMedia('(max-width: 980px)').matches) detailEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    try {
      const d = await getJSON(`${cfg.stockUrl}?id=${encodeURIComponent(selectedId)}`, detailCtrl.signal);
      renderDetail(d, scan);
      if (scan) beep(true);
    } catch (e) {
      if (e.name === 'AbortError') return;
      detailEl.innerHTML = `<div class="pf-empty pf-error-box"><b>Could not load product</b><div>${esc(e.message)}</div><button type="button" class="btn secondary pf-retry">Retry</button></div>`;
    } finally {
      detailEl.removeAttribute('aria-busy');
    }
  }

  function closeDetail() {
    selectedId = null;
    detailEl.hidden = true;
    detailEl.innerHTML = '';
    resultsEl.querySelectorAll('.pf-card.selected').forEach((c) => c.classList.remove('selected'));
    updateUrl();
  }

  // ---------- Events ----------
  input.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    if (input.value.trim() === lastLookup) return;
    lastLookup = null;
    debounceTimer = setTimeout(liveSearch, DEBOUNCE_MS);
  });
  form.addEventListener('submit', (ev) => { ev.preventDefault(); lookupCode(input.value, 'enter'); });
  resultsEl.addEventListener('click', (ev) => { const card = ev.target.closest('.pf-card'); if (card) openDetail(card.dataset.id, null); });
  detailEl.addEventListener('click', (ev) => {
    if (ev.target.closest('.pf-close')) closeDetail();
    else if (ev.target.closest('.pf-retry') && selectedId) openDetail(selectedId, null);
  });

  root.addEventListener('error', (ev) => {
    const img = ev.target;
    if (img && img.tagName === 'IMG' && img.parentElement) img.parentElement.innerHTML = '<span class="pf-noimg">No photo</span>';
  }, true);

  // Keyboard-wedge (USB/Bluetooth) scanners: fast keystrokes ending in Enter while focus is outside a text field.
  let wedgeBuf = '', wedgeLast = 0;
  document.addEventListener('keydown', (ev) => {
    const t = ev.target;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
    if (ev.ctrlKey || ev.metaKey || ev.altKey) return;
    const now = performance.now();
    if (now - wedgeLast > SCANNER_KEY_GAP_MS * 2) wedgeBuf = '';
    wedgeLast = now;
    if (ev.key === 'Enter') {
      if (wedgeBuf.length >= SCANNER_MIN_LEN) { ev.preventDefault(); const code = wedgeBuf; wedgeBuf = ''; lookupCode(code, 'scanner'); }
      wedgeBuf = '';
    } else if (ev.key.length === 1) {
      wedgeBuf += ev.key;
    }
  });

  // ---------- Camera scanner ----------
  const cam = { stream: null, timer: null, zxing: null, active: false };
  const NATIVE_FORMATS = ['code_128', 'code_39', 'code_93', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'itf', 'codabar', 'qr_code', 'data_matrix'];

  function camMessage(msg, isError) { scanMsg.textContent = msg; scanMsg.className = isError ? 'pf-error' : 'muted'; }

  function loadZXing() {
    if (window.ZXing) return Promise.resolve(window.ZXing);
    return new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = cfg.zxingUrl;
      s.onload = () => (window.ZXing ? resolve(window.ZXing) : reject(new Error('Scanner library failed to load.')));
      s.onerror = () => reject(new Error('Scanner library failed to load.'));
      document.head.appendChild(s);
    });
  }

  function cameraErrorText(e) {
    switch (e && e.name) {
      case 'NotAllowedError': case 'SecurityError': return 'Camera permission was denied. Allow camera access for this site in your browser settings, or type the code / use a USB scanner.';
      case 'NotFoundError': case 'OverconstrainedError': return 'No camera was found on this device. Type the code or use a USB/Bluetooth scanner.';
      case 'NotReadableError': return 'The camera is being used by another app. Close it and try again.';
      default: return (e && e.message) || 'Could not start the camera.';
    }
  }

  function stopCamera() {
    cam.active = false;
    clearTimeout(cam.timer);
    if (cam.zxing) { try { cam.zxing.reset(); } catch (e) { /* already stopped */ } cam.zxing = null; }
    if (cam.stream) { cam.stream.getTracks().forEach((t) => t.stop()); cam.stream = null; }
    video.srcObject = null;
  }

  function closeScanner() { stopCamera(); scanPanel.hidden = true; input.focus(); }

  function onCameraCode(code) {
    if (!cam.active) return;
    closeScanner();
    if (navigator.vibrate) navigator.vibrate(80);
    lookupCode(String(code), 'camera');
  }

  async function startCamera() {
    scanPanel.hidden = false;
    scanPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      camMessage('Camera scanning needs a secure (HTTPS) connection and a supported browser. Type the code or use a USB/Bluetooth scanner instead.', true);
      return;
    }
    stopCamera();
    cam.active = true;
    camMessage('Requesting camera permission…');
    const constraints = { audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } } };
    try {
      let detector = null;
      if ('BarcodeDetector' in window) {
        try {
          const supported = await window.BarcodeDetector.getSupportedFormats();
          const formats = NATIVE_FORMATS.filter((f) => supported.includes(f));
          if (formats.length) detector = new window.BarcodeDetector({ formats });
        } catch (e) { detector = null; }
      }
      if (detector) {
        cam.stream = await navigator.mediaDevices.getUserMedia(constraints);
        if (!cam.active) { stopCamera(); return; }
        video.srcObject = cam.stream;
        await video.play();
        camMessage('Point the camera at a barcode.');
        const tick = async () => {
          if (!cam.active) return;
          try {
            if (video.readyState >= 2) {
              const codes = await detector.detect(video);
              if (codes.length && codes[0].rawValue) { onCameraCode(codes[0].rawValue); return; }
            }
          } catch (e) { /* frame not ready */ }
          cam.timer = setTimeout(tick, 150);
        };
        tick();
      } else {
        camMessage('Loading scanner…');
        const ZX = await loadZXing();
        if (!cam.active) return;
        cam.zxing = new ZX.BrowserMultiFormatReader();
        camMessage('Requesting camera permission…');
        await cam.zxing.decodeFromConstraints(constraints, video, (result) => { if (result) onCameraCode(result.getText()); });
        if (cam.active) camMessage('Point the camera at a barcode.');
      }
    } catch (e) {
      stopCamera();
      camMessage(cameraErrorText(e), true);
    }
  }

  scanBtn.addEventListener('click', startCamera);
  scanClose.addEventListener('click', closeScanner);
  document.addEventListener('visibilitychange', () => { if (document.hidden && cam.active) closeScanner(); });
  window.addEventListener('pagehide', stopCamera);

  // ---------- Feedback ----------
  let audioCtx = null;
  function beep(ok) {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      const o = audioCtx.createOscillator(), g = audioCtx.createGain();
      o.frequency.value = ok ? 1200 : 300; g.gain.value = 0.05;
      o.connect(g); g.connect(audioCtx.destination); o.start(); o.stop(audioCtx.currentTime + (ok ? 0.08 : 0.25));
    } catch (e) { /* audio not available */ }
  }

  // ---------- Initial state from URL ----------
  const initialQ = root.dataset.initialQ || '';
  const initialId = Number(root.dataset.initialId || 0);
  if (initialQ) { input.value = initialQ; liveSearch(); }
  if (initialId > 0) openDetail(initialId, null);
})();
