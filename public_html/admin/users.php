<?php
require_once __DIR__ . '/../auth.php';
requireRole('superadmin');

require_once '/var/www/collector.maxk.site/db_config.php';
$pdo = getDbConnection();

$ALL_SECTIONS = ['traffic', 'performance', 'errors'];
$errors   = [];
$success  = '';
$editUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create user
    if ($action === 'create') {
        $uname    = trim($_POST['username'] ?? '');
        $pass     = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? '';
        $sections = $_POST['sections'] ?? [];

        if (!preg_match('/^[a-zA-Z0-9_]{3,64}$/', $uname))
            $errors[] = 'Username must be 3–64 alphanumeric characters.';
        if (strlen($pass) < 8)
            $errors[] = 'Password must be at least 8 characters.';
        if (!in_array($role, ['superadmin','analyst','viewer'], true))
            $errors[] = 'Invalid role.';

        if (empty($errors)) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->execute([$uname]);
            if ($stmt->fetch()) $errors[] = 'Username already exists.';
        }

        if (empty($errors)) {
            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            $ins  = $pdo->prepare(
                'INSERT INTO users (username, password_hash, role) VALUES (?,?,?)'
            );
            $ins->execute([$uname, $hash, $role]);
            $newId = (int) $pdo->lastInsertId();

            if ($role === 'analyst') {
                $valid = array_filter($sections,
                    fn($s) => in_array($s, $ALL_SECTIONS, true));
                foreach ($valid as $s) {
                    $pdo->prepare(
                        'INSERT IGNORE INTO user_sections (user_id,section) VALUES (?,?)'
                    )->execute([$newId, $s]);
                }
            }
            $success = "User \"$uname\" created.";
        }
    }

    // Update user
    elseif ($action === 'update') {
        $uid      = (int) ($_POST['user_id'] ?? 0);
        $role     = $_POST['role'] ?? '';
        $sections = $_POST['sections'] ?? [];
        $pass     = $_POST['password'] ?? '';

        if (!in_array($role, ['superadmin','analyst','viewer'], true))
            $errors[] = 'Invalid role.';
        if ($pass !== '' && strlen($pass) < 8)
            $errors[] = 'New password must be at least 8 characters.';

        if (empty($errors)) {
            if ($pass !== '') {
                $pdo->prepare('UPDATE users SET role=?, password_hash=? WHERE id=?')
                    ->execute([$role, password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]), $uid]);
            } else {
                $pdo->prepare('UPDATE users SET role=? WHERE id=?')
                    ->execute([$role, $uid]);
            }

            $pdo->prepare('DELETE FROM user_sections WHERE user_id=?')->execute([$uid]);
            if ($role === 'analyst') {
                $valid = array_filter($sections,
                    fn($s) => in_array($s, $ALL_SECTIONS, true));
                foreach ($valid as $s) {
                    $pdo->prepare(
                        'INSERT IGNORE INTO user_sections (user_id,section) VALUES (?,?)'
                    )->execute([$uid, $s]);
                }
            }
            $success = 'User updated.';
        }
    }

    // Delete user
    elseif ($action === 'delete') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        if ($uid === currentUserId()) {
            $errors[] = 'You cannot delete your own account.';
        } else {
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
            $success = 'User deleted.';
        }
    }
}

// Load edit target if requested
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editUser = $stmt->fetch() ?: null;
    if ($editUser) {
        $s = $pdo->prepare('SELECT section FROM user_sections WHERE user_id=?');
        $s->execute([$editUser['id']]);
        $editUser['sections'] = $s->fetchAll(\PDO::FETCH_COLUMN);
    }
}

// Load all users
$users = $pdo->query(
    'SELECT u.id, u.username, u.role, u.created_at,
            GROUP_CONCAT(us.section ORDER BY us.section SEPARATOR ", ") AS sections
     FROM users u
     LEFT JOIN user_sections us ON us.user_id = u.id
     GROUP BY u.id ORDER BY u.id'
)->fetchAll();

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Management – Analytics Reporting</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f8; color: #1f2937; }
    .topbar {
      background: #111827; color: #fff;
      padding: 12px 20px; display: flex; justify-content: space-between; align-items: center;
    }
    .topbar h1 { margin: 0; font-size: 1.1rem; }
    .topbar nav { display: flex; gap: 16px; align-items: center; }
    .topbar a { color: #e5e7eb; text-decoration: none; font-weight: 600; font-size: .9rem; }
    .topbar a:hover { text-decoration: underline; }
    .topbar .user { color: #9ca3af; font-size: .85rem; }
    .container { max-width: 1000px; margin: 28px auto; padding: 0 16px; }
    .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08);
            padding: 24px; margin-bottom: 20px; }
    .card h2 { margin: 0 0 16px; font-size: 1.05rem; color: #111827; }
    .alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: .9rem; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error   { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }
    label { display: block; font-size: .83rem; font-weight: 600; margin-bottom: 3px; color: #374151; }
    input[type=text], input[type=password], select {
      width: 100%; padding: 8px 10px; border: 1px solid #d1d5db;
      border-radius: 6px; font-size: .9rem; margin-bottom: 12px;
    }
    input:focus, select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,.2); }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .checkboxes { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 12px; }
    .checkboxes label { font-weight: 400; display: flex; align-items: center; gap: 5px; }
    .checkboxes input { width: auto; margin-bottom: 0; }
    .section-row { display: none; }
    .btn { padding: 8px 18px; border: none; border-radius: 6px; font-size: .9rem;
           font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
    .btn-primary { background: #2563eb; color: #fff; }
    .btn-primary:hover { background: #1d4ed8; }
    .btn-warning { background: #f59e0b; color: #fff; }
    .btn-warning:hover { background: #d97706; }
    .btn-danger  { background: #ef4444; color: #fff; }
    .btn-danger:hover  { background: #dc2626; }
    .btn-ghost   { background: #e5e7eb; color: #374151; }
    .btn-ghost:hover { background: #d1d5db; }
    table { width: 100%; border-collapse: collapse; font-size: .88rem; }
    thead th { background: #f9fafb; padding: 10px 12px; text-align: left;
               border-bottom: 2px solid #e5e7eb; font-size: .8rem;
               text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
    tbody td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
    tbody tr:hover { background: #f9fafb; }
    .badge { display: inline-block; padding: 2px 9px; border-radius: 9999px; font-size: .75rem; font-weight: 700; }
    .badge-superadmin { background: #dbeafe; color: #1e40af; }
    .badge-analyst    { background: #dcfce7; color: #166534; }
    .badge-viewer     { background: #f3f4f6; color: #374151; }
    .actions { display: flex; gap: 6px; }
    .hint { font-size: .8rem; color: #9ca3af; margin-top: -8px; margin-bottom: 12px; }
  </style>
</head>
<body>
<header class="topbar">
  <h1>Analytics Reporting</h1>
  <nav>
    <a href="/dashboard.php">Dashboard</a>
    <a href="/saved-reports.php">Saved Reports</a>
    <a href="/admin/users.php" style="color:#fff">Users</a>
    <span class="user">Signed in as <?= $e(currentUser()) ?> (superadmin)</span>
    <form method="post" action="/logout.php" style="display:inline"><button type="submit" style="background:none;border:none;color:#f87171;cursor:pointer;font-size:.875rem">Logout</button></form>
  </nav>
</header>

<main class="container">

  <?php if ($errors): ?>
    <p class="alert alert-error" role="alert"><?= implode('<br>', array_map($e, $errors)) ?></p>
  <?php elseif ($success): ?>
    <p class="alert alert-success" role="status"><?= $e($success) ?></p>
  <?php endif; ?>

  <?php if ($editUser): ?>
  <section class="card">
    <h2>Edit User: <?= $e($editUser['username']) ?></h2>
    <form method="post" action="/admin/users.php">
      <input type="hidden" name="action"  value="update">
      <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
      <div class="grid2">
        <div>
          <label>Role</label>
          <select name="role" id="edit-role" onchange="toggleSections('edit')">
            <?php foreach (['superadmin','analyst','viewer'] as $r): ?>
              <option value="<?= $r ?>" <?= $editUser['role']===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>New Password <span style="font-weight:400;color:#9ca3af">(leave blank to keep current)</span></label>
          <input type="password" name="password" autocomplete="new-password" placeholder="••••••••">
          <p class="hint">Minimum 8 characters.</p>
        </div>
      </div>
      <div id="edit-section-row" class="section-row">
        <label>Allowed Sections</label>
        <div class="checkboxes">
          <?php foreach ($ALL_SECTIONS as $s): ?>
            <label>
              <input type="checkbox" name="sections[]" value="<?= $s ?>"
                <?= in_array($s, $editUser['sections'], true) ? 'checked' : '' ?>>
              <?= ucfirst($s) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="/admin/users.php" class="btn btn-ghost" style="margin-left:8px">Cancel</a>
    </form>
  </section>
  <?php endif; ?>

  <!-- Create User Form -->
  <section class="card">
    <h2>Create New User</h2>
    <form method="post" action="/admin/users.php">
      <input type="hidden" name="action" value="create">
      <div class="grid2">
        <div>
          <label for="username">Username</label>
          <input id="username" name="username" type="text" required
                 pattern="[a-zA-Z0-9_]{3,64}" autocomplete="off"
                 value="<?= $e($_POST['username'] ?? '') ?>">
        </div>
        <div>
          <label for="password">Password</label>
          <input id="password" name="password" type="password" required autocomplete="new-password">
          <p class="hint">Minimum 8 characters.</p>
        </div>
      </div>
      <div class="grid2">
        <div>
          <label for="role">Role</label>
          <select id="role" name="role" onchange="toggleSections('create')">
            <option value="viewer">Viewer</option>
            <option value="analyst">Analyst</option>
            <option value="superadmin">Super Admin</option>
          </select>
        </div>
      </div>
      <div id="create-section-row" class="section-row">
        <label>Allowed Sections</label>
        <div class="checkboxes">
          <?php foreach ($ALL_SECTIONS as $s): ?>
            <label>
              <input type="checkbox" name="sections[]" value="<?= $s ?>">
              <?= ucfirst($s) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Create User</button>
    </form>
  </section>

  <!-- User Table -->
  <section class="card">
    <h2>All Users</h2>
    <table>
      <thead>
        <tr>
          <th>ID</th><th>Username</th><th>Role</th>
          <th>Sections</th><th>Created</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><?= (int)$u['id'] ?></td>
          <td><strong><?= $e($u['username']) ?></strong>
              <?= $u['id'] === currentUserId() ? ' <span style="color:#9ca3af;font-size:.78rem">(you)</span>' : '' ?>
          </td>
          <td><span class="badge badge-<?= $e($u['role']) ?>"><?= $e($u['role']) ?></span></td>
          <td><?= $e($u['sections'] ?? '—') ?></td>
          <td><?= $e(substr($u['created_at'], 0, 10)) ?></td>
          <td>
            <menu class="actions">
              <a href="/admin/users.php?edit=<?= (int)$u['id'] ?>" class="btn btn-warning" style="font-size:.8rem;padding:5px 12px">Edit</a>
              <?php if ($u['id'] !== currentUserId()): ?>
              <form method="post" action="/admin/users.php"
                    onsubmit="return confirm('Delete user <?= $e($u['username']) ?>?')">
                <input type="hidden" name="action"  value="delete">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="btn btn-danger" style="font-size:.8rem;padding:5px 12px">Delete</button>
              </form>
              <?php endif; ?>
            </menu>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

</main>

<script>
  const ALL_SECTIONS = <?= json_encode($ALL_SECTIONS) ?>;

  function toggleSections(prefix) {
    const role = document.getElementById(prefix + '-role')?.value
               ?? document.getElementById('role')?.value;
    const row  = document.getElementById(prefix + '-section-row');
    if (row) row.style.display = (role === 'analyst') ? 'block' : 'none';
  }

  // Init on load
  toggleSections('create');
  toggleSections('edit');
</script>
</body>
</html>
