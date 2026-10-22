<?php
// Scan & Find: scan a garment / batch / product barcode or a rack-bin label and see exactly
// where it is stored, how much is there and which online orders are waiting for it.
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/product_finder.php';
require_permission('view_stock');
$initialCode = pf_clean_query(is_string($_GET['code'] ?? null) ? $_GET['code'] : '');
page_header('Scan & Find');
?>
<link rel="stylesheet" href="assets/css/scan.css" data-scan-css>
<div id="scanFind"
     data-api-url="api/scan_find.php"
     data-pick-url="online_pick.php"
     data-initial-code="<?= e($initialCode) ?>">
  <p class="muted"><?= is_super_admin() ? 'Searching all locations.' : 'Searching your assigned locations only.' ?> Scan a garment, batch or product barcode, a SKU, or a rack / bin label.</p>

  <div class="panel sf-bar">
    <form id="sfForm" autocomplete="off">
      <label for="sfCode" class="muted">Barcode / QR / SKU / rack code</label>
      <input id="sfCode" class="scanbox" name="code" maxlength="<?= PF_MAX_QUERY_LEN ?>" data-scanner autofocus required
             placeholder="Scan or type, then Enter" autocapitalize="off" spellcheck="false" enterkeyhint="search" aria-describedby="sfStatus">
      <div class="sf-actions">
        <button type="submit" class="btn">Find</button>
        <button type="button" id="sfCamBtn" class="btn secondary">Scan with Camera</button>
      </div>
    </form>
    <div id="sfStatus" class="sf-status" role="status" aria-live="polite"></div>
  </div>

  <div id="sfResult" class="sf-result" aria-live="polite"></div>
</div>
<script src="assets/js/camera_scan.js" defer></script>
<script src="assets/js/scan_find.js" defer></script>
<?php page_footer();
