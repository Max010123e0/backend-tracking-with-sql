<?php
/**
 * Sessions API Endpoint
 * Handles CRUD operations for user sessions
 * 
 * Routes:
 * GET    /api/sessions         - Get all sessions
 * GET    /api/sessions/{id}    - Get specific session by ID
 * POST   /api/sessions         - Create new session
 * PUT    /api/sessions/{id}    - Update session by ID
 * DELETE /api/sessions/{id}    - Delete session by ID
 */

require_once __DIR__ . '/../../db_config.php';

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? '/';
$pathParts = explode('/', trim($path, '/'));
$id = $pathParts[0] ?? null;

$pdo = getDbConnection();

try {
    switch ($method) {
        case 'GET':
            if ($id) {
                // GET /api/sessions/{id} - Get specific session
                $stmt = $pdo->prepare("SELECT * FROM sessions WHERE id = ?");
                $stmt->execute([$id]);
                $session = $stmt->fetch();
                
                if (!$session) {
                    sendError('Session not found', 404);
                }
                
                sendJson($session);
            } else {
                // GET /api/sessions - Get all sessions
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
                $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
                
                $stmt = $pdo->prepare("
                    SELECT * FROM sessions 
                    ORDER BY last_seen DESC 
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$limit, $offset]);
                $sessions = $stmt->fetchAll();
                
                // Get total count
                $countStmt = $pdo->query("SELECT COUNT(*) as total FROM sessions");
                $total = $countStmt->fetch()['total'];
                
                sendJson([
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'sessions' => $sessions
                ]);
            }
            break;
            
        case 'POST':
            // POST /api/sessions - Create new session
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['session_id'])) {
                sendError('Missing required field: session_id');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO sessions (session_id, first_seen, last_seen, page_count)
                VALUES (?, ?, ?, ?)
            ");
            
            $timestamp = $input['timestamp'] ?? date('Y-m-d H:i:s');
            
            $stmt->execute([
                $input['session_id'],
                $timestamp,
                $timestamp,
                $input['page_count'] ?? 1
            ]);
            
            sendJson([
                'success' => true,
                'id' => $pdo->lastInsertId(),
                'message' => 'Session created successfully'
            ], 201);
            break;
            
        case 'PUT':
            // PUT /api/sessions/{id} - Update session
            if (!$id) {
                sendError('Session ID required for update');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Check if session exists
            $stmt = $pdo->prepare("SELECT id FROM sessions WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                sendError('Session not found', 404);
            }
            
            // Build update query
            $updates = [];
            $params = [];
            
            $allowedFields = ['session_id', 'first_seen', 'last_seen', 'page_count'];
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $input[$field];
                }
            }
            
            if (empty($updates)) {
                sendError('No fields to update');
            }
            
            $params[] = $id;
            $sql = "UPDATE sessions SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            sendJson([
                'success' => true,
                'message' => 'Session updated successfully'
            ]);
            break;
            
        case 'DELETE':
            // DELETE /api/sessions/{id} - Delete session
            if (!$id) {
                sendError('Session ID required for deletion');
            }
            
            $stmt = $pdo->prepare("DELETE FROM sessions WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                sendError('Session not found', 404);
            }
            
            sendJson([
                'success' => true,
                'message' => 'Session deleted successfully'
            ]);
            break;
            
        default:
            sendError('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Sessions API error: " . $e->getMessage());
    sendError('Internal server error', 500);
}
