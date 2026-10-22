<?php
// Builds a real .xlsx (Excel) file from rows, without any library. First row = header.
require_once __DIR__ . '/zipwriter.php';
function xlsx_col(int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; }
function xlsx_clean(string $v): string { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v), ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function xlsx_download(string $filename, array $rows, string $sheet = 'Report'): void {
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="30" width="18" customWidth="1"/></cols><sheetData>';
    foreach ($rows as $r => $row) {
        $x .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $c => $v) {
            $ref = xlsx_col($c) . ($r + 1); $st = $r === 0 ? ' s="1"' : '';
            if ($r > 0 && (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d{1,12}(\.\d+)?$/', $v) && !preg_match('/^0\d/', $v)))) $x .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            else $x .= '<c r="' . $ref . '" t="inlineStr"' . $st . '><is><t xml:space="preserve">' . xlsx_clean((string)$v) . '</t></is></c>';
        }
        $x .= '</row>';
    }
    $x .= '</sheetData></worksheet>';
    $sheet = strtr(mb_substr($sheet, 0, 31), [chr(92) => ' ', '/' => ' ', '?' => ' ', '*' => ' ', '[' => ' ', ']' => ' ', ':' => ' ']);
    $tmp = tempnam(sys_get_temp_dir(), 'xl'); $z = new SimpleZip($tmp);
    $z->addString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $z->addString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . xlsx_clean($sheet) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF1F8"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
    $z->addString('xl/worksheets/sheet1.xml', $x);
    $z->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    header('Content-Length: ' . filesize($tmp)); header('Cache-Control: no-store');
    readfile($tmp); @unlink($tmp); exit;
}
