<?php
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
                // GET /api/events/{id} - Get specific event
                $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
                $stmt->execute([$id]);
                $event = $stmt->fetch();
                
                if (!$event) {
                    sendError('Event not found', 404);
                }
                
                // Decode JSON data field
                if (isset($event['data'])) {
                    $event['data'] = json_decode($event['data'], true);
                }
                
                sendJson($event);
            } else {
                // GET /api/events - Get all events with optional filters
                $filters = [];
                $params = [];
                
                // Support query parameters for filtering
                if (isset($_GET['event_type'])) {
                    $filters[] = "event_type = ?";
                    $params[] = $_GET['event_type'];
                }
                
                if (isset($_GET['session_id'])) {
                    $filters[] = "session_id = ?";
                    $params[] = $_GET['session_id'];
                }
                
                if (isset($_GET['url'])) {
                    $filters[] = "url LIKE ?";
                    $params[] = '%' . $_GET['url'] . '%';
                }
                
                // Pagination
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
                $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
                
                $whereClause = $filters ? 'WHERE ' . implode(' AND ', $filters) : '';
                $sql = "SELECT * FROM events $whereClause ORDER BY timestamp DESC LIMIT ? OFFSET ?";
                
                $params[] = $limit;
                $params[] = $offset;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $events = $stmt->fetchAll();
                
                // Decode JSON data field for each event
                foreach ($events as &$event) {
                    if (isset($event['data'])) {
                        $event['data'] = json_decode($event['data'], true);
                    }
                }
                
                // Get total count
                $countSql = "SELECT COUNT(*) as total FROM events $whereClause";
                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute(array_slice($params, 0, count($params) - 2));
                $total = $countStmt->fetch()['total'];
                
                sendJson([
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'events' => $events
                ]);
            }
            break;
            
        case 'POST':
            // POST /api/events - Create new event
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['session_id']) || !isset($input['event_type'])) {
                sendError('Missing required fields: session_id, event_type');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO events (session_id, event_type, url, timestamp, data)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $timestamp = $input['timestamp'] ?? date('Y-m-d H:i:s');
            $data = $input;
            unset($data['session_id'], $data['event_type'], $data['url'], $data['timestamp']);
            
            $stmt->execute([
                $input['session_id'],
                $input['event_type'],
                $input['url'] ?? null,
                $timestamp,
                json_encode($data)
            ]);
            
            sendJson([
                'success' => true,
                'id' => $pdo->lastInsertId(),
                'message' => 'Event created successfully'
            ], 201);
            break;
            
        case 'PUT':
            // PUT /api/events/{id} - Update event
            if (!$id) {
                sendError('Event ID required for update');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Check if event exists
            $stmt = $pdo->prepare("SELECT id FROM events WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                sendError('Event not found', 404);
            }
            
            // Build update query dynamically based on provided fields
            $updates = [];
            $params = [];
            
            $allowedFields = ['session_id', 'event_type', 'url', 'timestamp', 'data'];
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $field === 'data' ? json_encode($input[$field]) : $input[$field];
                }
            }
            
            if (empty($updates)) {
                sendError('No fields to update');
            }
            
            $params[] = $id;
            $sql = "UPDATE events SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            sendJson([
                'success' => true,
                'message' => 'Event updated successfully'
            ]);
            break;
            
        case 'DELETE':
            // DELETE /api/events/{id} - Delete event
            if (!$id) {
                sendError('Event ID required for deletion');
            }
            
            $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                sendError('Event not found', 404);
            }
            
            sendJson([
                'success' => true,
                'message' => 'Event deleted successfully'
            ]);
            break;
            
        default:
            sendError('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log("Events API error: " . $e->getMessage());
    sendError('Internal server error', 500);
}
