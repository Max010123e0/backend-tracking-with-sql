<?php
require_once __DIR__ . '/../auth.php';
requireSection('errors');

require_once '/var/www/collector.maxk.site/db_config.php';
$pdo = getDbConnection();
$currentSection = 'errors';

$days = max(7, min(90, (int)($_GET['days'] ?? 30)));
$limit  = 50;
$offset = max(0, (int)($_GET['offset'] ?? 0));

// Error type breakdown (for doughnut)
$typeRows = $pdo->prepare(
    "SELECT JSON_UNQUOTE(error_type) AS type, COUNT(*) AS cnt
     FROM errors
     WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
       AND error_type IS NOT NULL
     GROUP BY type ORDER BY cnt DESC"
);
$typeRows->execute([$days]);
$typeData = $typeRows->fetchAll();
$typeLabels = array_column($typeData, 'type');
$typeValues = array_map('intval', array_column($typeData, 'cnt'));

// Daily error trend
$trendRows = $pdo->prepare(
    "SELECT DATE(timestamp) AS day, COUNT(*) AS cnt
     FROM errors
     WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY day ORDER BY day ASC"
);
$trendRows->execute([$days]);
$trend = $trendRows->fetchAll();
$trendLabels = array_column($trend, 'day');
$trendValues = array_map('intval', array_column($trend, 'cnt'));

// Top error messages
$topMsgs = $pdo->prepare(
    "SELECT JSON_UNQUOTE(error_type) AS type,
            JSON_UNQUOTE(error_message) AS message,
            JSON_UNQUOTE(error_source) AS source,
            COUNT(*) AS cnt
     FROM errors
     WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY type, message, source ORDER BY cnt DESC LIMIT 10"
);
$topMsgs->execute([$days]);
$topErrors = $topMsgs->fetchAll();

// Paginated error log 
$logRows = $pdo->prepare(
    "SELECT e.id, e.session_id, e.url,
            JSON_UNQUOTE(e.error_type)    AS type,
            JSON_UNQUOTE(e.error_message) AS message,
            JSON_UNQUOTE(e.error_source)  AS source,
            JSON_UNQUOTE(e.error_line)    AS line,
            e.timestamp
     FROM errors e
     WHERE e.timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     ORDER BY e.timestamp DESC
     LIMIT $limit OFFSET $offset"
);
$logRows->execute([$days]);
$log = $logRows->fetchAll();

$cntRow = $pdo->prepare(
    "SELECT COUNT(*) AS c FROM errors WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"
);
$cntRow->execute([$days]);
$total = (int)$cntRow->fetch()['c'];

// Summary
$sumRow = $pdo->prepare(
    "SELECT COUNT(*) AS total_errors,
            COUNT(DISTINCT session_id) AS affected_sessions,
            COUNT(DISTINCT JSON_UNQUOTE(error_type)) AS distinct_types
     FROM errors WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"
);
$sumRow->execute([$days]);
$sum = $sumRow->fetch();

// Handle save
$saveMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report'])) {
    requireRole('superadmin','analyst');
    $title   = trim($_POST['report_title'] ?? '');
    $comment = trim($_POST['analyst_comment'] ?? '');
    if ($title !== '') {
        $slug = 'err-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $pdo->prepare(
            "INSERT INTO saved_reports (title,slug,category,filters,analyst_comment,created_by)
             VALUES (?,?,'errors',?,?,?)"
        )->execute([$title,$slug,json_encode(['days'=>$days]),$comment?:null,currentUserId()]);
        $saveMsg = 'Report saved.';
    }
}

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$paginUrl = fn($o) => '?days='.$days.'&offset='.$o;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Errors Report – Analytics Reporting</title>
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
    .stats-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:18px; }
    .stat-card { background:#fff; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08);
                 padding:18px 20px; text-align:center; }
    .stat-card .label { font-size:.78rem; color:#6b7280; text-transform:uppercase;
                        letter-spacing:.04em; margin-bottom:6px; }
    .stat-card .value { display:block; font-size:2rem; font-weight:800; color:#ef4444; }
    .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    .filters { display:flex; gap:10px; align-items:center; margin-bottom:18px; }
    .filters label { font-size:.83rem; font-weight:600; }
    .filters select { padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.88rem; }
    .filters button { padding:6px 14px; background:#2563eb; color:#fff;
                      border:none; border-radius:6px; font-size:.88rem; font-weight:600; cursor:pointer; }
    table { width:100%; border-collapse:collapse; font-size:.83rem; }
    thead th { background:#f9fafb; padding:9px 12px; text-align:left;
               border-bottom:2px solid #e5e7eb; font-size:.78rem;
               text-transform:uppercase; letter-spacing:.04em; color:#6b7280; }
    tbody td { padding:8px 12px; border-bottom:1px solid #f3f4f6; vertical-align:top; }
    tbody tr:hover { background:#fef2f2; }
    .url-cell { max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .msg-cell { max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sid { font-family:monospace; font-size:.78rem; color:#9ca3af; }
    .badge-error { display:inline-block; padding:2px 8px; border-radius:9999px;
                   background:#fee2e2; color:#b91c1c; font-size:.75rem; font-weight:700; }
    .pagination { display:flex; gap:8px; align-items:center; margin-top:14px; font-size:.88rem; }
    .pagination a { padding:5px 12px; border-radius:6px; background:#e5e7eb;
                    color:#374151; text-decoration:none; font-weight:600; }
    .pagination a:hover { background:#d1d5db; }
    .pagination .current { padding:5px 12px; background:#2563eb; color:#fff; border-radius:6px; }
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

  <!-- Summary -->
  <section class="stats-grid">
    <article class="stat-card">
      <p class="label">Total Errors</p>
      <output class="value"><?= number_format((int)$sum['total_errors']) ?></output>
    </article>
    <article class="stat-card">
      <p class="label">Affected Sessions</p>
      <output class="value"><?= number_format((int)$sum['affected_sessions']) ?></output>
    </article>
    <article class="stat-card">
      <p class="label">Distinct Error Types</p>
      <output class="value" style="color:#f59e0b"><?= number_format((int)$sum['distinct_types']) ?></output>
    </article>
  </section>

  <section class="grid2">
    <!-- Error type doughnut -->
    <section class="card">
      <h2>Errors by Type</h2>
      <p class="sub">Distribution of collected error types in the selected period.</p>
      <canvas id="typeChart" style="max-height:240px"></canvas>
      <noscript>
        <table><thead><tr><th>Type</th><th>Count</th></tr></thead><tbody>
        <?php foreach ($typeData as $r): ?>
          <tr><td><?= $e($r['type']) ?></td><td><?= (int)$r['cnt'] ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </noscript>
    </section>

    <!-- Daily trend -->
    <section class="card">
      <h2>Daily Error Count</h2>
      <p class="sub">Total errors triggered per day. Spikes may indicate deployments or user-facing issues.</p>
      <canvas id="trendChart" style="max-height:240px"></canvas>
      <noscript>
        <table><thead><tr><th>Date</th><th>Errors</th></tr></thead><tbody>
        <?php foreach ($trend as $r): ?>
          <tr><td><?= $e($r['day']) ?></td><td><?= (int)$r['cnt'] ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </noscript>
    </section>
  </section>

  <!-- Top error messages -->
  <section class="card">
    <h2>Top Error Messages</h2>
    <p class="sub">Most frequently occurring errors — these are the highest-priority items to fix.</p>
    <table>
      <thead><tr><th>Type</th><th>Message</th><th>Source</th><th>Count</th></tr></thead>
      <tbody>
        <?php foreach ($topErrors as $r): ?>
        <tr>
          <td><span class="badge-error"><?= $e($r['type'] ?? 'unknown') ?></span></td>
          <td class="msg-cell" title="<?= $e($r['message']) ?>"><?= $e($r['message'] ?? 'null') ?></td>
          <td class="url-cell" title="<?= $e($r['source']) ?>"><?= $e($r['source'] ?? '—') ?></td>
          <td><strong><?= (int)$r['cnt'] ?></strong></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$topErrors): ?><tr><td colspan="4" style="color:#9ca3af;text-align:center">No errors recorded</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>

  <!-- Full error log -->
  <section class="card">
    <h2>Error Log</h2>
    <p class="sub">Full paginated log — <?= number_format($total) ?> total errors in the selected period.</p>
    <table>
      <thead>
        <tr><th>Time</th><th>Type</th><th>Message</th><th>Source</th><th>Line</th><th>Session</th></tr>
      </thead>
      <tbody>
        <?php foreach ($log as $r): ?>
        <tr>
          <td style="white-space:nowrap"><?= $e(substr($r['timestamp'],0,16)) ?></td>
          <td><span class="badge-error"><?= $e($r['type'] ?? 'unknown') ?></span></td>
          <td class="msg-cell" title="<?= $e($r['message']) ?>"><?= $e($r['message'] ?? 'null') ?></td>
          <td class="url-cell" title="<?= $e($r['source']) ?>"><?= $e($r['source'] ?? '—') ?></td>
          <td><?= $e($r['line'] ?? '—') ?></td>
          <td class="sid"><?= $e(substr($r['session_id'],0,12)) ?>…</td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$log): ?><tr><td colspan="6" style="color:#9ca3af;text-align:center">No errors in this period</td></tr><?php endif; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($total > $limit): ?>
    <nav class="pagination" aria-label="Error log pagination">
      <?php if ($offset > 0): ?>
        <a href="<?= $e($paginUrl(max(0, $offset-$limit))) ?>">← Prev</a>
      <?php endif; ?>
      <span class="current"><?= $offset+1 ?>–<?= min($offset+$limit,$total) ?> of <?= number_format($total) ?></span>
      <?php if ($offset+$limit < $total): ?>
        <a href="<?= $e($paginUrl($offset+$limit)) ?>">Next →</a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
  </section>

  <!-- Analyst comment + save -->
  <?php if (in_array(currentRole(), ['superadmin','analyst'])): ?>
  <section class="card" style="margin-bottom:10px">
    <a href="/export/pdf.php?report=errors&days=<?= $days ?>" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px">&#x2193; Export PDF</a>
    <span style="font-size:.8rem;color:#6b7280;margin-left:8px">Downloads a PDF snapshot of this report (last <?= $days ?> days)</span>
  </section>
  <section class="card">
    <section class="save-section">
      <h3>Save This Report</h3>
      <form method="post">
        <input type="hidden" name="save_report" value="1">
        <label style="font-size:.83rem;font-weight:600;color:#374151">Report Title</label>
        <input type="text" name="report_title" placeholder="e.g. Errors — March 2026" required>
        <label style="font-size:.83rem;font-weight:600;color:#374151">Analyst Comment</label>
        <textarea name="analyst_comment"
          placeholder="Explain the errors: what caused them, their impact on users, and recommended fixes…"></textarea>
        <button type="submit" class="btn btn-primary">Save Report</button>
      </form>
    </section>
  </section>
  <?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const typeLabels  = <?= json_encode($typeLabels) ?>;
const typeValues  = <?= json_encode($typeValues) ?>;
const trendLabels = <?= json_encode($trendLabels) ?>;
const trendValues = <?= json_encode($trendValues) ?>;

new Chart(document.getElementById('typeChart'), {
  type: 'doughnut',
  data: {
    labels: typeLabels.length ? typeLabels : ['No data'],
    datasets: [{ data: typeValues.length ? typeValues : [1],
      backgroundColor: ['#ef4444','#f97316','#eab308','#8b5cf6','#3b82f6'],
      borderWidth: 2, borderColor: '#fff' }]
  },
  options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});

new Chart(document.getElementById('trendChart'), {
  type: 'bar',
  data: {
    labels: trendLabels,
    datasets: [{ label: 'Errors', data: trendValues,
      backgroundColor: 'rgba(239,68,68,0.75)', borderRadius: 4 }]
  },
  options: {
    responsive: true,
    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    plugins: { legend: { display: false } }
  }
});
</script>
</body>
</html>
