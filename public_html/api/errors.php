<?php
/**
 * Errors API Endpoint
 * Read-only access to error events
 * 
 * Routes:
 * GET    /api/errors         - Get all errors
 * GET    /api/errors/{id}    - Get specific error by ID
 */

require_once __DIR__ . '/../../db_config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Only GET method is allowed for errors', 405);
}

$path = $_SERVER['PATH_INFO'] ?? '/';
$pathParts = explode('/', trim($path, '/'));
$id = $pathParts[0] ?? null;

$pdo = getDbConnection();

try {
    if ($id) {
        // GET /api/errors/{id}
        $stmt = $pdo->prepare("SELECT * FROM errors WHERE id = ?");
        $stmt->execute([$id]);
        $error = $stmt->fetch();
        
        if (!$error) {
            sendError('Error not found', 404);
        }
        
        // Decode JSON fields
        foreach (['error_type', 'error_message', 'error_source', 'error_line', 'error_stack'] as $field) {
            if (isset($error[$field])) {
                $error[$field] = json_decode($error[$field], true);
            }
        }
        
        sendJson($error);
    } else {
        // GET /api/errors
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        $stmt = $pdo->prepare("
            SELECT * FROM errors 
            ORDER BY timestamp DESC 
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $errors = $stmt->fetchAll();
        
        // Decode JSON fields
        foreach ($errors as &$error) {
            foreach (['error_type', 'error_message', 'error_source', 'error_line', 'error_stack'] as $field) {
                if (isset($error[$field])) {
                    $error[$field] = json_decode($error[$field], true);
                }
            }
        }
        
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM errors");
        $total = $countStmt->fetch()['total'];
        
        sendJson([
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'errors' => $errors
        ]);
    }
} catch (Exception $e) {
    error_log("Errors API error: " . $e->getMessage());
    sendError('Internal server error', 500);
}
