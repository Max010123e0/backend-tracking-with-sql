<?php
require_once __DIR__ . '/../auth.php';
requireSection('performance');

require_once '/var/www/collector.maxk.site/db_config.php';
$pdo = getDbConnection();
$currentSection = 'performance';

$days = max(7, min(90, (int)($_GET['days'] ?? 30)));

// Vitals per page
$vitalsRows = $pdo->prepare(
    "SELECT url, COUNT(*) AS samples,
            ROUND(AVG(CAST(JSON_UNQUOTE(lcp) AS DECIMAL(12,2))),0) AS avg_lcp,
            ROUND(AVG(CAST(JSON_UNQUOTE(cls) AS DECIMAL(10,4))),4) AS avg_cls,
            ROUND(AVG(CAST(JSON_UNQUOTE(inp) AS DECIMAL(12,2))),0) AS avg_inp
     FROM vitals
     WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
       AND lcp IS NOT NULL
     GROUP BY url ORDER BY avg_lcp DESC"
);
$vitalsRows->execute([$days]);
$vitals = $vitalsRows->fetchAll();

// Chart data: avg LCP per page
$vitalsForChart = $pdo->prepare(
    "SELECT url,
            ROUND(AVG(CAST(JSON_UNQUOTE(lcp) AS DECIMAL(12,2))),0) AS avg_lcp,
            ROUND(AVG(CAST(JSON_UNQUOTE(cls) AS DECIMAL(10,4))),4) AS avg_cls,
            ROUND(AVG(CAST(JSON_UNQUOTE(inp) AS DECIMAL(12,2))),0) AS avg_inp
     FROM vitals
     WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY url ORDER BY avg_lcp DESC LIMIT 8"
);
$vitalsForChart->execute([$days]);
$vc = $vitalsForChart->fetchAll();
$chartLabels = array_map(fn($r) => parse_url($r['url'], PHP_URL_PATH) ?: '/', $vc);
$lcpValues   = array_map(fn($r) => (float)$r['avg_lcp'], $vc);
$clsValues   = array_map(fn($r) => (float)$r['avg_cls'], $vc);
$inpValues   = array_map(fn($r) => (float)$r['avg_inp'], $vc);

// Daily vitals trend
$trendRows = $pdo->prepare(
    "SELECT DATE(timestamp) AS day,
            ROUND(AVG(CAST(JSON_UNQUOTE(lcp) AS DECIMAL(12,2))),0) AS avg_lcp
     FROM vitals
     WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY day ORDER BY day ASC"
);
$trendRows->execute([$days]);
$trend = $trendRows->fetchAll();
$trendLabels = array_column($trend, 'day');
$trendValues = array_map(fn($r) => (float)$r['avg_lcp'], $trend);

// Overall summary
$summary = $pdo->prepare(
    "SELECT COUNT(*) AS samples,
            ROUND(AVG(CAST(JSON_UNQUOTE(lcp) AS DECIMAL(12,2))),0) AS avg_lcp,
            ROUND(AVG(CAST(JSON_UNQUOTE(cls) AS DECIMAL(10,4))),4) AS avg_cls,
            ROUND(AVG(CAST(JSON_UNQUOTE(inp) AS DECIMAL(12,2))),0) AS avg_inp
     FROM vitals
     WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"
);
$summary->execute([$days]);
$sum = $summary->fetch();

// Handle save
$saveMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report'])) {
    requireRole('superadmin','analyst');
    $title   = trim($_POST['report_title'] ?? '');
    $comment = trim($_POST['analyst_comment'] ?? '');
    if ($title !== '') {
        $slug = 'perf-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $pdo->prepare(
            "INSERT INTO saved_reports (title, slug, category, filters, analyst_comment, created_by)
             VALUES (?,?,'performance',?,?,?)"
        )->execute([$title, $slug, json_encode(['days'=>$days]), $comment?:null, currentUserId()]);
        $saveMsg = 'Report saved.';
    }
}

// Helpers
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$lcpClass = function($v) {
    if ($v <= 0)    return ['—',       'color:#9ca3af'];
    if ($v < 2500)  return ['Good',    'color:#166534;font-weight:700'];
    if ($v < 4000)  return ['Needs work','color:#92400e;font-weight:700'];
    return              ['Poor',    'color:#b91c1c;font-weight:700'];
};
$clsClass = function($v) {
    if ($v < 0)     return ['—',       'color:#9ca3af'];
    if ($v < 0.1)   return ['Good',    'color:#166534;font-weight:700'];
    if ($v < 0.25)  return ['Needs work','color:#92400e;font-weight:700'];
    return              ['Poor',    'color:#b91c1c;font-weight:700'];
};
$inpClass = function($v) {
    if ($v <= 0)    return ['—',       'color:#9ca3af'];
    if ($v < 200)   return ['Good',    'color:#166534;font-weight:700'];
    if ($v < 500)   return ['Needs work','color:#92400e;font-weight:700'];
    return              ['Poor',    'color:#b91c1c;font-weight:700'];
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Performance Report – Analytics Reporting</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #1f2937; }
    .topbar { background: #111827; color: #fff; padding: 12px 20px;
              display: flex; justify-content: space-between; align-items: center; }
    .topbar h1 { margin: 0; font-size: 1.1rem; }
    .topbar nav { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; }
    .topbar a { color: #e5e7eb; text-decoration: none; font-weight: 600; font-size: .85rem; }
    .topbar a:hover, .topbar a.active { color: #fff; text-decoration: underline; }
    .user { color: #9ca3af; font-size: .82rem; }
    .role-badge { display:inline-block; padding:1px 6px; border-radius:4px;
                  font-size:.7rem; font-weight:700; margin-left:4px;
                  background:#374151; color:#e5e7eb; }
    .container { max-width: 1200px; margin: 24px auto; padding: 0 16px; }
    .card { background: #fff; border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08); padding: 22px; margin-bottom: 18px; }
    .card h2 { margin: 0 0 4px; font-size: 1rem; color: #111827; }
    .card .sub { margin: 0 0 16px; font-size: .83rem; color: #6b7280; }
    .stats-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 14px; margin-bottom:18px; }
    .stat-card { background:#fff; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08);
                 padding:18px 20px; text-align:center; }
    .stat-card .label { font-size:.78rem; color:#6b7280; text-transform:uppercase;
                        letter-spacing:.04em; margin:0 0 6px; }
    .stat-card .value { display:block; font-size:1.8rem; font-weight:800; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    .filters { display:flex; gap:10px; align-items:center; margin-bottom:18px; }
    .filters label { font-size:.83rem; font-weight:600; }
    .filters select { padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.88rem; }
    .filters button { padding:6px 14px; background:#2563eb; color:#fff;
                      border:none; border-radius:6px; font-size:.88rem; font-weight:600; cursor:pointer; }
    table { width:100%; border-collapse:collapse; font-size:.85rem; }
    thead th { background:#f9fafb; padding:9px 12px; text-align:left;
               border-bottom:2px solid #e5e7eb; font-size:.78rem;
               text-transform:uppercase; letter-spacing:.04em; color:#6b7280; }
    tbody td { padding:8px 12px; border-bottom:1px solid #f3f4f6; }
    tbody tr:hover { background:#f9fafb; }
    .url-cell { max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .threshold-key { display:flex; gap:16px; font-size:.78rem; margin-bottom:12px;
                      list-style:none; padding:0; }
    .threshold-key li { display:flex; align-items:center; gap:4px; }
    .dot { width:10px; height:10px; border-radius:50%; display:inline-block; }
    .save-section { border-top:1px solid #e5e7eb; margin-top:18px; padding-top:18px; }
    .save-section h3 { margin:0 0 12px; font-size:.95rem; color:#111827; }
    .save-section textarea { width:100%; padding:9px 10px; border:1px solid #d1d5db;
                              border-radius:6px; font-size:.88rem; resize:vertical; min-height:80px; margin-bottom:10px; }
    .save-section input[type=text] { width:100%; padding:9px 10px; border:1px solid #d1d5db;
                                      border-radius:6px; font-size:.88rem; margin-bottom:10px; }
    .btn { padding:8px 18px; border:none; border-radius:6px; font-size:.88rem; font-weight:600; cursor:pointer; }
    .btn-primary { background:#2563eb; color:#fff; }
    .btn-primary:hover { background:#1d4ed8; }
    .alert-success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0;
                     padding:8px 14px; border-radius:6px; font-size:.88rem; margin-bottom:12px; }
    noscript .ns-warn { display:block; background:#fef9c3; border:1px solid #fde047;
                        color:#713f12; padding:10px 14px; border-radius:6px; margin-bottom:16px; font-size:.88rem; }
  </style>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>
<main class="container">

  <noscript>
    <p class="ns-warn">⚠ JavaScript is disabled. Charts are not displayed, but all data tables remain fully accessible below.</p>
  </noscript>

  <?php if ($saveMsg): ?>
    <p class="alert-success" role="status"><?= $e($saveMsg) ?></p>
  <?php endif; ?>

  <form method="get" class="filters">
    <label for="days">Date range:</label>
    <select id="days" name="days">
      <?php foreach ([7,14,30,60,90] as $d): ?>
        <option value="<?= $d ?>" <?= $days===$d?'selected':'' ?>>Last <?= $d ?> days</option>
      <?php endforeach; ?>
    </select>
    <button type="submit">Apply</button>
  </form>

  <!-- Summary stats -->
  <?php
    [$lcpL,$lcpS] = $lcpClass((float)$sum['avg_lcp']);
    [$clsL,$clsS] = $clsClass((float)$sum['avg_cls']);
    [$inpL,$inpS] = $inpClass((float)$sum['avg_inp']);
  ?>
  <section class="stats-grid">
    <article class="stat-card">
      <p class="label">Avg LCP</p>
      <output class="value" style="<?= $lcpS ?>"><?= $sum['avg_lcp']>0 ? number_format((int)$sum['avg_lcp']).'ms' : '—' ?></output>
      <p style="font-size:.78rem;color:#6b7280;margin-top:4px"><?= $lcpL ?> · target &lt;2500ms</p>
    </article>
    <article class="stat-card">
      <p class="label">Avg CLS</p>
      <output class="value" style="<?= $clsS ?>"><?= $sum['avg_cls']>0 ? number_format((float)$sum['avg_cls'],4) : '—' ?></output>
      <p style="font-size:.78rem;color:#6b7280;margin-top:4px"><?= $clsL ?> · target &lt;0.1</p>
    </article>
    <article class="stat-card">
      <p class="label">Avg INP</p>
      <output class="value" style="<?= $inpS ?>"><?= $sum['avg_inp']>0 ? number_format((int)$sum['avg_inp']).'ms' : '—' ?></output>
      <p style="font-size:.78rem;color:#6b7280;margin-top:4px"><?= $inpL ?> · target &lt;200ms</p>
    </article>
  </section>

  <!-- LCP trend -->
  <section class="card">
    <h2>LCP Trend Over Time</h2>
    <p class="sub">Daily average Largest Contentful Paint. Good threshold: under 2500 ms.</p>
    <canvas id="trendChart" height="80"></canvas>
    <noscript>
      <table><thead><tr><th>Date</th><th>Avg LCP (ms)</th></tr></thead><tbody>
      <?php foreach ($trend as $r): ?>
        <tr><td><?= $e($r['day']) ?></td><td><?= number_format((int)$r['avg_lcp']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </noscript>
  </section>

  <section class="grid2">
    <!-- LCP per page bar chart -->
    <section class="card">
      <h2>Avg LCP per Page</h2>
      <p class="sub">Lower is better. Red line = 2500 ms "Good" threshold.</p>
      <canvas id="lcpChart" height="160"></canvas>
    </section>

    <!-- Vitals table -->
    <section class="card">
      <h2>Core Web Vitals by Page</h2>
      <p class="sub">LCP, CLS, and INP averages with Google thresholds applied.</p>
      <ul class="threshold-key">
        <li><span class="dot" style="background:#16a34a"></span>Good</li>
        <li><span class="dot" style="background:#d97706"></span>Needs Work</li>
        <li><span class="dot" style="background:#dc2626"></span>Poor</li>
      </ul>
      <table>
        <thead><tr><th>Page</th><th>Samples</th><th>LCP</th><th>CLS</th><th>INP</th></tr></thead>
        <tbody>
          <?php foreach ($vitals as $v):
            [$ll,$ls] = $lcpClass((float)$v['avg_lcp']);
            [$cl,$cs] = $clsClass((float)$v['avg_cls']);
            [$il,$is] = $inpClass((float)$v['avg_inp']);
          ?>
          <tr>
            <td class="url-cell" title="<?= $e($v['url']) ?>"><?= $e(parse_url($v['url'], PHP_URL_PATH) ?: '/') ?></td>
            <td><?= (int)$v['samples'] ?></td>
            <td style="<?= $ls ?>"><?= $v['avg_lcp']>0 ? number_format((int)$v['avg_lcp']).'ms '.$ll : '—' ?></td>
            <td style="<?= $cs ?>"><?= $v['avg_cls']>0 ? number_format((float)$v['avg_cls'],4).' '.$cl : '—' ?></td>
            <td style="<?= $is ?>"><?= $v['avg_inp']>0 ? number_format((int)$v['avg_inp']).'ms '.$il : '—' ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$vitals): ?><tr><td colspan="5" style="color:#9ca3af;text-align:center">No vitals data</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>
  </section>

  <!-- Analyst comment + save -->
  <?php if (in_array(currentRole(), ['superadmin','analyst'])): ?>
  <section class="card" style="margin-bottom:10px">
    <a href="/export/pdf.php?report=performance&days=<?= $days ?>" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px">&#x2193; Export PDF</a>
    <span style="font-size:.8rem;color:#6b7280;margin-left:8px">Downloads a PDF snapshot of this report (last <?= $days ?> days)</span>
  </section>
  <section class="card">
    <section class="save-section">
      <h3>Save This Report</h3>
      <form method="post">
        <input type="hidden" name="save_report" value="1">
        <label style="font-size:.83rem;font-weight:600;color:#374151">Report Title</label>
        <input type="text" name="report_title" placeholder="e.g. Performance — March 2026" required>
        <label style="font-size:.83rem;font-weight:600;color:#374151">Analyst Comment</label>
        <textarea name="analyst_comment"
          placeholder="Assess the Core Web Vitals: are LCP/CLS/INP within Google's thresholds? Note any pages that need optimization…"></textarea>
        <button type="submit" class="btn btn-primary">Save Report</button>
      </form>
    </section>
  </section>
  <?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const trendLabels = <?= json_encode($trendLabels) ?>;
const trendValues = <?= json_encode($trendValues) ?>;
const chartLabels = <?= json_encode($chartLabels) ?>;
const lcpValues   = <?= json_encode($lcpValues) ?>;

// LCP trend line
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: trendLabels,
    datasets: [{
      label: 'Avg LCP (ms)', data: trendValues,
      borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)',
      tension: 0.3, fill: true, pointRadius: 4, pointBackgroundColor: '#3b82f6'
    }, {
      label: 'Good threshold (2500ms)',
      data: trendLabels.map(() => 2500),
      borderColor: '#16a34a', borderDash: [6,4],
      pointRadius: 0, borderWidth: 1.5,
      backgroundColor: 'transparent'
    }]
  },
  options: {
    responsive: true,
    scales: { y: { beginAtZero: true, ticks: { callback: v => v+'ms' } } },
    plugins: { legend: { position: 'bottom' } }
  }
});

// LCP per page bar
new Chart(document.getElementById('lcpChart'), {
  type: 'bar',
  data: {
    labels: chartLabels,
    datasets: [{
      label: 'Avg LCP (ms)',
      data: lcpValues,
      backgroundColor: lcpValues.map(v => v === 0 ? '#9ca3af' : v < 2500 ? '#16a34a' : v < 4000 ? '#d97706' : '#dc2626'),
      borderRadius: 4
    }]
  },
  options: {
    indexAxis: 'y', responsive: true,
    scales: { x: { beginAtZero: true, ticks: { callback: v => v+'ms' } } },
    plugins: {
      legend: { display: false },
      annotation: {}
    }
  }
});
</script>
</body>
</html>
