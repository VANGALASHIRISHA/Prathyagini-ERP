// Adds a QR code in the top-right corner of every label (.label[data-code]) and keeps the text clear of it,
// so the Code128 barcode keeps its full width (laser scanners need wide, thin bars).
// Needs assets/js/qrcode.js (qrcode-generator, MIT). Safe to call again after labels are re-rendered.
(function () {
  function qrSvg(text) {
    var q = qrcode(0, 'M'); q.addData(String(text)); q.make();
    var n = q.getModuleCount(), quiet = 2, size = n + quiet * 2, d = '';
    for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) if (q.isDark(r, c)) d += 'M' + (c + quiet) + ' ' + (r + quiet) + 'h1v1h-1z';
    return '<svg class="qr" viewBox="0 0 ' + size + ' ' + size + '" xmlns="http://www.w3.org/2000/svg" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
  }
  function addQr(root) {
    if (typeof qrcode !== 'function') return;
    (root || document).querySelectorAll('.label[data-code]').forEach(function (label) {
      if (label.querySelector('svg.qr')) return;
      label.classList.add('has-qr');
      label.insertAdjacentHTML('afterbegin', qrSvg(label.getAttribute('data-code')));
    });
  }
  window.PFLabelQR = addQr;
  var css = document.createElement('style');
  css.textContent = '.label.has-qr{position:relative}' +
    '.label.has-qr>svg.qr{position:absolute;top:1.2mm;right:1.2mm;width:10mm!important;height:10mm!important;max-width:none}' +
    '.label.has-qr>div:nth-child(-n+4):not(.code):not(.price){padding-right:11mm}';
  document.head.appendChild(css);
  if (document.readyState !== 'loading') addQr(); else document.addEventListener('DOMContentLoaded', function () { addQr(); });
})();
