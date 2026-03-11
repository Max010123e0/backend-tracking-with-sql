<?php
require_once __DIR__ . '/auth.php';
requireRole('superadmin', 'analyst');

require_once '/var/www/collector.maxk.site/db_config.php';

$pdo = getDbConnection();

// Filters
$allowed_types = ['pageview', 'pageview_nojs', 'error', 'vitals'];
$event_type = isset($_GET['event_type']) && in_array($_GET['event_type'], $allowed_types, true)
    ? $_GET['event_type'] : '';

$params = [];
$where  = '';
if ($event_type !== '') {
    $where    = 'WHERE event_type = ?';
    $params[] = $event_type;
}

$limit  = 100;
$offset = max(0, (int) ($_GET['offset'] ?? 0));

$rows = $pdo->prepare(
    "SELECT id, session_id, event_type, url, timestamp
     FROM events
     $where
     ORDER BY timestamp DESC
     LIMIT $limit OFFSET $offset"
);
$rows->execute($params);
$events = $rows->fetchAll();

// Total count for pagination
$count_params = $params;
$total = (int) $pdo->prepare("SELECT COUNT(*) FROM events $where")
    ->execute($count_params ?: []) ? 0 : 0;
$cnt = $pdo->prepare("SELECT COUNT(*) AS c FROM events $where");
$cnt->execute($count_params);
$total = (int) $cnt->fetch()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reports – Analytics Reporting</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #1f2937; }
    .topbar {
      background: #111827; color: #fff;
      padding: 12px 20px; display: flex;
      justify-content: space-between; align-items: center;
    }
    .topbar h1 { margin: 0; font-size: 1.1rem; }
    .topbar nav { display: flex; gap: 16px; align-items: center; }
    .topbar a { color: #e5e7eb; text-decoration: none; font-weight: 600; font-size: .9rem; }
    .topbar a:hover { text-decoration: underline; }
    .topbar .user { color: #9ca3af; font-size: .85rem; }
    .container { max-width: 1200px; margin: 28px auto; padding: 0 16px; }
    section.card { background: #fff; border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08); padding: 20px; margin-bottom: 16px; }
    section.card h2 { margin: 0 0 4px; font-size: 1.1rem; color: #111827; }
    .meta { color: #6b7280; font-size: .88rem; margin: 0 0 16px; }
    .filters { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
    .filters label { font-size: .85rem; font-weight: 600; }
    .filters select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: .9rem; }
    .filters button {
      padding: 6px 14px; background: #2563eb; color: #fff;
      border: none; border-radius: 6px; font-size: .9rem; cursor: pointer; font-weight: 600;
    }
    .filters button:hover { background: #1d4ed8; }
    .filters a.reset {
      padding: 6px 14px; background: #e5e7eb; color: #374151;
      border-radius: 6px; font-size: .9rem; text-decoration: none; font-weight: 600;
    }
    table { width: 100%; border-collapse: collapse; font-size: .88rem; }
    thead th {
      background: #f9fafb; padding: 10px 12px; text-align: left;
      border-bottom: 2px solid #e5e7eb; font-size: .82rem;
      text-transform: uppercase; letter-spacing: .04em; color: #6b7280;
    }
    tbody td { padding: 9px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
    tbody tr:hover { background: #f9fafb; }
    .badge {
      display: inline-block; padding: 2px 8px; border-radius: 9999px;
      font-size: .75rem; font-weight: 700;
    }
    .badge-pageview    { background: #dbeafe; color: #1d4ed8; }
    .badge-pageview_nojs { background: #e0e7ff; color: #4338ca; }
    .badge-error       { background: #fee2e2; color: #b91c1c; }
    .badge-vitals      { background: #dcfce7; color: #15803d; }
    .badge-other       { background: #f3f4f6; color: #374151; }
    .url-cell { max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sid-cell { font-family: monospace; font-size: .8rem; color: #6b7280; }
    nav.pagination { display: flex; gap: 10px; align-items: center; margin-top: 16px; font-size: .9rem; }
    nav.pagination a {
      padding: 6px 14px; border-radius: 6px; background: #e5e7eb;
      color: #374151; text-decoration: none; font-weight: 600;
    }
    nav.pagination a:hover { background: #d1d5db; }
    nav.pagination .info { color: #6b7280; }
  </style>
</head>
<body>
  <header class="topbar">
    <h1>Analytics Reporting</h1>
    <nav>
      <a href="/dashboard.php">Dashboard</a>
      <a href="/reports.php">Reports</a>
      <a href="/charts.php">Charts</a>
      <span class="user">Signed in as <?= htmlspecialchars(currentUser(), ENT_QUOTES, 'UTF-8') ?></span>
      <form method="post" action="/logout.php" style="display:inline"><button type="submit" style="background:none;border:none;color:#f87171;cursor:pointer;font-size:.875rem">Logout</button></form>
    </nav>
  </header>

  <main class="container">
    <section class="card">
      <h2>Events Data Table</h2>
      <p class="meta">
        Raw events collected from <strong>test.maxk.site</strong> via collector.js and noscript pixel.
        Showing <?= count($events) ?> of <?= $total ?> total records.
      </p>

      <form class="filters" method="get" action="/reports.php">
        <label for="event_type">Filter by type:</label>
        <select id="event_type" name="event_type">
          <option value="">All types</option>
          <?php foreach ($allowed_types as $t): ?>
            <option value="<?= $t ?>" <?= $event_type === $t ? 'selected' : '' ?>>
              <?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
        <?php if ($event_type !== ''): ?>
          <a class="reset" href="/reports.php">Clear</a>
        <?php endif; ?>
      </form>

      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Session ID</th>
            <th>Event Type</th>
            <th>URL</th>
            <th>Timestamp</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($events)): ?>
            <tr><td colspan="5" style="text-align:center;color:#6b7280;padding:24px">No events found.</td></tr>
          <?php else: ?>
            <?php foreach ($events as $row): ?>
              <?php
                $type  = htmlspecialchars($row['event_type'], ENT_QUOTES, 'UTF-8');
                $badge = match($row['event_type']) {
                    'pageview'      => 'badge-pageview',
                    'pageview_nojs' => 'badge-pageview_nojs',
                    'error'         => 'badge-error',
                    'vitals'        => 'badge-vitals',
                    default         => 'badge-other',
                };
              ?>
              <tr>
                <td><?= (int) $row['id'] ?></td>
                <td class="sid-cell"><?= htmlspecialchars($row['session_id'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="badge <?= $badge ?>"><?= $type ?></span></td>
                <td class="url-cell" title="<?= htmlspecialchars($row['url'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars($row['url'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td><?= htmlspecialchars($row['timestamp'], ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <nav class="pagination" aria-label="Event table pagination">
        <?php if ($offset > 0): ?>
          <a href="?event_type=<?= urlencode($event_type) ?>&offset=<?= max(0, $offset - $limit) ?>">← Previous</a>
        <?php endif; ?>
        <span class="info">Rows <?= $offset + 1 ?>–<?= min($offset + $limit, $total) ?> of <?= $total ?></span>
        <?php if ($offset + $limit < $total): ?>
          <a href="?event_type=<?= urlencode($event_type) ?>&offset=<?= $offset + $limit ?>">Next →</a>
        <?php endif; ?>
      </nav>
    </section>
  </main>
</body>
</html>
