<?php
/**
 * Delete a saved PDF export.
 * POST /export/delete.php  {token}
 */
require_once __DIR__ . '/../auth.php';
requireRole('superadmin', 'analyst');

require_once '/var/www/collector.maxk.site/db_config.php';
$pdo  = getDbConnection();
$role = currentRole();
$uid  = currentUserId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /export/saved.php');
    exit;
}

$token = trim($_POST['token'] ?? '');
if (!preg_match('/^[0-9a-f]{32}$/i', $token)) {
    http_response_code(400);
    exit('Invalid token.');
}

// Fetch record — superadmin can delete any; analyst only their own
if ($role === 'superadmin') {
    $stmt = $pdo->prepare("SELECT * FROM pdf_exports WHERE token = ?");
    $stmt->execute([$token]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM pdf_exports WHERE token = ? AND created_by = ?");
    $stmt->execute([$token, $uid]);
}
$export = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$export) {
    http_response_code(403);
    include __DIR__ . '/../403.php';
    exit;
}

// Delete file from disk
$filepath = '/var/www/reporting.maxk.site/pdf_storage/' . basename($export['filename']);
if (is_file($filepath)) {
    unlink($filepath);
}

// Delete DB record
$pdo->prepare("DELETE FROM pdf_exports WHERE token = ?")->execute([$token]);

header('Location: /export/saved.php');
exit;
