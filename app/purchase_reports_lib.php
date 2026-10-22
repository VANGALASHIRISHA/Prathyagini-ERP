<?php
// Purchase and GST reports. Read-only. Drafts and cancelled bills are never counted.
// Each report returns ['title'=>, 'head'=>[...], 'rows'=>[[...]], 'note'=>] so one page can show, print and export all of them.

function pr_reports(): array {
    return [
        'vendor' => 'Total purchases by vendor',
        'area' => 'Total purchases by vendor area / city',
        'period' => 'Purchases by month and year',
        'invoice' => 'Vendor invoice-wise purchase details',
        'product' => 'Product-wise purchased quantities',
        'gst' => 'GST-wise purchase summary',
        'payment' => 'Paid and unpaid bills',
        'outstanding' => 'Outstanding vendor balances',
        'pending' => 'Goods received versus goods pending',
        'returns' => 'Purchase returns and damaged / rejected goods',
    ];
}

function pr_run(string $key, string $from, string $to): array {
    $pdo = db();
    $base = "pu.bill_status NOT IN ('DRAFT','CANCELLED') AND pu.invoice_date BETWEEN ? AND ?";
    $rng = [$from, $to];
    $gst = '(pu.cgst_total+pu.sgst_total+pu.igst_total)';
    $q = function (string $sql, array $a) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchAll(PDO::FETCH_NUM); };
    $reports = pr_reports();
    $title = $reports[$key] ?? 'Report';
    $note = '';
    switch ($key) {
        case 'vendor':
            $head = ['Vendor code', 'Vendor', 'City', 'Bills', 'Taxable', 'GST', 'Invoice total'];
            $rows = $q("SELECT s.party_code,s.party_name,COALESCE(s.city,''),COUNT(*),SUM(pu.taxable_total),SUM($gst),SUM(pu.grand_total) FROM purchases pu JOIN suppliers s ON s.id=pu.supplier_id WHERE $base GROUP BY s.id ORDER BY SUM(pu.grand_total) DESC", $rng);
            break;
        case 'area':
            $head = ['Area / city', 'Vendors', 'Bills', 'Taxable', 'GST', 'Invoice total'];
            $rows = $q("SELECT COALESCE(NULLIF(TRIM(s.city),''),NULLIF(TRIM(s.area),''),'(not set)') place,COUNT(DISTINCT s.id),COUNT(*),SUM(pu.taxable_total),SUM($gst),SUM(pu.grand_total) FROM purchases pu JOIN suppliers s ON s.id=pu.supplier_id WHERE $base GROUP BY place ORDER BY SUM(pu.grand_total) DESC", $rng);
            break;
        case 'period':
            $head = ['Month', 'Bills', 'Taxable', 'GST', 'Invoice total'];
            $rows = $q("SELECT DATE_FORMAT(pu.invoice_date,'%Y-%m') m,COUNT(*),SUM(pu.taxable_total),SUM($gst),SUM(pu.grand_total) FROM purchases pu WHERE $base GROUP BY m ORDER BY m", $rng);
            $years = $q("SELECT YEAR(pu.invoice_date) y,COUNT(*),SUM(pu.taxable_total),SUM($gst),SUM(pu.grand_total) FROM purchases pu WHERE $base GROUP BY y ORDER BY y", $rng);
            foreach ($years as $y) $rows[] = ['YEAR ' . $y[0], $y[1], $y[2], $y[3], $y[4]];
            break;
        case 'invoice':
            $head = ['ERP ID', 'Vendor', 'Vendor bill', 'Bill date', 'Taxable', 'GST', 'Total', 'Bill', 'Goods'];
            $rows = $q("SELECT pu.purchase_no,s.party_name,pu.invoice_no,pu.invoice_date,pu.taxable_total,$gst,pu.grand_total,pu.bill_status,pu.receipt_status FROM purchases pu JOIN suppliers s ON s.id=pu.supplier_id WHERE $base ORDER BY pu.invoice_date,pu.id", $rng);
            break;
        case 'product':
            $head = ['SKU', 'Product', 'Qty billed', 'Taxable value'];
            $rows = $q("SELECT sku,name,SUM(qty),SUM(val) FROM (
                SELECT l.sku_snap sku,l.name_snap name,l.quantity qty,l.taxable_value val FROM purchase_bill_lines l JOIN purchases pu ON pu.id=l.purchase_id WHERE $base
                UNION ALL
                SELECT p.sku,p.product_name,pi.quantity,pi.quantity*pi.cost_price FROM purchase_items pi JOIN purchases pu ON pu.id=pi.purchase_id JOIN products p ON p.id=pi.product_id WHERE pu.entry_mode='LEGACY' AND $base) t GROUP BY sku,name ORDER BY SUM(qty) DESC", array_merge($rng, $rng));
            $note = 'Includes older purchases posted through Purchase Inward (valued at cost).';
            break;
        case 'gst':
            $head = ['GST rate %', 'Lines', 'Taxable value', 'CGST', 'SGST', 'IGST', 'Total GST'];
            $rows = $q("SELECT l.gst_rate,COUNT(*),SUM(l.taxable_value),SUM(l.cgst_amount),SUM(l.sgst_amount),SUM(l.igst_amount),SUM(l.cgst_amount+l.sgst_amount+l.igst_amount) FROM purchase_bill_lines l JOIN purchases pu ON pu.id=l.purchase_id WHERE $base GROUP BY l.gst_rate ORDER BY l.gst_rate", $rng);
            $note = 'Purchase-module bills only. Freight, packing and other charges are not taxed by this module; add GST on them manually if your vendor charged it.';
            break;
        case 'payment':
            $head = ['ERP ID', 'Vendor', 'Vendor bill', 'Bill date', 'Total', 'Paid', 'Balance', 'Payment status'];
            $rows = $q("SELECT pu.purchase_no,s.party_name,pu.invoice_no,pu.invoice_date,pu.grand_total,pu.amount_paid,pu.grand_total-pu.amount_paid,pu.payment_status FROM purchases pu JOIN suppliers s ON s.id=pu.supplier_id WHERE pu.entry_mode='MODULE' AND $base ORDER BY pu.payment_status,pu.invoice_date", $rng);
            $note = 'Purchase-module bills only. Older purchases track payment per vendor (see Outstanding vendor balances).';
            break;
        case 'outstanding':
            $head = ['Vendor code', 'Vendor', 'City', 'Mobile', 'Total billed', 'Total paid / adjusted', 'Outstanding'];
            $rows = $q("SELECT s.party_code,s.party_name,COALESCE(s.city,''),COALESCE(s.mobile,''),SUM(l.credit),SUM(l.debit),SUM(l.credit-l.debit) FROM suppliers s JOIN supplier_ledger l ON l.supplier_id=s.id GROUP BY s.id HAVING ABS(SUM(l.credit-l.debit))>0.004 ORDER BY SUM(l.credit-l.debit) DESC", []);
            $note = 'As on today, from the supplier ledger (all bills and payments, old and new). The date range does not apply.';
            break;
        case 'pending':
            $head = ['ERP ID', 'Vendor', 'Vendor bill', 'Bill date', 'Billed qty', 'Accepted', 'Damaged', 'Rejected', 'Pending', 'Goods status'];
            $rows = $q("SELECT pu.purchase_no,s.party_name,pu.invoice_no,pu.invoice_date,SUM(l.quantity),SUM(l.received_qty),SUM(l.damaged_qty),SUM(l.rejected_qty),GREATEST(SUM(l.quantity)-SUM(l.received_qty)-SUM(l.damaged_qty)-SUM(l.rejected_qty),0),IF(pu.short_closed=1,'CLOSED SHORT',pu.receipt_status) FROM purchases pu JOIN suppliers s ON s.id=pu.supplier_id JOIN purchase_bill_lines l ON l.purchase_id=pu.id WHERE pu.entry_mode='MODULE' AND $base GROUP BY pu.id ORDER BY pu.invoice_date", $rng);
            break;
        case 'returns':
            $head = ['Type', 'Reference', 'Date', 'Vendor', 'Product', 'Qty / Value', 'Remark'];
            $rows = $q("SELECT 'Damaged' typ,gr.receipt_no,gr.received_date,s.party_name,CONCAT(l.sku_snap,' · ',l.name_snap),i.damaged_qty,COALESCE(i.remarks,'') FROM goods_receipt_items i JOIN goods_receipts gr ON gr.id=i.receipt_id JOIN purchases pu ON pu.id=gr.purchase_id JOIN suppliers s ON s.id=pu.supplier_id JOIN purchase_bill_lines l ON l.id=i.bill_line_id WHERE i.damaged_qty>0 AND gr.received_date BETWEEN ? AND ?
              UNION ALL SELECT 'Rejected',gr.receipt_no,gr.received_date,s.party_name,CONCAT(l.sku_snap,' · ',l.name_snap),i.rejected_qty,COALESCE(i.remarks,'') FROM goods_receipt_items i JOIN goods_receipts gr ON gr.id=i.receipt_id JOIN purchases pu ON pu.id=gr.purchase_id JOIN suppliers s ON s.id=pu.supplier_id JOIN purchase_bill_lines l ON l.id=i.bill_line_id WHERE i.rejected_qty>0 AND gr.received_date BETWEEN ? AND ?
              UNION ALL SELECT 'Purchase return',r.return_no,DATE(r.created_at),s.party_name,'(see return)',r.total_value,r.reason FROM purchase_returns r JOIN suppliers s ON s.id=r.supplier_id WHERE DATE(r.created_at) BETWEEN ? AND ?
              ORDER BY 3", array_merge($rng, $rng, $rng));
            $note = 'Damaged and rejected goods are recorded but never added to stock. Purchase return value is in rupees.';
            break;
        default:
            $head = []; $rows = [];
    }
    return ['title' => $title, 'head' => $head, 'rows' => $rows, 'note' => $note];
}

/** Input-tax register: one row per purchase bill with GST, grouped by vendor GSTIN. */
function pr_gst_register(string $from, string $to): array {
    $st = db()->prepare("SELECT pu.invoice_date,pu.invoice_no,COALESCE(pu.vendor_name_snap,s.party_name),COALESCE(pu.vendor_gstin_snap,s.gstin,''),COALESCE(pu.vendor_state_code_snap,s.state_code,''),pu.gst_type,pu.taxable_total,pu.cgst_total,pu.sgst_total,pu.igst_total,pu.grand_total FROM purchases pu JOIN suppliers s ON s.id=pu.supplier_id WHERE pu.entry_mode='MODULE' AND pu.bill_status NOT IN ('DRAFT','CANCELLED') AND pu.invoice_date BETWEEN ? AND ? ORDER BY pu.invoice_date,pu.id");
    $st->execute([$from, $to]);
    return $st->fetchAll(PDO::FETCH_NUM);
}

function pr_dates(): array {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');
    return [$from, $to];
}

function pr_cell($v): string {
    if (is_numeric($v) && !preg_match('/^0\d/', (string)$v) && strlen((string)$v) < 14) return strpos((string)$v, '.') !== false ? number_format((float)$v, 2) : (string)$v;
    return (string)$v;
}

function pr_export(string $name, array $head, array $rows, string $fmt): void {
    $out = [$head];
    foreach ($rows as $r) $out[] = array_map(fn($v) => is_numeric($v) && !preg_match('/^0\d/', (string)$v) && strlen((string)$v) < 14 ? (float)$v : (string)$v, $r);
    if ($fmt === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '_' . date('Ymd_His') . '.csv"');
        $o = fopen('php://output', 'w');
        foreach ($out as $row) fputcsv($o, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $row));
        exit;
    }
    require_once __DIR__ . '/xlsx.php';
    xlsx_download($name . '_' . date('Ymd_His') . '.xlsx', $out, mb_substr($name, 0, 31));
}
