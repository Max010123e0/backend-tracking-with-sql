<?php
require_once __DIR__ . '/auth.php';

// Already logged in → go to appropriate landing page
if (isLoggedIn()) {
    header('Location: ' . (currentRole() === 'viewer' ? '/saved-reports.php' : '/dashboard.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (attemptLogin($username, $password)) {
        header('Location: ' . (currentRole() === 'viewer' ? '/saved-reports.php' : '/dashboard.php'));
        exit;
    }

    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login – Analytics Reporting</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0; font-family: Arial, sans-serif;
      background: #f4f6f8; display: flex;
      align-items: center; justify-content: center; min-height: 100vh;
    }
    .card {
      background: #fff; border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,.1);
      padding: 32px 28px; width: 100%; max-width: 400px;
    }
    h1 { margin: 0 0 6px; font-size: 1.3rem; color: #111827; }
    p  { margin: 0 0 24px; font-size: .9rem; color: #6b7280; }
    label { display: block; font-size: .85rem; font-weight: 600;
            margin-bottom: 4px; color: #374151; }
    input[type=text], input[type=password] {
      width: 100%; padding: 9px 10px; border: 1px solid #d1d5db;
      border-radius: 6px; font-size: 1rem; margin-bottom: 14px;
    }
    input:focus { outline: none; border-color: #2563eb;
                  box-shadow: 0 0 0 2px rgba(37,99,235,.2); }
    button {
      width: 100%; padding: 10px; background: #2563eb; color: #fff;
      border: none; border-radius: 6px; font-size: 1rem;
      font-weight: 600; cursor: pointer;
    }
    button:hover { background: #1d4ed8; }
    .error {
      background: #fef2f2; border: 1px solid #fca5a5;
      color: #b91c1c; padding: 8px 12px; border-radius: 6px;
      margin-bottom: 14px; font-size: .9rem;
    }
  </style>
</head>
<body>
  <div class="card">
    <h1>Analytics Reporting</h1>
    <p>Sign in to access the reporting dashboard.</p>

    <?php if ($error): ?>
      <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="/login.php">
      <label for="username">Username</label>
      <input id="username" name="username" type="text" required autofocus
             value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

      <label for="password">Password</label>
      <input id="password" name="password" type="password" required>

      <button type="submit">Sign In</button>
    </form>
  </div>
</body>
</html>
