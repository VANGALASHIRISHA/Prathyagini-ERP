<?php
// Inventory integrity checks. Read-only: nothing is ever changed here.
// Each check returns: key, title, explain, count (problems found), rows (up to 20 examples).
function integrity_checks(): array {
    $pdo = db();
    $defs = [
        ['ledger', 'Stock balance equals stock ledger',
         'For every product + batch + location the stock balance must equal everything received minus everything sent in the stock ledger.',
         "SELECT p.sku, b.batch_no, l.code location_code, ib.qty balance, COALESCE(x.net,0) ledger_net
          FROM inventory_balances ib JOIN products p ON p.id=ib.product_id JOIN batches b ON b.id=ib.batch_id JOIN locations l ON l.id=ib.location_id
          LEFT JOIN (SELECT product_id,batch_id,location_id,SUM(qty_in-qty_out) net FROM stock_ledger GROUP BY product_id,batch_id,location_id) x
            ON x.product_id=ib.product_id AND x.batch_id=ib.batch_id AND x.location_id=ib.location_id
          WHERE ABS(ib.qty-COALESCE(x.net,0))>0.0005"],
        ['ledger_orphan', 'No ledger movement without a balance row',
         'A ledger line with a non-zero net result must have a matching balance row.',
         "SELECT p.sku, b.batch_no, l.code location_code, x.net ledger_net
          FROM (SELECT product_id,batch_id,location_id,SUM(qty_in-qty_out) net FROM stock_ledger GROUP BY product_id,batch_id,location_id HAVING ABS(SUM(qty_in-qty_out))>0.0005) x
          JOIN products p ON p.id=x.product_id JOIN batches b ON b.id=x.batch_id JOIN locations l ON l.id=x.location_id
          LEFT JOIN inventory_balances ib ON ib.product_id=x.product_id AND ib.batch_id=x.batch_id AND ib.location_id=x.location_id
          WHERE ib.product_id IS NULL"],
        ['batch', 'Batch quantity equals the sum of its balances',
         'A batch’s available quantity must equal what its balance rows hold across all locations.',
         "SELECT p.sku, b.batch_no, b.qty_available batch_qty, COALESCE(s.q,0) balances
          FROM batches b JOIN products p ON p.id=b.product_id
          LEFT JOIN (SELECT batch_id,SUM(qty) q FROM inventory_balances GROUP BY batch_id) s ON s.batch_id=b.id
          WHERE ABS(b.qty_available-COALESCE(s.q,0))>0.0005"],
        ['negative', 'No negative stock and no over-reserved stock',
         'Stock can never be below zero, and reserved quantity can never be more than the stock on hand.',
         "SELECT p.sku, b.batch_no, l.code location_code, ib.qty, ib.reserved_qty
          FROM inventory_balances ib JOIN products p ON p.id=ib.product_id JOIN batches b ON b.id=ib.batch_id JOIN locations l ON l.id=ib.location_id
          WHERE ib.qty<-0.0005 OR ib.reserved_qty<-0.0005 OR ib.reserved_qty>ib.qty+0.0005"],
        ['serial', 'Unique-piece barcodes match the stock balance',
         'For garments tracked piece by piece, the number of AVAILABLE or RESERVED barcodes at a location must equal the stock balance there. Sold, damaged and in-transit pieces are not counted.',
         "SELECT p.sku, b.batch_no, l.code location_code, ib.qty balance, COALESCE(s.c,0) barcodes
          FROM inventory_balances ib JOIN products p ON p.id=ib.product_id AND p.serial_tracking=1 JOIN batches b ON b.id=ib.batch_id JOIN locations l ON l.id=ib.location_id
          LEFT JOIN (SELECT batch_id,location_id,COUNT(*) c FROM product_serials WHERE status IN ('AVAILABLE','RESERVED') GROUP BY batch_id,location_id) s
            ON s.batch_id=ib.batch_id AND s.location_id=ib.location_id
          WHERE ABS(ib.qty-COALESCE(s.c,0))>0.0005"],
        ['serial_loc', 'Every available barcode sits where stock exists',
         'A barcode marked AVAILABLE or RESERVED at a location needs a stock balance row for that batch and location.',
         "SELECT ps.serial_barcode, l.code location_code, p.sku
          FROM product_serials ps JOIN products p ON p.id=ps.product_id JOIN locations l ON l.id=ps.location_id
          LEFT JOIN inventory_balances ib ON ib.batch_id=ps.batch_id AND ib.location_id=ps.location_id AND ib.product_id=ps.product_id
          WHERE ps.status IN ('AVAILABLE','RESERVED') AND (ib.product_id IS NULL OR ib.qty<=0.0005)"],
        ['sold', 'Every SOLD barcode belongs to a bill',
         'A piece marked SOLD must appear on a sale (and that sale must not be fully returned).',
         "SELECT ps.serial_barcode, p.sku FROM product_serials ps JOIN products p ON p.id=ps.product_id
          WHERE ps.status='SOLD' AND NOT EXISTS (SELECT 1 FROM sale_items si WHERE si.serial_id=ps.id)"],
        ['purchase', 'Purchase totals equal their lines',
         'The total cost of a purchase must equal the sum of quantity × rate of its lines.',
         "SELECT pu.purchase_no, pu.total_cost, ROUND(SUM(pi.quantity*pi.cost_price),2) lines_total
          FROM purchases pu JOIN purchase_items pi ON pi.purchase_id=pu.id WHERE pu.entry_mode='LEGACY' GROUP BY pu.id,pu.purchase_no,pu.total_cost
          HAVING ABS(pu.total_cost-SUM(pi.quantity*pi.cost_price))>0.01"],
        ['transit', 'In-transit barcodes belong to a real transfer',
         'Pieces marked IN_TRANSIT must belong to a dispatched transfer, or to a received transfer where a shortage was recorded.',
         "SELECT ps.serial_barcode, p.sku FROM product_serials ps JOIN products p ON p.id=ps.product_id
          WHERE ps.status='IN_TRANSIT' AND NOT EXISTS (SELECT 1 FROM stock_transfer_items i JOIN stock_transfers t ON t.id=i.transfer_id WHERE i.serial_id=ps.id AND (t.status='DISPATCHED' OR (t.status='RECEIVED' AND t.has_discrepancy=1)))"],
        ['missing', 'No pieces missing after a transfer',
         'Pieces that were dispatched but never received (a shortage was recorded). They are not at either location and need investigation; post a Stock Adjustment once the cause is known.',
         "SELECT t.transfer_no, ps.serial_barcode, p.sku, t.received_at
          FROM stock_transfer_items i JOIN stock_transfers t ON t.id=i.transfer_id AND t.status='RECEIVED' AND t.has_discrepancy=1
          JOIN product_serials ps ON ps.id=i.serial_id AND ps.status='IN_TRANSIT' JOIN products p ON p.id=ps.product_id"],
    ];
    $out = [];
    foreach ($defs as [$key, $title, $explain, $sql]) {
        try {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM (' . $sql . ') q')->fetchColumn();
            $rows = $count ? $pdo->query($sql . ' LIMIT 20')->fetchAll() : [];
            $out[] = ['key' => $key, 'title' => $title, 'explain' => $explain, 'count' => $count, 'rows' => $rows, 'error' => null];
        } catch (Throwable $e) {
            error_log('integrity ' . $key . ': ' . $e->getMessage());
            $out[] = ['key' => $key, 'title' => $title, 'explain' => $explain, 'count' => -1, 'rows' => [], 'error' => 'Could not run this check.'];
        }
    }
    return $out;
}
