<?php
// Code 128 (set B) barcode as an SVG, drawn on the server from the saved database value.
function code128_valid(string $text): bool {
    return (bool)preg_match('/^[\x20-\x7E]{1,80}$/', $text);
}

function code128_svg(string $text, int $barHeight = 50): string {
    if (!code128_valid($text)) throw new InvalidArgumentException('Barcode can only contain English letters, numbers and symbols.');
    static $patterns = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];
    $values = [104];
    $sum = 104;
    $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $v = ord($text[$i]) - 32;
        $values[] = $v;
        $sum += $v * ($i + 1);
    }
    $values[] = $sum % 103;
    $values[] = 106;
    // Quiet zone of 10 modules on each side is required by scanners.
    $quiet = 10;
    $x = $quiet;
    $bars = '';
    foreach ($values as $v) {
        $p = $patterns[$v];
        for ($i = 0, $n = strlen($p); $i < $n; $i++) {
            $w = (int)$p[$i];
            if ($i % 2 === 0) $bars .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $barHeight . '"/>';
            $x += $w;
        }
    }
    $width = $x + $quiet;
    return '<svg class="barcode-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $barHeight . '" preserveAspectRatio="none" shape-rendering="crispEdges" role="img" aria-label="' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '"><rect width="' . $width . '" height="' . $barHeight . '" fill="#fff"/><g fill="#000">' . $bars . '</g></svg>';
}
