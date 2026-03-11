<?php
/**
 * Serve a saved PDF export.
 * /export/serve.php?token=XXXX
 * Any logged-in user (including viewers) can view exported PDFs via their token.
 */
require_once __DIR__ . '/../auth.php';
requireLogin();

require_once '/var/www/collector.maxk.site/db_config.php';

$token = trim($_GET['token'] ?? '');
if (!preg_match('/^[0-9a-f]{32}$/i', $token)) {
    http_response_code(400);
    exit('Invalid token.');
}

$pdo = getDbConnection();
$stmt = $pdo->prepare(
    "SELECT pe.*, u.username AS author
     FROM pdf_exports pe
     JOIN users u ON u.id = pe.created_by
     WHERE pe.token = ?"
);
$stmt->execute([$token]);
$export = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$export) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

$filepath = '/var/www/reporting.maxk.site/pdf_storage/' . basename($export['filename']);
if (!is_file($filepath)) {
    http_response_code(404);
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

// Serve inline so the browser opens it (not forces download).
// The URL itself is then shareable with any logged-in user.
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . addslashes($export['filename']) . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, max-age=3600');
readfile($filepath);
exit;
