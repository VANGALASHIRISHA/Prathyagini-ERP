<?php
// Posts one supplier invoice with many lines in a single transaction: purchase header, batch,
// purchase item, stock balance, stock ledger and (for serial-tracked products) one unique barcode per piece.
// $lines: [['product_id'=>int,'location_id'=>int,'qty'=>int|float,'cost'=>float,'batch'=>string], ...]
// Throws on any problem; nothing is kept in that case.
function purchase_post_lines(int $supplierId, string $invoice, string $date, array $lines): array {
    if (!$lines) throw new Exception('No lines to post.');
    $uid = (int)current_user()['id'];
    $total = 0.0;
    foreach ($lines as $l) $total += $l['qty'] * $l['cost'];
    db()->beginTransaction();
    try {
        $chk = db()->prepare('SELECT 1 FROM suppliers WHERE id=? AND active=1 FOR UPDATE');
        $chk->execute([$supplierId]);
        if (!$chk->fetchColumn()) throw new Exception('Supplier is missing or inactive.');
        if ($invoice !== '') {
            $chk = db()->prepare('SELECT purchase_no FROM purchases WHERE supplier_id=? AND invoice_no=? LIMIT 1');
            $chk->execute([$supplierId, $invoice]);
            if ($dup = $chk->fetchColumn()) throw new Exception('Supplier invoice ' . $invoice . ' is already posted as ' . $dup . '. Stock was not added again.');
        }
        $purchaseNo = 'PUR-' . date('YmdHis') . '-' . random_int(100, 999);
        db()->prepare('INSERT INTO purchases(purchase_no,supplier_id,invoice_no,invoice_date,total_cost,created_by) VALUES(?,?,?,?,?,?)')
            ->execute([$purchaseNo, $supplierId, $invoice, $date, $total, $uid]);
        $purchaseId = (int)db()->lastInsertId();
        db()->prepare("INSERT INTO supplier_ledger(supplier_id,txn_type,credit,reference_type,reference_id,remarks,created_by) VALUES(?,'PURCHASE',?,'PURCHASE',?,?,?)")
            ->execute([$supplierId, $total, $purchaseId, $purchaseNo, $uid]);
        $batches = [];
        $serialCount = 0;
        $pst = db()->prepare('SELECT sku,serial_tracking FROM products WHERE id=? AND active=1 FOR UPDATE');
        $lchk = db()->prepare('SELECT 1 FROM locations WHERE id=? AND active=1');
        $check = db()->prepare('SELECT 1 FROM product_serials WHERE serial_barcode=?');
        foreach ($lines as $l) {
            $pst->execute([$l['product_id']]);
            $p = $pst->fetch();
            if (!$p) throw new Exception('A product is missing or inactive.');
            $lchk->execute([$l['location_id']]);
            if (!$lchk->fetchColumn()) throw new Exception('A stock location is missing or inactive.');
            $serial = (int)$p['serial_tracking'] === 1;
            if ($serial && abs($l['qty'] - round($l['qty'])) > 0.0001) throw new Exception($p['sku'] . ': serialized garments need whole-piece quantities.');
            $skuKey = preg_replace('/[^A-Z0-9]/', '', strtoupper($p['sku']));
            $batchBarcode = 'PFB-' . $skuKey . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            db()->prepare('INSERT INTO batches(batch_no,batch_barcode,product_id,supplier_id,purchase_id,purchase_invoice_no,purchased_at,cost_price,cost_code,qty_received,qty_available) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$l['batch'], $batchBarcode, $l['product_id'], $supplierId, $purchaseId, $invoice, $date, $l['cost'], 'BC-' . strtoupper(bin2hex(random_bytes(4))), $l['qty'], $l['qty']]);
            $batchId = (int)db()->lastInsertId();
            db()->prepare('INSERT INTO purchase_items(purchase_id,product_id,batch_id,location_id,quantity,cost_price) VALUES(?,?,?,?,?,?)')
                ->execute([$purchaseId, $l['product_id'], $batchId, $l['location_id'], $l['qty'], $l['cost']]);
            db()->prepare('INSERT INTO inventory_balances(product_id,batch_id,location_id,qty) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)')
                ->execute([$l['product_id'], $batchId, $l['location_id'], $l['qty']]);
            db()->prepare("INSERT INTO stock_ledger(txn_type,product_id,batch_id,location_id,qty_in,reference_type,reference_id,unit_rate,remarks,created_by) VALUES('PURCHASE',?,?,?,?,'PURCHASE',?,?,?,?)")
                ->execute([$l['product_id'], $batchId, $l['location_id'], $l['qty'], $purchaseId, $l['cost'], $invoice, $uid]);
            db()->prepare('UPDATE products SET purchase_cost=? WHERE id=?')->execute([$l['cost'], $l['product_id']]);
            if ($serial) {
                $ins = db()->prepare("INSERT INTO product_serials(serial_barcode,product_id,batch_id,location_id,status) VALUES(?,?,?,?,'AVAILABLE')");
                for ($n = 1; $n <= (int)round($l['qty']); $n++) {
                    do {
                        $code = 'PFS-' . $skuKey . '-' . $batchId . '-' . str_pad((string)$n, 5, '0', STR_PAD_LEFT) . '-' . strtoupper(bin2hex(random_bytes(1)));
                        $check->execute([$code]);
                    } while ($check->fetchColumn());
                    $ins->execute([$code, $l['product_id'], $batchId, $l['location_id']]);
                    $serialCount++;
                }
            }
            $batches[] = ['id' => $batchId, 'label' => $p['sku'] . ' · ' . $l['batch']];
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    audit('PURCHASE_INWARD', 'purchase', $purchaseId, ['purchase_no' => $purchaseNo, 'via' => 'csv_import', 'lines' => count($lines), 'total' => $total]);
    return ['purchase_id' => $purchaseId, 'purchase_no' => $purchaseNo, 'batches' => $batches, 'serials' => $serialCount, 'total' => $total];
}
