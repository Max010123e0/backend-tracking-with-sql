<?php
require_once __DIR__ . '/auth.php';
requireLogin();

require_once '/var/www/collector.maxk.site/db_config.php';
$pdo  = getDbConnection();
$role = currentRole();
$uid  = currentUserId();
$e    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ── Handle edit comment (analyst/superadmin only) ─────────────────────────
$editMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_comment'])) {
    requireRole('superadmin', 'analyst');
    $id      = (int)($_POST['report_id'] ?? 0);
    $comment = trim($_POST['analyst_comment'] ?? '');
    if ($id > 0) {
        $pdo->prepare("UPDATE saved_reports SET analyst_comment=? WHERE id=?")
            ->execute([$comment ?: null, $id]);
        $editMsg = 'Comment updated.';
    }
}

// ── Handle delete (analyst own reports or superadmin any) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_report'])) {
    requireRole('superadmin', 'analyst');
    $id = (int)($_POST['report_id'] ?? 0);
    if ($id > 0) {
        $where  = ($role === 'superadmin') ? 'id=?' : 'id=? AND created_by=?';
        $params = ($role === 'superadmin') ? [$id] : [$id, $uid];
        $pdo->prepare("DELETE FROM saved_reports WHERE $where")->execute($params);
        header('Location: /saved-reports.php');
        exit;
    }
}

// ── Single report view (?slug=…) ──────────────────────────────────────────
$slug = trim($_GET['slug'] ?? '');
if ($slug !== '') {
    $stmt = $pdo->prepare(
        "SELECT sr.*, u.username AS author
         FROM saved_reports sr
         JOIN users u ON u.id = sr.created_by
         WHERE sr.slug = ?"
    );
    $stmt->execute([$slug]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) { http_response_code(404); include __DIR__ . '/404.php'; exit; }

    $filters = json_decode($report['filters'] ?? '{}', true);
    $days    = (int)($filters['days'] ?? 30);

    $liveLinks = [
        'traffic'     => "/reports/traffic.php?days=$days",
        'performance' => "/reports/performance.php?days=$days",
        'errors'      => "/reports/errors.php?days=$days",
    ];
    $catColors = ['traffic'=>'#2563eb','performance'=>'#059669','errors'=>'#dc2626'];
    $catColor  = $catColors[$report['category']] ?? '#6b7280';
    $canEdit   = ($role === 'superadmin' || ($role === 'analyst' && (int)$report['created_by'] === $uid));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $e($report['title']) ?> – Saved Reports</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #1f2937; }
    .topbar { background: #111827; color: #fff; padding: 12px 20px; display: flex;
              justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
    .topbar h1 { margin: 0; font-size: 1.1rem; }
    .topbar nav { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
    .topbar a { color: #e5e7eb; text-decoration: none; font-weight: 600; font-size: .9rem; }
    .topbar a:hover, .topbar a.active { color: #fff; text-decoration: underline; }
    .topbar .user { color: #9ca3af; font-size: .85rem; }
    .role-badge { display: inline-block; font-size:.75rem; padding:1px 7px; border-radius:99px; font-weight:700; margin-left:4px; }
    .role-superadmin { background:#7c3aed; color:#fff; }
    .role-analyst    { background:#0284c7; color:#fff; }
    .role-viewer     { background:#6b7280; color:#fff; }
    .container { max-width: 860px; margin: 28px auto; padding: 0 16px; }
    article.card, aside.card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); padding: 28px; margin-bottom: 16px; }
    .report-header { border-left: 5px solid <?= $e($catColor) ?>; padding-left: 16px; margin-bottom: 20px; }
    .report-header h2 { margin: 0 0 4px; font-size: 1.4rem; }
    .meta { font-size: .85rem; color: #6b7280; margin-bottom: 12px; }
    .badge { display: inline-block; font-size:.75rem; padding:2px 10px; border-radius:99px;
             background: <?= $e($catColor) ?>; color:#fff; font-weight:700; text-transform:uppercase; margin-right:8px; }
    .comment-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 16px;
                   font-size: .95rem; line-height: 1.6; white-space: pre-wrap; color: #374151; }
    .comment-box.empty { color: #9ca3af; font-style: italic; }
    .btn { display: inline-block; padding: 8px 18px; border-radius: 6px; font-size: .875rem;
           font-weight: 600; text-decoration: none; border: none; cursor: pointer; }
    .btn-primary { background: #2563eb; color: #fff; }
    .btn-primary:hover { background: #1d4ed8; }
    .btn-secondary { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
    .btn-secondary:hover { background: #e5e7eb; }
    .edit-form textarea { width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px;
                          font-size:.9rem; font-family:inherit; resize:vertical; min-height:100px; }
    .filters-list { display: flex; gap: 12px; flex-wrap: wrap; }
    .filter-tag { background: #eff6ff; color: #1d4ed8; border-radius: 4px; padding: 3px 10px; font-size: .8rem; }
    .actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 20px; }
    .msg { padding: 8px 14px; background: #d1fae5; border: 1px solid #6ee7b7; border-radius: 6px;
           font-size: .875rem; color: #065f46; }
  </style>
</head>
<body>
<?php $currentSection = 'saved'; require_once __DIR__ . '/includes/nav.php'; ?>
<main class="container">
  <?php if ($editMsg): ?><div class="msg" style="margin-bottom:12px"><?= $e($editMsg) ?></div><?php endif; ?>

  <article class="card">
    <div class="report-header">
      <h2><?= $e($report['title']) ?></h2>
      <div class="meta">
        <span class="badge"><?= $e($report['category']) ?></span>
        Saved by <strong><?= $e($report['author']) ?></strong>
        on <?= date('M j, Y', strtotime($report['created_at'])) ?>
        <?php if ($report['updated_at'] !== $report['created_at']): ?>
          · updated <?= date('M j, Y', strtotime($report['updated_at'])) ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($filters)): ?>
    <div style="margin-bottom:20px">
      <strong style="font-size:.85rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">Filters</strong>
      <div class="filters-list" style="margin-top:6px">
        <?php foreach ($filters as $k => $v): ?>
          <span class="filter-tag"><?= $e($k) ?>: <?= $e($v) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <strong style="font-size:.85rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">Analyst Comment</strong>
    <div class="comment-box <?= $report['analyst_comment'] ? '' : 'empty' ?>" style="margin-top:8px">
      <?= $report['analyst_comment'] ? $e($report['analyst_comment']) : 'No comment added.' ?>
    </div>

    <div class="actions">
      <?php if (canAccessSection($report['category'])): ?>
        <a href="<?= $e($liveLinks[$report['category']] ?? '#') ?>" class="btn btn-primary">View Live Report →</a>
      <?php endif; ?>
      <a href="/saved-reports.php" class="btn btn-secondary">← All Saved Reports</a>
      <?php if ($canEdit): ?>
        <button onclick="document.getElementById('edit-form').hidden=!document.getElementById('edit-form').hidden"
                class="btn btn-secondary">Edit Comment</button>
        <form method="post" onsubmit="return confirm('Delete this report?');" style="margin:0">
          <input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>">
          <button name="delete_report" class="btn" style="background:#fef2f2;color:#b91c1c;border:1px solid #fca5a5">Delete</button>
        </form>
      <?php endif; ?>
    </div>
  </article>

  <?php if ($canEdit): ?>
  <aside id="edit-form" class="card" hidden>
    <h3 style="margin:0 0 12px;font-size:1rem">Edit Analyst Comment</h3>
    <form method="post" class="edit-form">
      <input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>">
      <textarea name="analyst_comment" placeholder="Write your analysis, insights, or notes…"><?= $e($report['analyst_comment'] ?? '') ?></textarea>
      <div style="margin-top:10px">
        <button name="edit_comment" class="btn btn-primary">Save Comment</button>
      </div>
    </form>
  </aside>
  <?php endif; ?>
</main>
</body>
</html>
<?php
    exit;
}

// ── List view ─────────────────────────────────────────────────────────────
$filterCat = $_GET['category'] ?? '';
$validCats = ['traffic', 'performance', 'errors'];
if (!in_array($filterCat, $validCats, true)) $filterCat = '';

$params = [];
$where  = '';
if ($filterCat !== '') {
    $where    = "WHERE sr.category = ?";
    $params[] = $filterCat;
}

$reports = $pdo->prepare(
    "SELECT sr.*, u.username AS author
     FROM saved_reports sr
     JOIN users u ON u.id = sr.created_by
     $where
     ORDER BY sr.created_at DESC"
);
$reports->execute($params);
$rows = $reports->fetchAll(PDO::FETCH_ASSOC);

$catColors = ['traffic'=>'#2563eb','performance'=>'#059669','errors'=>'#dc2626'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Saved Reports – Analytics Reporting</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #1f2937; }
    .topbar { background: #111827; color: #fff; padding: 12px 20px; display: flex;
              justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
    .topbar h1 { margin: 0; font-size: 1.1rem; }
    .topbar nav { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
    .topbar a { color: #e5e7eb; text-decoration: none; font-weight: 600; font-size: .9rem; }
    .topbar a:hover, .topbar a.active { color: #fff; text-decoration: underline; }
    .topbar .user { color: #9ca3af; font-size: .85rem; }
    .role-badge { display: inline-block; font-size:.75rem; padding:1px 7px; border-radius:99px; font-weight:700; margin-left:4px; }
    .role-superadmin { background:#7c3aed; color:#fff; }
    .role-analyst    { background:#0284c7; color:#fff; }
    .role-viewer     { background:#6b7280; color:#fff; }
    .container { max-width: 900px; margin: 28px auto; padding: 0 16px; }
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
    .page-header h2 { margin: 0; font-size: 1.25rem; }
    .filter-bar { display: flex; gap: 8px; flex-wrap: wrap; }
    .filter-bar a { padding: 5px 14px; border-radius: 99px; font-size: .8rem; font-weight: 600;
                    text-decoration: none; border: 1px solid #d1d5db; color: #374151; background: #fff; }
    .filter-bar a:hover { background: #f3f4f6; }
    .filter-bar a.active { background: #111827; color: #fff; border-color: #111827; }
    .report-card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08);
                   padding: 18px 20px; margin-bottom: 12px; display: flex; gap: 16px; align-items: flex-start; }
    .report-card .stripe { width: 4px; border-radius: 4px; flex-shrink: 0; align-self: stretch; }
    .report-card .body { flex: 1; min-width: 0; }
    .report-card h3 { margin: 0 0 4px; font-size: 1rem; }
    .report-card h3 a { text-decoration: none; color: #111827; }
    .report-card h3 a:hover { text-decoration: underline; color: #2563eb; }
    .meta { font-size: .8rem; color: #6b7280; }
    .badge { display: inline-block; font-size:.7rem; padding:2px 8px; border-radius:99px;
             color:#fff; font-weight:700; text-transform:uppercase; margin-right:6px; }
    .comment-preview { font-size: .875rem; color: #6b7280; margin-top: 6px;
                       white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
    .btn { display: inline-block; padding: 5px 14px; border-radius: 5px; font-size: .8rem;
           font-weight: 600; text-decoration: none; border: none; cursor: pointer; }
    .btn-primary { background: #2563eb; color: #fff; }
    .btn-primary:hover { background: #1d4ed8; }
    .btn-secondary { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
    .btn-secondary:hover { background: #e5e7eb; }
    .empty { text-align: center; padding: 60px 0; color: #9ca3af; }
    .empty strong { display: block; font-size: 1.1rem; margin-bottom: 8px; color: #6b7280; }
    section.card { background:#fff; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08); padding:28px; }
  </style>
</head>
<body>
<?php $currentSection = 'saved'; require_once __DIR__ . '/includes/nav.php'; ?>
<main class="container">
  <div class="page-header">
    <h2>Saved Reports</h2>
    <div class="filter-bar">
      <a href="/saved-reports.php" class="<?= $filterCat==='' ? 'active' : '' ?>">All</a>
      <?php foreach ($validCats as $cat): ?>
        <a href="/saved-reports.php?category=<?= $cat ?>"
           class="<?= $filterCat===$cat ? 'active' : '' ?>"
           style="<?= $filterCat===$cat ? 'background:'.($catColors[$cat]??'#111').';color:#fff;border-color:'.($catColors[$cat]??'#111') : '' ?>">
          <?= ucfirst($cat) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (empty($rows)): ?>
  <section class="card">
    <div class="empty">
      <strong>No saved reports yet.</strong>
      <?php if ($role !== 'viewer'): ?>
        Analysts can save snapshots from the
        <a href="/reports/traffic.php">Traffic</a>,
        <a href="/reports/performance.php">Performance</a>, and
        <a href="/reports/errors.php">Errors</a> report pages.
      <?php else: ?>
        When analysts publish a report snapshot, it will appear here.
      <?php endif; ?>
    </div>
  </section>
  <?php else: ?>
    <?php foreach ($rows as $r):
      $color   = $catColors[$r['category']] ?? '#6b7280';
      $canEdit = ($role === 'superadmin' || ($role === 'analyst' && (int)$r['created_by'] === $uid));
      $c = $r['analyst_comment'] ?? '';
      $preview = $c !== '' ? (strlen($c) > 160 ? substr($c, 0, 159) . '…' : $c) : '';
    ?>
    <article class="report-card">
      <div class="stripe" style="background:<?= $e($color) ?>"></div>
      <div class="body">
        <h3><a href="/saved-reports.php?slug=<?= $e($r['slug']) ?>"><?= $e($r['title']) ?></a></h3>
        <div class="meta">
          <span class="badge" style="background:<?= $e($color) ?>"><?= $e($r['category']) ?></span>
          by <strong><?= $e($r['author']) ?></strong>
          · <?= date('M j, Y', strtotime($r['created_at'])) ?>
        </div>
        <?php if ($preview): ?>
          <div class="comment-preview"><?= $e($preview) ?></div>
        <?php endif; ?>
        <div class="actions">
          <a href="/saved-reports.php?slug=<?= $e($r['slug']) ?>" class="btn btn-primary">View →</a>
          <?php if ($canEdit): ?>
            <form method="post" onsubmit="return confirm('Delete this report?');" style="margin:0">
              <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
              <button name="delete_report" class="btn btn-secondary" style="color:#b91c1c">Delete</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  <?php endif; ?>
</main>
</body>
</html>
