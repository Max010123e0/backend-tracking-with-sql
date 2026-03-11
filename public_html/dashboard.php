<?php
require_once __DIR__ . '/auth.php';
requireRole('superadmin', 'analyst');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard – Analytics Reporting</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #1f2937; }
    .topbar {
      background: #111827; color: #fff;
      padding: 12px 20px; display: flex;
      justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;
    }
    .topbar h1 { margin: 0; font-size: 1.1rem; }
    .topbar nav { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
    .topbar a { color: #e5e7eb; text-decoration: none; font-weight: 600; font-size: .9rem; }
    .topbar a:hover, .topbar a.active { color: #fff; text-decoration: underline; }
    .topbar .user { color: #9ca3af; font-size: .85rem; }
    .role-badge { display: inline-block; font-size:.75rem; padding:1px 7px; border-radius:99px; font-weight:700; margin-left:4px; }
    .role-superadmin { background:#7c3aed; color:#fff; }
    .role-analyst    { background:#0284c7; color:#fff; }
    .role-viewer     { background:#6b7280; color:#fff; }
    .container { max-width: 1100px; margin: 28px auto; padding: 0 16px; }
    .card { background: #fff; border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08); padding: 20px; margin-bottom: 16px; }
    .card h2 { margin: 0 0 8px; font-size: 1.1rem; color: #111827; }
    .card p  { margin: 0; color: #6b7280; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap: 16px; }
    .section-card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08);
                    padding: 22px; border-left: 4px solid #2563eb; }
    .section-card h3 { margin: 0 0 6px; font-size: 1rem; color: #111827; }
    .section-card p  { margin: 0 0 14px; color: #6b7280; font-size: .9rem; }
    .section-card a.btn { display: inline-block; background: #2563eb; color: #fff;
                          text-decoration: none; padding: 7px 16px; border-radius: 6px;
                          font-size: .85rem; font-weight: 600; }
    .section-card a.btn:hover { background: #1d4ed8; }
    .section-card.locked { border-left-color: #d1d5db; opacity: .55; pointer-events: none; }
    ul { padding-left: 18px; }
    li { margin: 6px 0; }
    a { color: #2563eb; }
  </style>
</head>
<body>
<?php $currentSection = 'dashboard'; require_once __DIR__ . '/includes/nav.php'; ?>

  <main class="container">
    <div class="card">
      <h2>Welcome back, <?= htmlspecialchars(currentUser(), ENT_QUOTES, 'UTF-8') ?>.</h2>
      <p>
        Analytics reporting dashboard for <strong>test.maxk.site</strong>.
        Use the report sections below to explore collected data, build charts, and save snapshots for stakeholders.
      </p>
    </div>

    <div class="grid">
<?php
$allSections = [
    'traffic'     => ['label'=>'Traffic',     'desc'=>'Pageviews, sessions, top pages, referrers, and daily trends.',         'href'=>'/reports/traffic.php',     'color'=>'#2563eb'],
    'performance' => ['label'=>'Performance', 'desc'=>'Core Web Vitals (LCP, CLS, INP) per page with Good/Needs Work/Poor ratings.', 'href'=>'/reports/performance.php', 'color'=>'#059669'],
    'errors'      => ['label'=>'Errors',      'desc'=>'JavaScript error counts, types, affected sessions, and full error log.', 'href'=>'/reports/errors.php',      'color'=>'#dc2626'],
];
$role     = currentRole();
$sections = allowedSections();
foreach ($allSections as $slug => $sec):
    $allowed = ($role === 'superadmin' || in_array($slug, $sections, true));
    $lockClass = $allowed ? '' : ' locked';
?>
      <div class="section-card<?= $lockClass ?>" style="border-left-color:<?= $sec['color'] ?>">
        <h3><?= htmlspecialchars($sec['label'], ENT_QUOTES, 'UTF-8') ?></h3>
        <p><?= htmlspecialchars($sec['desc'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ($allowed): ?>
          <a href="<?= htmlspecialchars($sec['href'], ENT_QUOTES, 'UTF-8') ?>" class="btn">Open →</a>
        <?php else: ?>
          <span style="font-size:.8rem;color:#9ca3af;">No access — contact your admin</span>
        <?php endif; ?>
      </div>
<?php endforeach; ?>
    </div>

    <div class="card" style="margin-top:16px">
      <h2>API Endpoints</h2>
      <ul>
        <li><a href="/api/events" target="_blank">/api/events</a> — All collected events</li>
        <li><a href="/api/pageviews" target="_blank">/api/pageviews</a> — Pageview events</li>
        <li><a href="/api/sessions" target="_blank">/api/sessions</a> — User sessions</li>
        <li><a href="/api/errors" target="_blank">/api/errors</a> — Error events</li>
      </ul>
    </div>
  </main>
</body>
</html>
