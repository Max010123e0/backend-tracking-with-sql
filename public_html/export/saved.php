<?php
/**
 * List of saved PDF exports.
 * /export/saved.php
 * Accessible to analysts and superadmins (not viewers).
 */
require_once __DIR__ . '/../auth.php';
requireRole('superadmin', 'analyst');

require_once '/var/www/collector.maxk.site/db_config.php';
$pdo  = getDbConnection();
$role = currentRole();
$uid  = currentUserId();

// Superadmin sees all exports; analyst sees only their own
if ($role === 'superadmin') {
    $stmt = $pdo->query(
        "SELECT pe.*, u.username AS author
         FROM pdf_exports pe
         JOIN users u ON u.id = pe.created_by
         ORDER BY pe.created_at DESC"
    );
} else {
    $stmt = $pdo->prepare(
        "SELECT pe.*, u.username AS author
         FROM pdf_exports pe
         JOIN users u ON u.id = pe.created_by
         WHERE pe.created_by = ?
         ORDER BY pe.created_at DESC"
    );
    $stmt->execute([$uid]);
}
$exports = $stmt->fetchAll(PDO::FETCH_ASSOC);

$reportColors = ['traffic'=>'#2563eb','performance'=>'#059669','errors'=>'#dc2626'];
$currentSection = 'exports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Saved PDF Exports · Analytics</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f3f4f6;color:#1f2937;min-height:100vh}
    .topbar{background:#111827;color:#fff;padding:0 24px;display:flex;align-items:center;justify-content:space-between;height:52px;gap:16px}
    .topbar h1{font-size:1rem;font-weight:600;white-space:nowrap}
    .topbar nav{display:flex;gap:4px;align-items:center;flex-wrap:wrap}
    .topbar nav a{color:#d1d5db;text-decoration:none;padding:6px 10px;border-radius:4px;font-size:.875rem;transition:background .15s}
    .topbar nav a:hover,.topbar nav a.active{background:#374151;color:#fff}
    .topbar .user{font-size:.8rem;color:#9ca3af;white-space:nowrap}
    .topbar .logout{color:#f87171;font-size:.8rem;text-decoration:none;padding:4px 8px;border:1px solid #f87171;border-radius:4px}
    .topbar .logout:hover{background:#f87171;color:#fff}
    main{max-width:960px;margin:32px auto;padding:0 16px}
    h2{font-size:1.25rem;font-weight:700;margin-bottom:20px;color:#111827}
    .empty{text-align:center;padding:60px 20px;color:#6b7280}
    .empty p{margin-top:8px;font-size:.9rem}
    table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1)}
    th{background:#111827;color:#fff;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;padding:10px 14px;text-align:left}
    td{padding:10px 14px;font-size:.875rem;border-bottom:1px solid #f3f4f6;vertical-align:middle}
    tr:last-child td{border-bottom:none}
    tr:hover td{background:#f9fafb}
    .badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:.75rem;font-weight:600;color:#fff}
    .actions{display:flex;gap:8px;align-items:center}
    .btn{display:inline-block;padding:5px 12px;border-radius:5px;font-size:.8rem;font-weight:500;text-decoration:none;cursor:pointer;border:none;transition:opacity .15s}
    .btn:hover{opacity:.85}
    .btn-blue{background:#2563eb;color:#fff}
    .btn-gray{background:#e5e7eb;color:#374151}
    .btn-red{background:#ef4444;color:#fff;font-size:.75rem;padding:4px 10px}
    .url-box{display:flex;align-items:center;gap:6px}
    .url-text{font-family:monospace;font-size:.78rem;color:#4b5563;background:#f3f4f6;padding:3px 7px;border-radius:4px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .copy-btn{font-size:.72rem;padding:3px 7px;background:#e0e7ff;color:#3730a3;border:none;border-radius:4px;cursor:pointer}
    .copy-btn:hover{background:#c7d2fe}
    noscript .url-box .copy-btn{display:none}
  </style>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>

<main>
  <h2>Saved PDF Exports</h2>

  <?php if (empty($exports)): ?>
    <div class="empty">
      <svg width="40" height="40" fill="none" stroke="#9ca3af" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
      <p>No PDFs exported yet. Go to a report page and click <strong>Export PDF</strong>.</p>
    </div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Report</th>
          <th>Days</th>
          <?php if ($role === 'superadmin'): ?><th>By</th><?php endif; ?>
          <th>Generated</th>
          <th>Shareable URL</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($exports as $ex):
            $color = $reportColors[$ex['report']] ?? '#6b7280';
            $url   = 'https://' . $_SERVER['HTTP_HOST'] . '/export/serve.php?token=' . rawurlencode($ex['token']);
            $label = ucfirst($ex['report']);
        ?>
        <tr>
          <td><span class="badge" style="background:<?= htmlspecialchars($color) ?>"><?= htmlspecialchars($label) ?></span></td>
          <td><?= (int)$ex['days'] ?> days</td>
          <?php if ($role === 'superadmin'): ?>
          <td><?= htmlspecialchars($ex['author'], ENT_QUOTES, 'UTF-8') ?></td>
          <?php endif; ?>
          <td><?= date('M j, Y H:i', strtotime($ex['created_at'])) ?></td>
          <td>
            <div class="url-box">
              <span class="url-text" title="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?></span>
              <button class="copy-btn" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>').then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500)})">Copy</button>
            </div>
            <noscript><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="font-size:.78rem">Open PDF</a></noscript>
          </td>
          <td>
            <div class="actions">
              <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-blue">View PDF</a>
              <?php if ($role === 'superadmin' || (int)$ex['created_by'] === $uid): ?>
              <form method="post" action="/export/delete.php" onsubmit="return confirm('Delete this PDF?')">
                <input type="hidden" name="token" value="<?= htmlspecialchars($ex['token'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-red">Delete</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</main>
</body>
</html>
