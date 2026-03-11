<?php
/**
 * Shared topbar + nav for all reporting pages.
 * Expects $currentSection (string) and auth.php already loaded.
 */
$_role     = currentRole();
$_user     = currentUser();
$_sections = allowedSections();
$_navLinks = [
    'traffic'     => ['href' => '/reports/traffic.php',     'label' => 'Traffic'],
    'performance' => ['href' => '/reports/performance.php', 'label' => 'Performance'],
    'errors'      => ['href' => '/reports/errors.php',      'label' => 'Errors'],
];
?>
<header class="topbar">
  <h1>Analytics Reporting</h1>
  <nav>
    <?php if ($_role !== 'viewer'): ?>
      <a href="/dashboard.php" <?= ($currentSection??'')==='dashboard'?'class="active"':'' ?>>Dashboard</a>
      <?php foreach ($_navLinks as $slug => $link):
        if ($_role === 'superadmin' || in_array($slug, $_sections, true)): ?>
        <a href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"
           <?= ($currentSection??'')===$slug ? 'class="active"' : '' ?>>
          <?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>
        </a>
      <?php endif; endforeach; ?>
    <?php endif; ?>
    <a href="/saved-reports.php" <?= ($currentSection??'')==='saved'?'class="active"':'' ?>>Saved Reports</a>
    <?php if ($_role !== 'viewer'): ?>
      <a href="/export/saved.php" <?= ($currentSection??'')==='exports'?'class="active"':'' ?>>Saved PDFs</a>
    <?php endif; ?>
    <?php if ($_role === 'superadmin'): ?>
      <a href="/admin/users.php">Users</a>
    <?php endif; ?>
    <span class="user"><?= htmlspecialchars($_user, ENT_QUOTES, 'UTF-8') ?>
      <span class="role-badge role-<?= htmlspecialchars($_role, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($_role, ENT_QUOTES, 'UTF-8') ?></span>
    </span>
    <a href="/logout.php">Logout</a>
  </nav>
</header>
