<?php
http_response_code(404);
if (session_status() === PHP_SESSION_NONE) session_start();
if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/auth.php';
}
$_home = match(currentRole()) {
    'superadmin', 'analyst' => '/dashboard.php',
    'viewer'                => '/saved-reports.php',
    default                 => null,
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>404 Not Found – Analytics Reporting</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0; font-family: Arial, sans-serif;
      background: #f4f6f8; color: #1f2937;
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh;
    }
    main {
      background: #fff; border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,.08);
      padding: 40px 36px; max-width: 480px; width: 100%; text-align: center;
    }
    .code {
      font-size: 4rem; font-weight: 800;
      color: #6b7280; line-height: 1; margin: 0 0 8px;
    }
    h1 { margin: 0 0 12px; font-size: 1.4rem; color: #111827; }
    p  { margin: 0 0 24px; color: #6b7280; font-size: .95rem; line-height: 1.5; }
    .btn {
      display: inline-block; padding: 10px 22px;
      background: #2563eb; color: #fff; border-radius: 6px;
      text-decoration: none; font-weight: 600; font-size: .95rem;
      margin: 0 6px 8px;
    }
    .btn:hover { background: #1d4ed8; }
    .btn-ghost {
      background: #e5e7eb; color: #374151;
    }
    .btn-ghost:hover { background: #d1d5db; }
    code.url {
      display: inline-block; background: #f3f4f6; border-radius: 4px;
      padding: 4px 10px; font-family: monospace; font-size: .85rem;
      color: #374151; margin-bottom: 20px; word-break: break-all;
    }
  </style>
</head>
<body>
  <main>
    <p class="code">404</p>
    <h1>Page Not Found</h1>
    <p>The page you're looking for doesn't exist or has been moved.</p>
    <?php if (!empty($_SERVER['REQUEST_URI'])): ?>
      <code class="url"><?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8') ?></code>
    <?php endif; ?>
    <?php if ($_home): ?>
      <a href="<?= htmlspecialchars($_home, ENT_QUOTES, 'UTF-8') ?>" class="btn">Go to Home</a>
      <form method="post" action="/logout.php" style="display:inline">
        <button type="submit" class="btn btn-ghost">Sign Out</button>
      </form>
    <?php else: ?>
      <a href="/login.php" class="btn">Sign In</a>
    <?php endif; ?>
  </main>
</body>
</html>
