// Shared camera barcode scanner (QR, Code128, EAN-13/8, UPC-A/E, Code39).
// Usage: CameraScan.open({ title: 'Scan', onDetect: function (code) { ... } });
// Uses the browser's native BarcodeDetector when available, otherwise the bundled ZXing library.
// The rear camera is preferred and is always stopped after the first successful read or on close.
(function () {
  'use strict';
  if (window.CameraScan) return;

  var scriptSrc = (document.currentScript && document.currentScript.src) || (location.origin + '/assets/js/camera_scan.js');
  var ZXING_URL = new URL('vendor/zxing/zxing.min.js', scriptSrc).href;
  var CSS_URL = new URL('../css/scan.css', scriptSrc).href;
  var NATIVE_FORMATS = ['qr_code', 'code_128', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_39'];

  var ui = null;
  var cam = { stream: null, timer: null, zxing: null, active: false, onDetect: null };

  function ensureStyles() {
    if (document.querySelector('link[data-scan-css]')) return;
    var l = document.createElement('link');
    l.rel = 'stylesheet'; l.href = CSS_URL; l.setAttribute('data-scan-css', '');
    document.head.appendChild(l);
  }

  function buildUi() {
    if (ui) return ui;
    ensureStyles();
    var overlay = document.createElement('div');
    overlay.className = 'cs-overlay';
    overlay.hidden = true;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML =
      '<div class="cs-box">' +
      '  <div class="cs-head"><b class="cs-title">Scan barcode</b><button type="button" class="btn secondary cs-close">Close</button></div>' +
      '  <div class="cs-video-wrap"><video playsinline muted></video><div class="cs-reticle" aria-hidden="true"></div></div>' +
      '  <div class="cs-msg muted" role="status" aria-live="polite"></div>' +
      '</div>';
    document.body.appendChild(overlay);
    ui = {
      overlay: overlay,
      title: overlay.querySelector('.cs-title'),
      video: overlay.querySelector('video'),
      msg: overlay.querySelector('.cs-msg'),
    };
    overlay.querySelector('.cs-close').addEventListener('click', close);
    overlay.addEventListener('click', function (ev) { if (ev.target === overlay) close(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !overlay.hidden) close(); });
    document.addEventListener('visibilitychange', function () { if (document.hidden && cam.active) close(); });
    window.addEventListener('pagehide', stop);
    return ui;
  }

  function message(text, isError) {
    ui.msg.textContent = text;
    ui.msg.className = 'cs-msg ' + (isError ? 'cs-error' : 'muted');
  }

  function errorText(e) {
    switch (e && e.name) {
      case 'NotAllowedError':
      case 'SecurityError': return 'Camera permission was denied. Allow camera access for this site in your browser settings, or type the code / use a USB scanner.';
      case 'NotFoundError':
      case 'OverconstrainedError': return 'No camera was found on this device. Type the code or use a USB/Bluetooth scanner.';
      case 'NotReadableError': return 'The camera is being used by another app. Close it and try again.';
      default: return (e && e.message) || 'Could not start the camera.';
    }
  }

  function loadZXing() {
    if (window.ZXing) return Promise.resolve(window.ZXing);
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = ZXING_URL;
      s.onload = function () { window.ZXing ? resolve(window.ZXing) : reject(new Error('Scanner library failed to load.')); };
      s.onerror = function () { reject(new Error('Scanner library failed to load.')); };
      document.head.appendChild(s);
    });
  }

  function zxingHints(ZX) {
    var hints = new Map();
    var F = ZX.BarcodeFormat;
    hints.set(ZX.DecodeHintType.POSSIBLE_FORMATS, [F.QR_CODE, F.CODE_128, F.EAN_13, F.EAN_8, F.UPC_A, F.UPC_E, F.CODE_39]);
    return hints;
  }

  function stop() {
    cam.active = false;
    clearTimeout(cam.timer);
    if (cam.zxing) { try { cam.zxing.reset(); } catch (e) { /* already stopped */ } cam.zxing = null; }
    if (cam.stream) { cam.stream.getTracks().forEach(function (t) { t.stop(); }); cam.stream = null; }
    if (ui) ui.video.srcObject = null;
  }

  function close() {
    stop();
    if (ui) ui.overlay.hidden = true;
    cam.onDetect = null;
  }

  function detected(code) {
    if (!cam.active) return;
    var cb = cam.onDetect;
    close();
    if (navigator.vibrate) navigator.vibrate(80);
    if (cb) cb(String(code).trim());
  }

  async function nativeDetector() {
    if (!('BarcodeDetector' in window)) return null;
    try {
      var supported = await window.BarcodeDetector.getSupportedFormats();
      var formats = NATIVE_FORMATS.filter(function (f) { return supported.indexOf(f) !== -1; });
      return formats.length ? new window.BarcodeDetector({ formats: formats }) : null;
    } catch (e) { return null; }
  }

  async function open(opts) {
    opts = opts || {};
    buildUi();
    stop();
    cam.onDetect = typeof opts.onDetect === 'function' ? opts.onDetect : null;
    ui.title.textContent = opts.title || 'Scan barcode';
    ui.overlay.hidden = false;

    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      message('Camera scanning needs a secure (HTTPS) connection and a supported browser. Type the code or use a USB/Bluetooth scanner instead.', true);
      return;
    }
    cam.active = true;
    message('Requesting camera permission…');
    var constraints = { audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } } };
    try {
      var detector = await nativeDetector();
      if (detector) {
        cam.stream = await navigator.mediaDevices.getUserMedia(constraints);
        if (!cam.active) { stop(); return; }
        ui.video.srcObject = cam.stream;
        await ui.video.play();
        message('Point the camera at a barcode or QR code.');
        var tick = async function () {
          if (!cam.active) return;
          try {
            if (ui.video.readyState >= 2) {
              var codes = await detector.detect(ui.video);
              if (codes.length && codes[0].rawValue) { detected(codes[0].rawValue); return; }
            }
          } catch (e) { /* frame not ready yet */ }
          cam.timer = setTimeout(tick, 150);
        };
        tick();
      } else {
        message('Loading scanner…');
        var ZX = await loadZXing();
        if (!cam.active) return;
        cam.zxing = new ZX.BrowserMultiFormatReader(zxingHints(ZX));
        message('Requesting camera permission…');
        await cam.zxing.decodeFromConstraints(constraints, ui.video, function (result) { if (result) detected(result.getText()); });
        if (cam.active) message('Point the camera at a barcode or QR code.');
      }
    } catch (e) {
      stop();
      message(errorText(e), true);
    }
  }

  window.CameraScan = { open: open, close: close };
})();
