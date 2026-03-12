<?php
 // Analytics Data Ingestion Endpoint

header('Content-Type: application/json');

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Allow requests from test.maxk.site
if (preg_match('/^https:\/\/[a-zA-Z0-9-]+\.maxk\.site$/', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} elseif ($origin === 'https://maxk.site') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Accept POST and GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST or GET.']);
    exit;
}

require_once __DIR__ . '/../../db_config.php';

// Handle GET requests from <noscript> fallback
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $jsEnabled = isset($_GET['js_enabled']) ? $_GET['js_enabled'] === 'true' : false;
        $page = $_GET['page'] ?? 'unknown';
        
        // Generate or retrieve session ID from cookie
        $sessionId = $_COOKIE['_collector_sid'] ?? 'nojs_' . bin2hex(random_bytes(16));
        
        // no-JS fallback event data
        $timestamp = date('Y-m-d H:i:s');
        $url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'unknown';
        
        $data = [
            'javascriptEnabled' => $jsEnabled,
            'page' => $page,
            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        
        $pdo = getDbConnection();
        
        // Insert event
        $eventId = logEvent($pdo, $sessionId, 'pageview_nojs', $url, $timestamp, $data);
        
        header('Content-Type: image/gif');
        header('Content-Length: 43');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
        
    } catch (Exception $e) {
        error_log("Error processing no-JS event: " . $e->getMessage());
        // Still return 1x1 GIF to avoid browser errors
        header('Content-Type: image/gif');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }
}

// Log the incoming event to the database
function logEvent($pdo, $sessionId, $eventType, $url, $timestamp, $data) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO events (session_id, event_type, url, timestamp, data)
            VALUES (:session_id, :event_type, :url, :timestamp, :data)
        ");
        
        $stmt->execute([
            ':session_id' => $sessionId,
            ':event_type' => $eventType,
            ':url' => $url,
            ':timestamp' => $timestamp,
            ':data' => json_encode($data)
        ]);
        
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Failed to insert event: " . $e->getMessage());
        throw $e;
    }
}

// Update or create session record
function updateSession($pdo, $sessionId, $timestamp) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sessions (session_id, first_seen, last_seen, page_count)
            VALUES (:session_id, :first_seen, :last_seen, 1)
            ON DUPLICATE KEY UPDATE 
                last_seen = VALUES(last_seen),
                page_count = page_count + 1
        ");
        
        $stmt->execute([
            ':session_id' => $sessionId,
            ':first_seen' => $timestamp,
            ':last_seen' => $timestamp
        ]);
    } catch (PDOException $e) {
        error_log("Failed to update session: " . $e->getMessage());
    }
}

try {
    $rawInput = file_get_contents('php://input');
    
    if (empty($rawInput)) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty request body']);
        exit;
    }
    
    $payload = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }
    
    // Validate required fields
    if (!isset($payload['type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required field: type']);
        exit;
    }
    
    if (!isset($payload['session'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required field: session']);
        exit;
    }
    
    if (!isset($payload['timestamp'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required field: timestamp']);
        exit;
    }
    
    // Extract common fields
    $sessionId = $payload['session'];
    $eventType = $payload['type'];
    $url = $payload['url'] ?? null;
    $timestamp = $payload['timestamp'];
    
    // Convert ISO 8601 timestamp to MySQL datetime format
    try {
        $dt = new DateTime($timestamp);
        $mysqlTimestamp = $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $mysqlTimestamp = date('Y-m-d H:i:s');
    }
    
    // Remove common fields from payload to store rest as data
    $data = $payload;
    unset($data['session']);
    unset($data['type']);
    unset($data['url']);
    unset($data['timestamp']);
    
    // Get database connection
    $pdo = getDbConnection();
    
    // Begin transaction
    $pdo->beginTransaction();
    
    try {
        // Insert event
        $eventId = logEvent($pdo, $sessionId, $eventType, $url, $mysqlTimestamp, $data);
        
        // Update session tracking
        if ($eventType === 'pageview') {
            updateSession($pdo, $sessionId, $mysqlTimestamp);
        }
        
        // Commit transaction
        $pdo->commit();
        
        // Return success response
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'id' => $eventId,
            'message' => 'Event logged successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Error processing analytics event: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => 'Failed to process event'
    ]);
}
