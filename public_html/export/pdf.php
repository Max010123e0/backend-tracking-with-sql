<?php
// PDF Export  –  /export/pdf.php?report=traffic|performance|errors&days=30
require_once __DIR__ . '/../auth.php';
requireRole('superadmin', 'analyst');

require_once '/var/www/collector.maxk.site/db_config.php';
require_once '/var/www/reporting.maxk.site/vendor/autoload.php';

$validReports = ['traffic', 'performance', 'errors'];
$report = $_GET['report'] ?? '';
if (!in_array($report, $validReports, true)) {
    http_response_code(400);
    exit('Invalid report type. Use: traffic, performance, or errors.');
}
requireSection($report);

$days = max(7, min(90, (int)($_GET['days'] ?? 30)));
$pdo  = getDbConnection();

// ── Fetch data based on report type ───────────────────────────────────────
$data = [];

if ($report === 'traffic') {
    // Summary
    $sum = $pdo->prepare(
        "SELECT COUNT(*) AS total_pv,
                COUNT(DISTINCT session_id) AS sessions,
                COUNT(DISTINCT url) AS unique_pages
         FROM pageviews
         WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"
    );
    $sum->execute([$days]);
    $data['summary'] = $sum->fetch(PDO::FETCH_ASSOC);

    // Top pages
    $tp = $pdo->prepare(
        "SELECT url, COUNT(*) AS views,
                COUNT(DISTINCT session_id) AS uniq_sessions
         FROM pageviews
         WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY url ORDER BY views DESC LIMIT 15"
    );
    $tp->execute([$days]);
    $data['top_pages'] = $tp->fetchAll(PDO::FETCH_ASSOC);

    // Referrers
    $ref = $pdo->prepare(
        "SELECT referrer, COUNT(*) AS visits
         FROM pageviews
         WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
           AND referrer IS NOT NULL AND referrer != ''
         GROUP BY referrer ORDER BY visits DESC LIMIT 10"
    );
    $ref->execute([$days]);
    $data['referrers'] = $ref->fetchAll(PDO::FETCH_ASSOC);

    // Daily trend
    $daily = $pdo->prepare(
        "SELECT DATE(timestamp) AS day, COUNT(*) AS views
         FROM pageviews
         WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY day ORDER BY day ASC"
    );
    $daily->execute([$days]);
    $data['daily'] = $daily->fetchAll(PDO::FETCH_ASSOC);

} elseif ($report === 'performance') {
    // Summary
    $sum = $pdo->prepare(
        "SELECT COUNT(*) AS samples,
                ROUND(AVG(CAST(JSON_UNQUOTE(lcp) AS DECIMAL(12,2))),0) AS avg_lcp,
                ROUND(AVG(CAST(JSON_UNQUOTE(cls) AS DECIMAL(10,4))),4) AS avg_cls,
                ROUND(AVG(CAST(JSON_UNQUOTE(inp) AS DECIMAL(12,2))),0) AS avg_inp
         FROM vitals
         WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"
    );
    $sum->execute([$days]);
    $data['summary'] = $sum->fetch(PDO::FETCH_ASSOC);

    // Per-page vitals
    $vt = $pdo->prepare(
        "SELECT url, COUNT(*) AS samples,
                ROUND(AVG(CAST(JSON_UNQUOTE(lcp) AS DECIMAL(12,2))),0) AS avg_lcp,
                ROUND(AVG(CAST(JSON_UNQUOTE(cls) AS DECIMAL(10,4))),4) AS avg_cls,
                ROUND(AVG(CAST(JSON_UNQUOTE(inp) AS DECIMAL(12,2))),0) AS avg_inp
         FROM vitals
         WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
           AND lcp IS NOT NULL
         GROUP BY url ORDER BY avg_lcp DESC LIMIT 20"
    );
    $vt->execute([$days]);
    $data['vitals'] = $vt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($report === 'errors') {
    // Summary
    $sum = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                COUNT(DISTINCT session_id) AS affected_sessions,
                COUNT(DISTINCT JSON_UNQUOTE(error_type)) AS distinct_types
         FROM errors
         WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"
    );
    $sum->execute([$days]);
    $data['summary'] = $sum->fetch(PDO::FETCH_ASSOC);

    // Top errors
    $te = $pdo->prepare(
        "SELECT JSON_UNQUOTE(error_type) AS type,
                JSON_UNQUOTE(error_message) AS message,
                JSON_UNQUOTE(error_source) AS source,
                COUNT(*) AS occurrences,
                COUNT(DISTINCT session_id) AS sessions
         FROM errors
         WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY error_type, error_message, error_source
         ORDER BY occurrences DESC LIMIT 20"
    );
    $te->execute([$days]);
    $data['top_errors'] = $te->fetchAll(PDO::FETCH_ASSOC);

    // Daily
    $daily = $pdo->prepare(
        "SELECT DATE(timestamp) AS day, COUNT(*) AS count
         FROM errors
         WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY day ORDER BY day ASC"
    );
    $daily->execute([$days]);
    $data['daily'] = $daily->fetchAll(PDO::FETCH_ASSOC);
}

// ── Helpers ───────────────────────────────────────────────────────────────
// Safe string truncate (avoids mb_strimwidth which requires php-mbstring)
$trunc = function(string $s, int $max, string $suffix = '…') : string {
    if (strlen($s) <= $max) return $s;
    return substr($s, 0, $max - strlen($suffix)) . $suffix;
};

$lcpLabel = function($v) {
    if ($v <= 0)   return ['—',           [200,200,200]];
    if ($v < 2500) return ['Good',        [22,163,74]];
    if ($v < 4000) return ['Needs Work',  [217,119,6]];
    return             ['Poor',       [220,38,38]];
};
$clsLabel = function($v) {
    if ($v < 0)    return ['—',           [200,200,200]];
    if ($v < 0.1)  return ['Good',        [22,163,74]];
    if ($v < 0.25) return ['Needs Work',  [217,119,6]];
    return             ['Poor',       [220,38,38]];
};
$inpLabel = function($v) {
    if ($v <= 0)   return ['—',           [200,200,200]];
    if ($v < 200)  return ['Good',        [22,163,74]];
    if ($v < 500)  return ['Needs Work',  [217,119,6]];
    return             ['Poor',       [220,38,38]];
};

$reportTitles = [
    'traffic'     => 'Traffic Report',
    'performance' => 'Performance Report',
    'errors'      => 'Error Report',
];

// ── Build PDF ─────────────────────────────────────────────────────────────
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Analytics Reporting');
$pdf->SetAuthor(currentUser());
$pdf->SetTitle($reportTitles[$report] . ' – Last ' . $days . ' days');
$pdf->SetSubject('Analytics export from reporting.maxk.site');

$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(14, 14, 14);
$pdf->SetAutoPageBreak(true, 14);
$pdf->AddPage();

// ── Cover / Title ─────────────────────────────────────────────────────────
$accentHex = ['traffic'=>'#2563eb','performance'=>'#059669','errors'=>'#dc2626'];
$accent = $accentHex[$report];

$pdf->SetFillColor(17, 24, 39);
$pdf->Rect(0, 0, 210, 36, 'F');
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 18);
$pdf->SetXY(14, 10);
$pdf->Cell(0, 10, $reportTitles[$report], 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);
$pdf->SetXY(14, 22);
$pdf->Cell(0, 6, 'Last ' . $days . ' days  ·  Generated ' . date('M j, Y H:i') . '  ·  ' . currentUser(), 0, 1, 'L');
$pdf->SetTextColor(31, 41, 55);
$pdf->SetY(44);

// ── Helper: section heading ───────────────────────────────────────────────
$heading = function(string $title) use ($pdf) {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(243, 244, 246);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->Cell(0, 8, $title, 0, 1, 'L', true);
    $pdf->Ln(2);
};

// ── Helper: stat row ─────────────────────────────────────────────────────
$statRow = function(array $stats) use ($pdf) {
    $w = 182 / count($stats);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor(239, 246, 255);
    foreach ($stats as $label => $value) {
        $x = $pdf->GetX(); $y = $pdf->GetY();
        $pdf->MultiCell($w, 16, '', 1, 'L', true);
        $pdf->SetXY($x + 2, $y + 2);
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->SetTextColor(37, 99, 235);
        $pdf->Cell($w - 4, 6, (string)$value, 0, 1, 'L');
        $pdf->SetXY($x + 2, $y + 9);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell($w - 4, 5, $label, 0, 0, 'L');
        $pdf->SetXY($x + $w, $y);
    }
    $pdf->Ln(20);
    $pdf->SetTextColor(31, 41, 55);
};

// ── Helper: simple table ─────────────────────────────────────────────────
$table = function(array $headers, array $rows, array $widths, string $title = '') use ($pdf, $heading) {
    if ($title !== '') $heading($title);
    // Header row
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(31, 41, 55);
    $pdf->SetTextColor(255, 255, 255);
    foreach ($headers as $i => $h) {
        $pdf->Cell($widths[$i], 7, $h, 0, 0, 'L', true);
    }
    $pdf->Ln();
    // Data rows
    $pdf->SetFont('helvetica', '', 8);
    $odd = true;
    foreach ($rows as $row) {
        $pdf->SetFillColor($odd ? 255 : 249, $odd ? 255 : 250, $odd ? 255 : 251);
        $pdf->SetTextColor(31, 41, 55);
        $vals = array_values($row);
        foreach ($vals as $i => $v) {
            $w = $widths[$i] ?? 30;
            $cell_val = (string)$v;
            if (strlen($cell_val) > 55) $cell_val = substr($cell_val, 0, 54) . '…';
            $pdf->Cell($w, 6, $cell_val, 0, 0, 'L', true);
        }
        $pdf->Ln();
        $odd = !$odd;
    }
    $pdf->Ln(4);
};

// ═══════════════════════════════════════════════════════════════════════════
// TRAFFIC
// ═══════════════════════════════════════════════════════════════════════════
if ($report === 'traffic') {
    $s = $data['summary'];
    $heading('Summary');
    $statRow([
        'Total Pageviews' => number_format((int)$s['total_pv']),
        'Sessions'        => number_format((int)$s['sessions']),
        'Unique Pages'    => number_format((int)$s['unique_pages']),
    ]);

    // Daily trend table
    if (!empty($data['daily'])) {
        $table(
            ['Date', 'Pageviews'],
            array_map(fn($r) => ['Date' => $r['day'], 'Views' => $r['views']], $data['daily']),
            [100, 82],
            'Daily Pageviews'
        );
    }

    // Top pages
    if (!empty($data['top_pages'])) {
        $table(
            ['URL', 'Views', 'Sessions'],
            array_map(fn($r) => [
                'url'  => $trunc(parse_url($r['url'], PHP_URL_PATH) ?: $r['url'], 50),
                'views'=> $r['views'],
                'sess' => $r['uniq_sessions'],
            ], $data['top_pages']),
            [122, 30, 30],
            'Top Pages'
        );
    }

    // Referrers
    if (!empty($data['referrers'])) {
        $table(
            ['Referrer', 'Visits'],
            array_map(fn($r) => ['ref' => $trunc($r['referrer'], 55), 'v' => $r['visits']], $data['referrers']),
            [152, 30],
            'Top Referrers'
        );
    }

// ═══════════════════════════════════════════════════════════════════════════
// PERFORMANCE
// ═══════════════════════════════════════════════════════════════════════════
} elseif ($report === 'performance') {
    $s = $data['summary'];
    [$lcpL] = $lcpLabel((int)$s['avg_lcp']);
    [$clsL] = $clsLabel((float)$s['avg_cls']);
    [$inpL] = $inpLabel((int)$s['avg_inp']);

    $heading('Summary');
    $statRow([
        'Avg LCP (' . $lcpL . ')' => ($s['avg_lcp'] > 0 ? $s['avg_lcp'] . 'ms' : '—'),
        'Avg CLS (' . $clsL . ')' => ($s['avg_cls'] >= 0 ? $s['avg_cls'] : '—'),
        'Avg INP (' . $inpL . ')' => ($s['avg_inp'] > 0 ? $s['avg_inp'] . 'ms' : '—'),
        'Samples'                 => number_format((int)$s['samples']),
    ]);

    // Per-page vitals table with colored rating
    if (!empty($data['vitals'])) {
        $heading('Core Web Vitals Per Page');
        $headers = ['URL', 'Samples', 'LCP (ms)', 'LCP Rating', 'CLS', 'CLS Rating', 'INP (ms)', 'INP Rating'];
        $widths  = [54, 16, 18, 20, 14, 20, 18, 22];

        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetFillColor(31, 41, 55);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($headers as $i => $h) {
            $pdf->Cell($widths[$i], 7, $h, 0, 0, 'L', true);
        }
        $pdf->Ln();

        $odd = true;
        foreach ($data['vitals'] as $r) {
            $bg = $odd ? [255,255,255] : [249,250,251];
            [$lcpTxt, $lcpRgb] = $lcpLabel((int)$r['avg_lcp']);
            [$clsTxt, $clsRgb] = $clsLabel((float)$r['avg_cls']);
            [$inpTxt, $inpRgb] = $inpLabel((int)$r['avg_inp']);

            $path = $trunc(parse_url($r['url'], PHP_URL_PATH) ?: $r['url'], 40);
            $cells = [
                [$path,                 $widths[0], $bg,     [31,41,55]],
                [$r['samples'],         $widths[1], $bg,     [31,41,55]],
                [$r['avg_lcp'].'ms',    $widths[2], $bg,     [31,41,55]],
                [$lcpTxt,               $widths[3], $lcpRgb, [255,255,255]],
                [$r['avg_cls'],         $widths[4], $bg,     [31,41,55]],
                [$clsTxt,               $widths[5], $clsRgb, [255,255,255]],
                [$r['avg_inp'].'ms',    $widths[6], $bg,     [31,41,55]],
                [$inpTxt,               $widths[7], $inpRgb, [255,255,255]],
            ];
            $pdf->SetFont('helvetica', '', 7);
            foreach ($cells as [$val, $w, $fill, $text]) {
                $pdf->SetFillColor(...$fill);
                $pdf->SetTextColor(...$text);
                $pdf->Cell($w, 6, (string)$val, 0, 0, 'L', true);
            }
            $pdf->Ln();
            $odd = !$odd;
        }
        $pdf->SetTextColor(31, 41, 55);
        $pdf->Ln(4);
    }

// ═══════════════════════════════════════════════════════════════════════════
// ERRORS
// ═══════════════════════════════════════════════════════════════════════════
} elseif ($report === 'errors') {
    $s = $data['summary'];
    $heading('Summary');
    $statRow([
        'Total Errors'      => number_format((int)$s['total']),
        'Affected Sessions' => number_format((int)$s['affected_sessions']),
        'Distinct Types'    => number_format((int)$s['distinct_types']),
    ]);

    if (!empty($data['daily'])) {
        $table(
            ['Date', 'Errors'],
            array_map(fn($r) => ['Date' => $r['day'], 'Count' => $r['count']], $data['daily']),
            [100, 82],
            'Daily Error Counts'
        );
    }

    if (!empty($data['top_errors'])) {
        $table(
            ['Type', 'Message', 'Source', 'Count', 'Sessions'],
            array_map(fn($r) => [
                'type'    => $trunc($r['type'] ?? '', 28),
                'message' => $trunc($r['message'] ?? '', 50),
                'source'  => $trunc($r['source'] ?? '', 30),
                'count'   => $r['occurrences'],
                'sess'    => $r['sessions'],
            ], $data['top_errors']),
            [28, 80, 42, 16, 16],
            'Top Errors'
        );
    }
}

// ── Footer on last page ───────────────────────────────────────────────────
$pdf->SetY(-18);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(156, 163, 175);
$pdf->Cell(0, 6, 'analytics.reporting.maxk.site  ·  exported by ' . currentUser() . '  ·  ' . date('Y-m-d H:i:s'), 0, 0, 'C');

// ── Save PDF to disk & store record ──────────────────────────────────────
$token    = bin2hex(random_bytes(16));
$filename = $report . '-report-' . date('Ymd') . '-' . $days . 'days-' . substr($token, 0, 8) . '.pdf';
$savePath = '/var/www/reporting.maxk.site/pdf_storage/' . $filename;

$pdf->Output($savePath, 'F');   // 'F' = write to file, no browser output

$pdo->prepare(
    "INSERT INTO pdf_exports (token, report, days, filename, created_by)
     VALUES (?, ?, ?, ?, ?)"
)->execute([$token, $report, $days, $filename, currentUserId()]);

// Redirect to the permanent serve URL
header('Location: /export/serve.php?token=' . rawurlencode($token));
exit;
