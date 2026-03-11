<?php
/**
 * Pageviews API Endpoint
 * Read-only access to pageview events
 * 
 * Routes:
 * GET    /api/pageviews         - Get all pageviews
 * GET    /api/pageviews/{id}    - Get specific pageview by ID
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
    sendError('Only GET method is allowed for pageviews', 405);
}

$path = $_SERVER['PATH_INFO'] ?? '/';
$pathParts = explode('/', trim($path, '/'));
$id = $pathParts[0] ?? null;

$pdo = getDbConnection();

try {
    if ($id) {
        // GET /api/pageviews/{id}
        $stmt = $pdo->prepare("SELECT * FROM pageviews WHERE id = ?");
        $stmt->execute([$id]);
        $pageview = $stmt->fetch();
        
        if (!$pageview) {
            sendError('Pageview not found', 404);
        }
        
        // Decode JSON fields
        foreach (['title', 'referrer', 'technographics', 'timing', 'resources'] as $field) {
            if (isset($pageview[$field])) {
                $pageview[$field] = json_decode($pageview[$field], true);
            }
        }
        
        sendJson($pageview);
    } else {
        // GET /api/pageviews
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        $stmt = $pdo->prepare("
            SELECT * FROM pageviews 
            ORDER BY timestamp DESC 
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $pageviews = $stmt->fetchAll();
        
        // Decode JSON fields
        foreach ($pageviews as &$pageview) {
            foreach (['title', 'referrer', 'technographics', 'timing', 'resources'] as $field) {
                if (isset($pageview[$field])) {
                    $pageview[$field] = json_decode($pageview[$field], true);
                }
            }
        }
        
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM pageviews");
        $total = $countStmt->fetch()['total'];
        
        sendJson([
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'pageviews' => $pageviews
        ]);
    }
} catch (Exception $e) {
    error_log("Pageviews API error: " . $e->getMessage());
    sendError('Internal server error', 500);
}
