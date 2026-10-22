<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/product_finder.php';
require_permission('view_stock');
$vis = pf_visibility();
$initialQ = pf_clean_query(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$initialId = max(0, (int)($_GET['id'] ?? 0));
page_header('Product Finder');
?>
<div id="productFinder" class="pf"
     data-search-url="api/product_search.php"
     data-stock-url="api/product_stock.php"
     data-zxing-url="assets/js/vendor/zxing/zxing.min.js"
     data-show-prices="<?= $vis['prices'] ? '1' : '0' ?>"
     data-show-reserved="<?= $vis['reserved'] ? '1' : '0' ?>"
     data-initial-q="<?= e($initialQ) ?>"
     data-initial-id="<?= e($initialId ?: '') ?>">
  <p class="muted"><?= is_super_admin() ? 'Showing stock across all locations.' : 'Showing stock for your assigned locations only.' ?> Search by product name, SKU, product barcode, batch barcode or individual piece barcode. USB/Bluetooth scanners work anywhere on this page.</p>

  <div class="panel pf-searchbar">
    <form id="pfForm" class="pf-search" role="search" autocomplete="off">
      <label for="pfQuery" class="pf-sr">Search products</label>
      <span class="pf-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M15.5 15.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>
      <input id="pfQuery" class="scanbox" type="search" name="q" maxlength="<?= PF_MAX_QUERY_LEN ?>" placeholder="Type name / SKU or scan a barcode" data-scanner autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search" aria-describedby="pfStatus">
      <button type="button" id="pfScanBtn" class="btn"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M3 7V4h3M21 7V4h-3M3 17v3h3M21 17v3h-3M7 8v8M10 8v8M13 8v8M17 8v8" fill="none" stroke="currentColor" stroke-width="2"/></svg> Scan Barcode</button>
    </form>
    <div id="pfStatus" class="pf-status muted" role="status" aria-live="polite"></div>
  </div>

  <div id="pfScanner" class="panel pf-scanner" hidden>
    <div class="pf-scanner-head"><b>Camera scanner</b><button type="button" id="pfScanClose" class="btn secondary">Close</button></div>
    <div class="pf-video-wrap"><video id="pfVideo" playsinline muted></video><div class="pf-reticle" aria-hidden="true"></div></div>
    <div id="pfScanMsg" class="muted">Point the camera at a barcode.</div>
  </div>

  <div class="pf-layout">
    <section class="pf-results-col" aria-label="Search results">
      <div id="pfResults" class="pf-results"><div class="pf-empty muted">Start typing at least 2 characters, or scan a barcode.</div></div>
    </section>
    <section id="pfDetail" class="pf-detail panel" aria-label="Product details" aria-live="polite" hidden></section>
  </div>
</div>
<script src="assets/js/product_finder.js" defer></script>
<?php page_footer();
