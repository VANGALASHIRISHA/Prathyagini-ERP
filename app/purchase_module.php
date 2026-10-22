<?php
// Textile Purchase & Vendor Management: shared logic.
// Bill (purchases + purchase_bill_lines) -> approval -> goods receipt (the ONLY place stock is created) -> payments.
// Every money figure is recalculated here on the server; the browser totals are for display only.

const PM_UNITS = ['PCS', 'MTR', 'ROLL', 'SET', 'KG', 'BOX', 'DOZEN'];
const PM_PAY_METHODS = ['CASH', 'UPI', 'BANK', 'CHEQUE', 'CARD', 'OTHER'];
const PM_VENDOR_TYPES = ['Manufacturer', 'Wholesaler', 'Agent / Broker', 'Retailer', 'Job Worker', 'Other'];

function pm_can_view(): bool { return has_permission('view_purchases') || has_permission('manage_purchases'); }
function pm_require_view(): void { login_required(); if (!pm_can_view()) { http_response_code(403); exit('Access denied'); } }
function pm_can_manage(): bool { return has_permission('manage_purchases'); }
function pm_can_approve(): bool { return has_permission('approve_purchases'); }
function pm_can_pay(): bool { return has_permission('manage_vendor_payments'); }
function pm_can_receive(): bool { return has_permission('receive_goods'); }
function pm_money($v): float { return round((float)$v, 2); }

/** Allowed GST slabs, configured by the Super Admin in Purchase Settings (nothing hardcoded in the calculation). */
function pm_gst_rates(): array {
    $raw = setting('purchase_gst_rates', '0,5,12,18,28') ?? '';
    $out = [];
    foreach (explode(',', $raw) as $p) {
        $p = trim($p);
        if ($p !== '' && is_numeric($p) && (float)$p >= 0 && (float)$p <= 100) $out[] = round((float)$p, 2);
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out ?: [0.0];
}

function pm_company_state_code(): string { return trim((string)setting('company_state_code', '')); }

/** CGST+SGST when vendor and company are in the same state, otherwise IGST. Null when it cannot be decided. */
function pm_gst_type(?string $vendorState): ?string {
    $c = pm_company_state_code();
    $v = trim((string)$vendorState);
    if (!preg_match('/^\d{2}$/', $c) || !preg_match('/^\d{2}$/', $v)) return null;
    return $c === $v ? 'CGST_SGST' : 'IGST';
}

/**
 * Server-side totals. $lines items: qty, rate, discount, gst_rate. Returns per-line and header figures.
 * Rounding rule: header is rounded to the nearest rupee and the difference is stored as round_off.
 */
function pm_calculate(array $lines, ?string $gstType, float $freight, float $packing, float $other): array {
    $out = ['lines' => [], 'subtotal' => 0.0, 'discount' => 0.0, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'lines_total' => 0.0];
    foreach ($lines as $i => $l) {
        $gross = pm_money($l['qty'] * $l['rate']);
        $disc = pm_money($l['discount']);
        if ($disc < 0 || $disc > $gross + 0.0001) throw new Exception('Line ' . ($i + 1) . ': discount cannot be negative or more than qty × rate.');
        $taxable = pm_money($gross - $disc);
        $rate = (float)$l['gst_rate'];
        $cg = $sg = $ig = 0.0;
        if ($gstType === 'IGST') $ig = pm_money($taxable * $rate / 100);
        elseif ($gstType === 'CGST_SGST') { $cg = pm_money($taxable * $rate / 200); $sg = pm_money($taxable * $rate / 200); }
        $total = pm_money($taxable + $cg + $sg + $ig);
        $out['lines'][$i] = ['gross' => $gross, 'taxable' => $taxable, 'cgst' => $cg, 'sgst' => $sg, 'igst' => $ig, 'total' => $total];
        $out['subtotal'] += $gross; $out['discount'] += $disc; $out['taxable'] += $taxable;
        $out['cgst'] += $cg; $out['sgst'] += $sg; $out['igst'] += $ig; $out['lines_total'] += $total;
    }
    foreach (['subtotal', 'discount', 'taxable', 'cgst', 'sgst', 'igst', 'lines_total'] as $k) $out[$k] = pm_money($out[$k]);
    $pre = pm_money($out['lines_total'] + $freight + $packing + $other);
    $out['round_off'] = pm_money(round($pre) - $pre);
    $out['grand_total'] = pm_money($pre + $out['round_off']);
    return $out;
}

function pm_unique_no(string $prefix, string $table, string $col): string {
    $chk = db()->prepare("SELECT 1 FROM $table WHERE $col=? LIMIT 1");
    for ($i = 0; $i < 20; $i++) {
        $no = $prefix . '-' . date('Ymd') . '-' . str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        $chk->execute([$no]);
        if (!$chk->fetchColumn()) return $no;
    }
    throw new Exception('Could not generate a unique number. Please try again.');
}

function pm_invoice_key(string $invoice): ?string {
    $k = strtoupper(preg_replace('/\s+/', '', trim($invoice)));
    return $k === '' ? null : mb_substr($k, 0, 100);
}

// ---------- vendor bill attachment (stored OUTSIDE public, served only through purchase_file.php) ----------
function pm_storage_dir(): string {
    $dir = __DIR__ . '/../storage/purchase_bills';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new Exception('The purchase bill storage folder is not writable.');
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
    return $dir;
}

/** Returns [path, original name] for a valid PDF/JPG/PNG upload, or null when nothing was uploaded. */
function pm_save_attachment(string $field = 'bill_file'): ?array {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('The bill file could not be uploaded (error ' . (int)$f['error'] . ').');
    if ($f['size'] > 5 * 1024 * 1024) throw new Exception('The bill file must be 5 MB or smaller.');
    $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 16);
    $ext = null;
    if (str_starts_with($head, '%PDF')) $ext = 'pdf';
    elseif (str_starts_with($head, "\xFF\xD8\xFF")) $ext = 'jpg';
    elseif (str_starts_with($head, "\x89PNG\r\n\x1A\n")) $ext = 'png';
    if (!$ext) throw new Exception('The bill file must be a PDF, JPG or PNG.');
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dir = pm_storage_dir();
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new Exception('The bill file could not be saved.');
    $orig = preg_replace('/[^A-Za-z0-9._ -]/', '_', mb_substr((string)$f['name'], 0, 120));
    return [$name, $orig];
}

function pm_status_badge(string $s): string {
    $cls = match ($s) {
        'APPROVED', 'RECEIVED', 'PAID', 'CONFIRMED', 'ACTIVE' => 'success',
        'CANCELLED', 'REVERSED', 'UNPAID' => 'error',
        default => '',
    };
    return '<span class="badge ' . $cls . '" style="padding:2px 8px;border:1px solid #bbb;border-radius:10px;font-size:12px">' . e($s) . '</span>';
}

// ---------- payments ----------
/** Recomputes amount_paid and payment_status of a module bill from its ACTIVE payments. */
function pm_refresh_payment_status(int $purchaseId): void {
    $st = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM vendor_payments WHERE purchase_id=? AND status='ACTIVE'");
    $st->execute([$purchaseId]);
    $paid = pm_money($st->fetchColumn());
    $g = db()->prepare("SELECT grand_total FROM purchases WHERE id=? AND entry_mode='MODULE'");
    $g->execute([$purchaseId]);
    $grand = $g->fetchColumn();
    if ($grand === false) return;
    $status = $paid <= 0.004 ? 'UNPAID' : ($paid + 0.004 >= (float)$grand ? 'PAID' : 'PARTIAL');
    db()->prepare('UPDATE purchases SET amount_paid=?, payment_status=? WHERE id=?')->execute([$paid, $status, $purchaseId]);
}

/** Must be called inside a transaction. Posts a payment and the matching supplier-ledger debit. */
function pm_insert_payment(int $supplierId, ?int $purchaseId, float $amount, string $method, string $ref, string $paidOn, string $remarks): int {
    $uid = (int)current_user()['id'];
    $amount = pm_money($amount);
    if ($amount <= 0) throw new Exception('Payment amount must be greater than zero.');
    if (!in_array($method, PM_PAY_METHODS, true)) throw new Exception('Choose a valid payment method.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn)) throw new Exception('Payment date is invalid.');
    if ($purchaseId) {
        $st = db()->prepare("SELECT supplier_id,grand_total,amount_paid,bill_status FROM purchases WHERE id=? AND entry_mode='MODULE' FOR UPDATE");
        $st->execute([$purchaseId]);
        $b = $st->fetch();
        if (!$b || (int)$b['supplier_id'] !== $supplierId) throw new Exception('The bill does not belong to this vendor.');
        if ($b['bill_status'] !== 'APPROVED') throw new Exception('Payments can be recorded only against an approved bill.');
        if ($amount > pm_money($b['grand_total'] - $b['amount_paid']) + 0.004) throw new Exception('Payment is more than the balance due on this bill (₹' . number_format($b['grand_total'] - $b['amount_paid'], 2) . ').');
    }
    $no = pm_unique_no('VP', 'vendor_payments', 'payment_no');
    db()->prepare('INSERT INTO vendor_payments(payment_no,supplier_id,purchase_id,amount,method,reference,paid_on,remarks,created_by) VALUES(?,?,?,?,?,?,?,?,?)')
        ->execute([$no, $supplierId, $purchaseId, $amount, $method, $ref ?: null, $paidOn, $remarks ?: null, $uid]);
    $pid = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO supplier_ledger(supplier_id,txn_type,debit,reference_type,reference_id,remarks,created_by) VALUES(?,'PAYMENT',?,'VENDOR_PAYMENT',?,?,?)")
        ->execute([$supplierId, $amount, $pid, $no . ' · ' . $method . ($ref ? ' · ' . $ref : ''), $uid]);
    if ($purchaseId) pm_refresh_payment_status($purchaseId);
    return $pid;
}

function pm_reverse_payment(int $paymentId, string $reason): void {
    $uid = (int)current_user()['id'];
    if (trim($reason) === '') throw new Exception('A reason is required to reverse a payment.');
    db()->beginTransaction();
    try {
        $st = db()->prepare('SELECT * FROM vendor_payments WHERE id=? FOR UPDATE');
        $st->execute([$paymentId]);
        $p = $st->fetch();
        if (!$p) throw new Exception('Payment not found.');
        if ($p['status'] !== 'ACTIVE') throw new Exception('This payment is already reversed.');
        db()->prepare("UPDATE vendor_payments SET status='REVERSED',reversed_by=?,reversed_at=NOW(),reverse_reason=? WHERE id=?")->execute([$uid, mb_substr(trim($reason), 0, 255), $paymentId]);
        db()->prepare("INSERT INTO supplier_ledger(supplier_id,txn_type,credit,reference_type,reference_id,remarks,created_by) VALUES(?,'ADJUSTMENT',?,'VENDOR_PAYMENT_REVERSAL',?,?,?)")
            ->execute([$p['supplier_id'], $p['amount'], $paymentId, 'Reversal of ' . $p['payment_no'], $uid]);
        if ($p['purchase_id']) pm_refresh_payment_status((int)$p['purchase_id']);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    audit('VENDOR_PAYMENT_REVERSE', 'vendor_payment', $paymentId, ['reason' => $reason]);
}

// ---------- bill workflow ----------
function pm_load_bill(int $id, bool $lock = false): ?array {
    $st = db()->prepare('SELECT pu.*, s.party_code, s.party_name, s.city vendor_city, s.area vendor_area, s.gstin vendor_gstin, s.state_code vendor_state_code FROM purchases pu JOIN suppliers s ON s.id=pu.supplier_id WHERE pu.id=?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function pm_bill_lines(int $purchaseId): array {
    $st = db()->prepare('SELECT * FROM purchase_bill_lines WHERE purchase_id=? ORDER BY line_no');
    $st->execute([$purchaseId]);
    return $st->fetchAll();
}

/** DRAFT -> SUBMITTED. Re-validates everything that approval and receipt will rely on. */
function pm_submit_bill(int $id): void {
    db()->beginTransaction();
    try {
        $b = pm_load_bill($id, true);
        if (!$b || $b['entry_mode'] !== 'MODULE') throw new Exception('Bill not found.');
        if ($b['bill_status'] !== 'DRAFT') throw new Exception('Only a draft can be submitted.');
        if (!pm_bill_lines($id)) throw new Exception('Add at least one product line before submitting.');
        if (trim((string)$b['invoice_no']) === '') throw new Exception('The vendor invoice number is required to submit.');
        if (!$b['gst_type']) throw new Exception('Set the Company State Code in Settings and the vendor State Code on the vendor, so GST type (CGST+SGST or IGST) can be decided.');
        if ($b['grand_total'] <= 0) throw new Exception('The invoice total must be greater than zero.');
        db()->prepare("UPDATE purchases SET bill_status='SUBMITTED',updated_at=NOW() WHERE id=?")->execute([$id]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    audit('PURCHASE_BILL_SUBMIT', 'purchase', $id);
}

/** SUBMITTED -> APPROVED: books the payable in the supplier ledger and records any payment entered with the bill. */
function pm_approve_bill(int $id): void {
    $uid = (int)current_user()['id'];
    db()->beginTransaction();
    try {
        $b = pm_load_bill($id, true);
        if (!$b || $b['entry_mode'] !== 'MODULE') throw new Exception('Bill not found.');
        if ($b['bill_status'] !== 'SUBMITTED') throw new Exception('Only a submitted bill can be approved.');
        db()->prepare("UPDATE purchases SET bill_status='APPROVED',approved_by=?,approved_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$uid, $id]);
        db()->prepare("INSERT INTO supplier_ledger(supplier_id,txn_type,credit,reference_type,reference_id,remarks,created_by) VALUES(?,'PURCHASE',?,'PURCHASE_BILL',?,?,?)")
            ->execute([$b['supplier_id'], $b['grand_total'], $id, $b['purchase_no'] . ' · ' . $b['invoice_no'], $uid]);
        if ((float)$b['entry_pay_amount'] > 0) {
            pm_insert_payment((int)$b['supplier_id'], $id, (float)$b['entry_pay_amount'], (string)($b['entry_pay_method'] ?: 'BANK'), (string)$b['entry_pay_ref'], substr((string)$b['invoice_date'], 0, 10), 'Paid with bill entry');
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    audit('PURCHASE_BILL_APPROVE', 'purchase', $id);
}

/** Cancels a bill that has no stock received and no active payment. Nothing is deleted; the payable is reversed. */
function pm_cancel_bill(int $id, string $reason): void {
    $uid = (int)current_user()['id'];
    if (trim($reason) === '') throw new Exception('A reason is required to cancel a bill.');
    db()->beginTransaction();
    try {
        $b = pm_load_bill($id, true);
        if (!$b || $b['entry_mode'] !== 'MODULE') throw new Exception('Bill not found.');
        if ($b['bill_status'] === 'CANCELLED') throw new Exception('This bill is already cancelled.');
        $st = db()->prepare('SELECT COUNT(*) FROM goods_receipts WHERE purchase_id=?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) throw new Exception('Goods have already been received against this bill. Use a Purchase Return instead of cancelling.');
        $st = db()->prepare("SELECT COUNT(*) FROM vendor_payments WHERE purchase_id=? AND status='ACTIVE'");
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) throw new Exception('Reverse the payments of this bill first, then cancel it.');
        if ($b['bill_status'] === 'APPROVED') {
            db()->prepare("INSERT INTO supplier_ledger(supplier_id,txn_type,debit,reference_type,reference_id,remarks,created_by) VALUES(?,'ADJUSTMENT',?,'PURCHASE_BILL_CANCEL',?,?,?)")
                ->execute([$b['supplier_id'], $b['grand_total'], $id, 'Cancelled ' . $b['purchase_no'], $uid]);
        }
        // The invoice key is released so the vendor's invoice number can be entered again correctly.
        db()->prepare("UPDATE purchases SET bill_status='CANCELLED',cancelled_by=?,cancelled_at=NOW(),cancel_reason=?,invoice_key=NULL,updated_at=NOW() WHERE id=?")
            ->execute([$uid, mb_substr(trim($reason), 0, 255), $id]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    audit('PURCHASE_BILL_CANCEL', 'purchase', $id, ['reason' => $reason]);
}

// ---------- goods receipt: the only code path that adds stock for a module bill ----------
function pm_line_pending(array $l): float {
    return max(0.0, (float)$l['quantity'] - (float)$l['received_qty'] - (float)$l['damaged_qty'] - (float)$l['rejected_qty']);
}

/**
 * $rows: [bill_line_id => ['acc'=>float,'dam'=>float,'rej'=>float,'note'=>string]]
 * Idempotent through $token (unique per rendered form) plus a row lock on the bill.
 * Accepted quantity becomes stock (batch + balance + ledger + one serial per piece when serial-tracked).
 * Damaged and rejected quantity are recorded but never become sellable stock.
 */
function pm_post_receipt(int $purchaseId, int $locationId, string $date, array $rows, string $remarks, bool $shortClose, string $token): array {
    $uid = (int)current_user()['id'];
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new Exception('This receipt form has expired. Reload the page and try again.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) throw new Exception('The received date is invalid or in the future.');
    if (!is_super_admin() && !can_access_location($locationId)) throw new Exception('You can receive only into your assigned locations.');
    db()->beginTransaction();
    try {
        $b = pm_load_bill($purchaseId, true);
        if (!$b || $b['entry_mode'] !== 'MODULE') throw new Exception('Bill not found.');
        if ($b['bill_status'] !== 'APPROVED') throw new Exception('Goods can be received only against an approved bill.');
        $dup = db()->prepare('SELECT receipt_no FROM goods_receipts WHERE receipt_token=?');
        $dup->execute([$token]);
        if ($no = $dup->fetchColumn()) throw new Exception('This receipt was already posted as ' . $no . '. Stock was not added again.');
        if ($b['receipt_status'] === 'RECEIVED') throw new Exception('This bill is already fully received.');
        $loc = db()->prepare('SELECT 1 FROM locations WHERE id=? AND active=1');
        $loc->execute([$locationId]);
        if (!$loc->fetchColumn()) throw new Exception('Choose an active destination location.');

        $st = db()->prepare('SELECT * FROM purchase_bill_lines WHERE purchase_id=? ORDER BY line_no FOR UPDATE');
        $st->execute([$purchaseId]);
        $lines = [];
        foreach ($st->fetchAll() as $l) $lines[(int)$l['id']] = $l;

        $plan = [];
        foreach ($rows as $lineId => $r) {
            $lineId = (int)$lineId;
            if (!isset($lines[$lineId])) throw new Exception('A receipt line does not belong to this bill.');
            $acc = round((float)($r['acc'] ?? 0), 3); $dam = round((float)($r['dam'] ?? 0), 3); $rej = round((float)($r['rej'] ?? 0), 3);
            if ($acc < 0 || $dam < 0 || $rej < 0) throw new Exception('Quantities cannot be negative.');
            if ($acc + $dam + $rej <= 0) continue;
            $l = $lines[$lineId];
            $pending = pm_line_pending($l);
            $note = trim((string)($r['note'] ?? ''));
            if ($acc + $dam + $rej > $pending + 0.0005 && $note === '') throw new Exception($l['sku_snap'] . ': more than the pending quantity (' . $pending . ') was entered. Write a remark to record the excess.');
            $pst = db()->prepare('SELECT serial_tracking,active FROM products WHERE id=? FOR UPDATE');
            $pst->execute([$l['product_id']]);
            $prod = $pst->fetch();
            if (!$prod || !(int)$prod['active']) throw new Exception($l['sku_snap'] . ': the product is missing or inactive.');
            $serial = (int)$prod['serial_tracking'] === 1;
            if ($serial && abs($acc - round($acc)) > 0.0001) throw new Exception($l['sku_snap'] . ': garments tracked piece by piece need a whole-piece accepted quantity.');
            $plan[$lineId] = ['acc' => $acc, 'dam' => $dam, 'rej' => $rej, 'note' => $note, 'serial' => $serial];
        }
        if (!$plan) throw new Exception('Enter at least one received, damaged or rejected quantity.');

        $receiptNo = pm_unique_no('GRN', 'goods_receipts', 'receipt_no');
        db()->prepare('INSERT INTO goods_receipts(receipt_no,purchase_id,location_id,received_date,remarks,short_closed,receipt_token,received_by) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([$receiptNo, $purchaseId, $locationId, $date, $remarks ?: null, $shortClose ? 1 : 0, $token, $uid]);
        $receiptId = (int)db()->lastInsertId();

        $serialCount = 0; $batchIds = [];
        $insBatch = db()->prepare('INSERT INTO batches(batch_no,batch_barcode,product_id,supplier_id,purchase_id,purchase_invoice_no,purchased_at,cost_price,cost_code,qty_received,qty_available) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $chkBatch = db()->prepare('SELECT 1 FROM batches WHERE product_id=? AND batch_no=?');
        $chkSerial = db()->prepare('SELECT 1 FROM product_serials WHERE serial_barcode=?');
        foreach ($plan as $lineId => $p) {
            $l = $lines[$lineId];
            $batchId = null;
            if ($p['acc'] > 0) {
                $batchNo = trim((string)$l['lot_no']) !== '' ? trim((string)$l['lot_no']) : 'LOT-' . $receiptNo;
                $chkBatch->execute([$l['product_id'], $batchNo]);
                if ($chkBatch->fetchColumn()) $batchNo .= '-' . $receiptId;
                // Batch cost is the vendor's net rate after discount and before GST (GST is claimed as input credit).
                $unitCost = (float)$l['quantity'] > 0 ? pm_money($l['taxable_value'] / $l['quantity']) : pm_money($l['rate']);
                $skuKey = preg_replace('/[^A-Z0-9]/', '', strtoupper($l['sku_snap']));
                $batchBarcode = 'PFB-' . $skuKey . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $insBatch->execute([$batchNo, $batchBarcode, $l['product_id'], $b['supplier_id'], $purchaseId, $b['invoice_no'], $date, $unitCost, 'BC-' . strtoupper(bin2hex(random_bytes(4))), $p['acc'], $p['acc']]);
                $batchId = (int)db()->lastInsertId();
                $batchIds[] = $batchId;
                db()->prepare('INSERT INTO purchase_items(purchase_id,product_id,batch_id,location_id,quantity,cost_price) VALUES(?,?,?,?,?,?)')
                    ->execute([$purchaseId, $l['product_id'], $batchId, $locationId, $p['acc'], $unitCost]);
                db()->prepare('INSERT INTO inventory_balances(product_id,batch_id,location_id,qty) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)')
                    ->execute([$l['product_id'], $batchId, $locationId, $p['acc']]);
                db()->prepare("INSERT INTO stock_ledger(txn_type,product_id,batch_id,location_id,qty_in,reference_type,reference_id,unit_rate,remarks,created_by) VALUES('PURCHASE',?,?,?,?,'GOODS_RECEIPT',?,?,?,?)")
                    ->execute([$l['product_id'], $batchId, $locationId, $p['acc'], $receiptId, $unitCost, $b['invoice_no'] . ' · ' . $receiptNo, $uid]);
                db()->prepare('UPDATE products SET purchase_cost=? WHERE id=?')->execute([$unitCost, $l['product_id']]);
                if ($p['serial']) {
                    $ins = db()->prepare("INSERT INTO product_serials(serial_barcode,product_id,batch_id,location_id,status) VALUES(?,?,?,?,'AVAILABLE')");
                    for ($n = 1; $n <= (int)round($p['acc']); $n++) {
                        do {
                            $code = 'PFS-' . $skuKey . '-' . $batchId . '-' . str_pad((string)$n, 5, '0', STR_PAD_LEFT) . '-' . strtoupper(bin2hex(random_bytes(1)));
                            $chkSerial->execute([$code]);
                        } while ($chkSerial->fetchColumn());
                        $ins->execute([$code, $l['product_id'], $batchId, $locationId]);
                        $serialCount++;
                    }
                }
            }
            db()->prepare('INSERT INTO goods_receipt_items(receipt_id,bill_line_id,product_id,batch_id,accepted_qty,damaged_qty,rejected_qty,remarks) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([$receiptId, $lineId, $l['product_id'], $batchId, $p['acc'], $p['dam'], $p['rej'], $p['note'] ?: null]);
            db()->prepare('UPDATE purchase_bill_lines SET received_qty=received_qty+?,damaged_qty=damaged_qty+?,rejected_qty=rejected_qty+? WHERE id=?')
                ->execute([$p['acc'], $p['dam'], $p['rej'], $lineId]);
        }

        // Receipt status: RECEIVED when nothing is pending, or when the receiver closes the bill short.
        $st = db()->prepare('SELECT quantity,received_qty,damaged_qty,rejected_qty FROM purchase_bill_lines WHERE purchase_id=?');
        $st->execute([$purchaseId]);
        $pending = 0.0; $any = false;
        foreach ($st->fetchAll() as $l) { $pending += pm_line_pending($l); if ($l['received_qty'] + $l['damaged_qty'] + $l['rejected_qty'] > 0) $any = true; }
        $status = ($pending <= 0.0005 || $shortClose) ? 'RECEIVED' : ($any ? 'PARTIAL' : 'PENDING');
        db()->prepare('UPDATE purchases SET receipt_status=?,received_date=?,short_closed=?,updated_at=NOW() WHERE id=?')
            ->execute([$status, $date, ($shortClose && $pending > 0.0005) ? 1 : (int)$b['short_closed'], $purchaseId]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    audit('GOODS_RECEIPT_POST', 'goods_receipt', $receiptId, ['receipt_no' => $receiptNo, 'purchase_id' => $purchaseId, 'status' => $status, 'serials' => $serialCount]);
    publish_event('GOODS_RECEIPT', 'Goods received', $receiptNo . ' · ' . $b['invoice_no'], ['super_admin'], 'goods_receipt', $receiptId, null);
    return ['receipt_id' => $receiptId, 'receipt_no' => $receiptNo, 'status' => $status, 'serials' => $serialCount, 'batch_ids' => $batchIds];
}

/** Per-vendor purchase total and what is still owed (supplier ledger = every bill and payment, old and new). */
function pm_vendor_stats(int $supplierId): array {
    $st = db()->prepare("SELECT COUNT(*) bills, COALESCE(SUM(grand_total),0) total FROM purchases WHERE supplier_id=? AND bill_status<>'CANCELLED' AND bill_status<>'DRAFT'");
    $st->execute([$supplierId]);
    $a = $st->fetch();
    $st = db()->prepare('SELECT COALESCE(SUM(credit-debit),0) FROM supplier_ledger WHERE supplier_id=?');
    $st->execute([$supplierId]);
    return ['bills' => (int)$a['bills'], 'total' => pm_money($a['total']), 'outstanding' => pm_money($st->fetchColumn())];
}
