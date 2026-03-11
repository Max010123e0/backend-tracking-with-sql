<?php
require_once __DIR__ . '/auth.php';
requireRole('superadmin', 'analyst');

require_once '/var/www/collector.maxk.site/db_config.php';
$pdo = getDbConnection();

// Chart 1: Event counts by type
$typeRows = $pdo->query(
    "SELECT event_type, COUNT(*) AS cnt
     FROM events
     GROUP BY event_type
     ORDER BY cnt DESC"
)->fetchAll();

$typeLabels = array_column($typeRows, 'event_type');
$typeValues = array_map('intval', array_column($typeRows, 'cnt'));

// Chart 2: Daily pageviews over the last 14 days
$dailyRows = $pdo->query(
    "SELECT DATE(timestamp) AS day, COUNT(*) AS cnt
     FROM events
     WHERE event_type IN ('pageview','pageview_nojs')
       AND timestamp >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
     GROUP BY DATE(timestamp)
     ORDER BY day ASC"
)->fetchAll();

$dailyLabels = array_column($dailyRows, 'day');
$dailyValues = array_map('intval', array_column($dailyRows, 'cnt'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Charts – Analytics Reporting</title>
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
    .container { max-width: 1100px; margin: 28px auto; padding: 0 16px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(440px, 1fr)); gap: 16px; }
    .card { background: #fff; border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08); padding: 24px; }
    .card h2 { margin: 0 0 4px; font-size: 1rem; color: #111827; }
    .card p  { margin: 0 0 16px; font-size: .85rem; color: #6b7280; }
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
      <a href="/logout.php">Logout</a>
    </nav>
  </header>

  <main class="container">
    <div class="grid">

      <div class="card">
        <h2>Events by Type</h2>
        <p>Breakdown of all collected event types.</p>
        <canvas id="typeChart"></canvas>
      </div>

      <div class="card">
        <h2>Daily Pageviews (Last 14 Days)</h2>
        <p>Pageview and no-JS pixel events per day.</p>
        <canvas id="dailyChart"></canvas>
      </div>

    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    // Chart 1 – doughnut: events by type
    new Chart(document.getElementById('typeChart'), {
      type: 'doughnut',
      data: {
        labels: <?= json_encode($typeLabels) ?>,
        datasets: [{
          data: <?= json_encode($typeValues) ?>,
          backgroundColor: ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ef4444'],
          borderWidth: 2,
          borderColor: '#fff'
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position: 'bottom' }
        }
      }
    });

    // Chart 2 – line: daily pageviews
    new Chart(document.getElementById('dailyChart'), {
      type: 'line',
      data: {
        labels: <?= json_encode($dailyLabels) ?>,
        datasets: [{
          label: 'Pageviews',
          data: <?= json_encode($dailyValues) ?>,
          borderColor: '#3b82f6',
          backgroundColor: 'rgba(59,130,246,0.12)',
          tension: 0.3,
          fill: true,
          pointRadius: 4,
          pointBackgroundColor: '#3b82f6'
        }]
      },
      options: {
        responsive: true,
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 } }
        },
        plugins: {
          legend: { display: false }
        }
      }
    });
  </script>
</body>
</html>
