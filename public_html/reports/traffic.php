<?php
require_once __DIR__ . '/../auth.php';
requireSection('traffic');

require_once '/var/www/collector.maxk.site/db_config.php';
$pdo = getDbConnection();
$currentSection = 'traffic';

// ── Filters ───────────────────────────────────────────────────────────────────
$days = max(7, min(90, (int)($_GET['days'] ?? 30)));

// ── Data: daily pageviews trend ───────────────────────────────────────────────
$dailyRows = $pdo->prepare(
    "SELECT DATE(timestamp) AS day, COUNT(*) AS cnt
     FROM pageviews
     WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY DATE(timestamp) ORDER BY day ASC"
);
$dailyRows->execute([$days]);
$daily = $dailyRows->fetchAll();
$dailyLabels = array_column($daily, 'day');
$dailyValues = array_map('intval', array_column($daily, 'cnt'));

// ── Data: top pages ───────────────────────────────────────────────────────────
$topPages = $pdo->prepare(
    "SELECT url, COUNT(*) AS cnt
     FROM pageviews
     WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY url ORDER BY cnt DESC LIMIT 10"
);
$topPages->execute([$days]);
$pages = $topPages->fetchAll();
$pageLabels = array_map(fn($r) => parse_url($r['url'], PHP_URL_PATH) ?: '/', $pages);
$pageValues = array_map('intval', array_column($pages, 'cnt'));

// ── Data: referrers ───────────────────────────────────────────────────────────
$refRows = $pdo->prepare(
    "SELECT JSON_UNQUOTE(referrer) AS ref, COUNT(*) AS cnt
     FROM pageviews
     WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
       AND referrer IS NOT NULL AND JSON_UNQUOTE(referrer) != ''
         AND JSON_UNQUOTE(referrer) != 'null'
     GROUP BY ref ORDER BY cnt DESC LIMIT 10"
);
$refRows->execute([$days]);
$referrers = $refRows->fetchAll();

// ── Data: summary stats ───────────────────────────────────────────────────────
$stats = $pdo->prepare(
    "SELECT
       COUNT(*) AS total_pageviews,
       COUNT(DISTINCT session_id) AS unique_sessions,
       COUNT(DISTINCT url) AS unique_pages
     FROM pageviews
     WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"
);
$stats->execute([$days]);
$stat = $stats->fetch();

// ── Handle save report ────────────────────────────────────────────────────────
$saveMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report'])) {
    requireRole('superadmin', 'analyst');
    $title   = trim($_POST['report_title'] ?? '');
    $comment = trim($_POST['analyst_comment'] ?? '');
    if ($title !== '') {
        $slug = 'traffic-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $pdo->prepare(
            "INSERT INTO saved_reports (title, slug, category, filters, analyst_comment, created_by)
             VALUES (?, ?, 'traffic', ?, ?, ?)"
        )->execute([
            $title, $slug,
            json_encode(['days' => $days]),
            $comment ?: null,
            currentUserId()
        ]);
        $saveMsg = 'Report saved.';
    }
}

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Traffic Report – Analytics Reporting</title>
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
    .stats-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 14px; }
    .stat-card { background:#fff; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08);
                 padding:18px 20px; text-align:center; }
    .stat-card .label { font-size:.8rem; color:#6b7280; text-transform:uppercase;
                        letter-spacing:.04em; margin-bottom:6px; }
    .stat-card .value { font-size:2rem; font-weight:800; color:#2563eb; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    .filters { display:flex; gap:10px; align-items:center; margin-bottom:18px; flex-wrap:wrap; }
    .filters label { font-size:.83rem; font-weight:600; }
    .filters select { padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.88rem; }
    .filters button { padding:6px 14px; background:#2563eb; color:#fff;
                      border:none; border-radius:6px; font-size:.88rem;
                      font-weight:600; cursor:pointer; }
    table { width:100%; border-collapse:collapse; font-size:.85rem; }
    thead th { background:#f9fafb; padding:9px 12px; text-align:left;
               border-bottom:2px solid #e5e7eb; font-size:.78rem;
               text-transform:uppercase; letter-spacing:.04em; color:#6b7280; }
    tbody td { padding:8px 12px; border-bottom:1px solid #f3f4f6; }
    tbody tr:hover { background:#f9fafb; }
    .url-cell { max-width:380px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .bar-bg { background:#e5e7eb; border-radius:4px; height:10px; }
    .bar-fill { background:#3b82f6; border-radius:4px; height:10px; }
    .save-section { border-top:1px solid #e5e7eb; margin-top:18px; padding-top:18px; }
    .save-section h3 { margin:0 0 12px; font-size:.95rem; color:#111827; }
    .save-section textarea { width:100%; padding:9px 10px; border:1px solid #d1d5db;
                              border-radius:6px; font-size:.88rem; resize:vertical;
                              min-height:80px; margin-bottom:10px; }
    .save-section input[type=text] { width:100%; padding:9px 10px; border:1px solid #d1d5db;
                                      border-radius:6px; font-size:.88rem; margin-bottom:10px; }
    .btn { padding:8px 18px; border:none; border-radius:6px; font-size:.88rem;
           font-weight:600; cursor:pointer; }
    .btn-primary { background:#2563eb; color:#fff; }
    .btn-primary:hover { background:#1d4ed8; }
    .alert-success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0;
                     padding:8px 14px; border-radius:6px; font-size:.88rem; margin-bottom:12px; }
    noscript .ns-warn { display:block; background:#fef9c3; border:1px solid #fde047;
                        color:#713f12; padding:10px 14px; border-radius:6px;
                        margin-bottom:16px; font-size:.88rem; }
  </style>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>
<main class="container">

  <noscript>
    <div class="ns-warn">⚠ JavaScript is disabled. Charts are not displayed, but all data tables remain fully accessible below.</div>
  </noscript>

  <?php if ($saveMsg): ?>
    <div class="alert-success"><?= $e($saveMsg) ?></div>
  <?php endif; ?>

  <!-- Filters -->
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
  <div class="stats-grid" style="margin-bottom:18px">
    <div class="stat-card">
      <div class="label">Total Pageviews</div>
      <div class="value"><?= number_format((int)$stat['total_pageviews']) ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Unique Sessions</div>
      <div class="value"><?= number_format((int)$stat['unique_sessions']) ?></div>
    </div>
    <div class="stat-card">
      <div class="label">Unique Pages</div>
      <div class="value"><?= number_format((int)$stat['unique_pages']) ?></div>
    </div>
  </div>

  <!-- Daily trend chart -->
  <div class="card">
    <h2>Daily Pageviews</h2>
    <p class="sub">Pageview events collected over the last <?= $e($days) ?> days.</p>
    <canvas id="dailyChart" height="80"></canvas>
    <noscript>
      <table><thead><tr><th>Date</th><th>Pageviews</th></tr></thead><tbody>
      <?php foreach ($daily as $r): ?>
        <tr><td><?= $e($r['day']) ?></td><td><?= (int)$r['cnt'] ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </noscript>
  </div>

  <div class="grid2">
    <!-- Top pages chart + table -->
    <div class="card">
      <h2>Top Pages</h2>
      <p class="sub">Most visited URLs in the selected period.</p>
      <canvas id="pagesChart" height="160"></canvas>
      <noscript><p style="color:#6b7280;font-size:.85rem">Enable JS to see chart.</p></noscript>
      <table style="margin-top:18px">
        <thead><tr><th>Page</th><th>Views</th><th style="width:120px">Share</th></tr></thead>
        <tbody>
          <?php $maxPV = max(array_column($pages,'cnt') ?: [1]); ?>
          <?php foreach ($pages as $row): ?>
          <tr>
            <td class="url-cell" title="<?= $e($row['url']) ?>"><?= $e(parse_url($row['url'], PHP_URL_PATH) ?: '/') ?></td>
            <td><?= number_format((int)$row['cnt']) ?></td>
            <td><div class="bar-bg"><div class="bar-fill" style="width:<?= round((int)$row['cnt']/$maxPV*100) ?>%"></div></div></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$pages): ?><tr><td colspan="3" style="color:#9ca3af;text-align:center">No data</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Referrers table -->
    <div class="card">
      <h2>Traffic Sources / Referrers</h2>
      <p class="sub">Where visitors came from (pages with a referrer header).</p>
      <table>
        <thead><tr><th>Referrer</th><th>Visits</th><th style="width:120px">Share</th></tr></thead>
        <tbody>
          <?php $maxRef = max(array_column($referrers,'cnt') ?: [1]); ?>
          <?php foreach ($referrers as $row): ?>
          <tr>
            <td class="url-cell" title="<?= $e($row['ref']) ?>"><?= $e($row['ref']) ?></td>
            <td><?= number_format((int)$row['cnt']) ?></td>
            <td><div class="bar-bg"><div class="bar-fill" style="width:<?= round((int)$row['cnt']/$maxRef*100) ?>%;background:#8b5cf6"></div></div></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$referrers): ?><tr><td colspan="3" style="color:#9ca3af;text-align:center">No referrer data</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Analyst comment + save -->
  <?php if (in_array(currentRole(), ['superadmin','analyst'])): ?>
  <div class="card" style="margin-bottom:10px">
    <a href="/export/pdf.php?report=traffic&days=<?= $days ?>" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px">&#x2193; Export PDF</a>
    <span style="font-size:.8rem;color:#6b7280;margin-left:8px">Downloads a PDF snapshot of this report (last <?= $days ?> days)</span>
  </div>
  <div class="card">
    <div class="save-section">
      <h3>Save This Report</h3>
      <form method="post">
        <input type="hidden" name="save_report" value="1">
        <label style="font-size:.83rem;font-weight:600;color:#374151">Report Title</label>
        <input type="text" name="report_title" placeholder="e.g. Traffic — March 2026" required>
        <label style="font-size:.83rem;font-weight:600;color:#374151">Analyst Comment</label>
        <textarea name="analyst_comment"
          placeholder="Interpret the data: trends, anomalies, recommendations…"></textarea>
        <button type="submit" class="btn btn-primary">Save Report</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const dailyLabels = <?= json_encode($dailyLabels) ?>;
const dailyValues = <?= json_encode($dailyValues) ?>;
const pageLabels  = <?= json_encode($pageLabels) ?>;
const pageValues  = <?= json_encode($pageValues) ?>;

// Daily line chart
new Chart(document.getElementById('dailyChart'), {
  type: 'line',
  data: {
    labels: dailyLabels,
    datasets: [{
      label: 'Pageviews',
      data: dailyValues,
      borderColor: '#3b82f6',
      backgroundColor: 'rgba(59,130,246,0.1)',
      tension: 0.3, fill: true,
      pointRadius: 4, pointBackgroundColor: '#3b82f6'
    }]
  },
  options: {
    responsive: true,
    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    plugins: { legend: { display: false } }
  }
});

// Top pages horizontal bar
new Chart(document.getElementById('pagesChart'), {
  type: 'bar',
  data: {
    labels: pageLabels,
    datasets: [{ label: 'Views', data: pageValues,
      backgroundColor: '#3b82f6', borderRadius: 4 }]
  },
  options: {
    indexAxis: 'y', responsive: true,
    scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
    plugins: { legend: { display: false } }
  }
});
</script>
</body>
</html>
