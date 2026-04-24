<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config/config.php';

$db_path = __DIR__ . '/data/database.sqlite';
try {
    $pdo = new PDO("sqlite:$db_path");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Initialize database if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS clipboards (
        id TEXT PRIMARY KEY,
        content TEXT,
        created_at DATETIME,
        expires_at DATETIME NULL,
        is_one_shot INTEGER,
        is_editable INTEGER,
        access_count INTEGER DEFAULT 0
    )");
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

function send_json($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function generate_id($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_';
    $id = '';
    for ($i = 0; $i < $length; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

function validate_id($id) {
    return preg_match('/^[a-zA-Z0-9\-_]{3,32}$/', $id);
}

switch ($action) {
    case 'config':
        send_json([
            'writepwd' => (bool)$config['writepwd'],
            'readpwd' => (bool)$config['readpwd']
        ]);
        break;

    case 'create':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) send_json(['error' => 'Invalid JSON'], 400);

        if ($config['writepwd'] && (!isset($data['password']) || $data['password'] !== $config['password'])) {
            send_json(['error' => 'Unauthorized'], 401);
        }

        $content = $data['content'] ?? '';
        if (strlen($content) > 102400) send_json(['error' => 'Content too large'], 400);

        $custom_id = $data['custom_id'] ?? null;
        if ($custom_id) {
            if (!validate_id($custom_id)) send_json(['error' => 'Invalid ID format'], 400);
            
            $stmt = $pdo->prepare("SELECT id FROM clipboards WHERE id = ?");
            $stmt->execute([$custom_id]);
            if ($stmt->fetch()) send_json(['error' => 'ID already exists'], 400);
            $id = $custom_id;
        } else {
            $id = generate_id();
            // Ensure uniqueness
            while (true) {
                $stmt = $pdo->prepare("SELECT id FROM clipboards WHERE id = ?");
                $stmt->execute([$id]);
                if (!$stmt->fetch()) break;
                $id = generate_id();
            }
        }

        $expires = $data['expires'] ?? 'infinite';
        $expires_at = null;
        if ($expires !== 'infinite') {
            switch ($expires) {
                case '1h':
                    $interval = 'PT1H';
                    break;
                case '24h':
                    $interval = 'P1D';
                    break;
                case '7d':
                    $interval = 'P7D';
                    break;
                default:
                    $interval = null;
            }
            if ($interval) {
                $date = new DateTime();
                $date->add(new DateInterval($interval));
                $expires_at = $date->format('Y-m-d H:i:s');
            }
        }

        $is_one_shot = ($data['one_shot'] ?? false) ? 1 : 0;
        $is_editable = ($data['editable'] ?? true) ? 1 : 0;

        $stmt = $pdo->prepare("INSERT INTO clipboards (id, content, created_at, expires_at, is_one_shot, is_editable) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id, $content, date('Y-m-d H:i:s'), $expires_at, $is_one_shot, $is_editable]);

        send_json(['success' => true, 'id' => $id, 'url' => "/c/$id"]);
        break;

    case 'get':
        $id = $_GET['id'] ?? '';
        if (!$id) send_json(['error' => 'Missing ID'], 400);

        if ($config['readpwd'] && (!isset($_GET['password']) || $_GET['password'] !== $config['password'])) {
            send_json(['error' => 'Unauthorized', 'needs_password' => true], 401);
        }

        $stmt = $pdo->prepare("SELECT * FROM clipboards WHERE id = ?");
        $stmt->execute([$id]);
        $clipboard = $stmt->fetch();

        if (!$clipboard) send_json(['error' => 'Not found'], 404);

        // Check expiration
        if ($clipboard['expires_at'] && strtotime($clipboard['expires_at']) < time()) {
            $stmt = $pdo->prepare("DELETE FROM clipboards WHERE id = ?");
            $stmt->execute([$id]);
            send_json(['error' => 'Expired'], 404);
        }

        // Increment access count
        $stmt = $pdo->prepare("UPDATE clipboards SET access_count = access_count + 1 WHERE id = ?");
        $stmt->execute([$id]);

        // Handle one-shot
        if ($clipboard['is_one_shot']) {
            $stmt = $pdo->prepare("DELETE FROM clipboards WHERE id = ?");
            $stmt->execute([$id]);
        }

        send_json([
            'content' => $clipboard['content'],
            'editable' => (bool)$clipboard['is_editable'],
            'expires_at' => $clipboard['expires_at'],
            'access_count' => $clipboard['access_count'] + 1,
            'is_one_shot' => (bool)$clipboard['is_one_shot']
        ]);
        break;

    case 'update':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) send_json(['error' => 'Invalid JSON'], 400);

        $id = $data['id'] ?? '';
        if (!$id) send_json(['error' => 'Missing ID'], 400);

        if ($config['writepwd'] && (!isset($data['password']) || $data['password'] !== $config['password'])) {
            send_json(['error' => 'Unauthorized'], 401);
        }

        $stmt = $pdo->prepare("SELECT is_editable FROM clipboards WHERE id = ?");
        $stmt->execute([$id]);
        $clipboard = $stmt->fetch();

        if (!$clipboard) send_json(['error' => 'Not found'], 404);
        if (!$clipboard['is_editable']) send_json(['error' => 'Read-only clipboard'], 403);

        $content = $data['content'] ?? '';
        if (strlen($content) > 102400) send_json(['error' => 'Content too large'], 400);

        $stmt = $pdo->prepare("UPDATE clipboards SET content = ? WHERE id = ?");
        $stmt->execute([$content, $id]);

        send_json(['success' => true]);
        break;

    case 'delete':
        session_start();
        if (!isset($_SESSION['admin'])) send_json(['error' => 'Unauthorized'], 401);

        $id = $_GET['id'] ?? '';
        if (!$id) send_json(['error' => 'Missing ID'], 400);

        $stmt = $pdo->prepare("DELETE FROM clipboards WHERE id = ?");
        $stmt->execute([$id]);

        send_json(['success' => true]);
        break;

    case 'admin_extend':
        session_start();
        if (!isset($_SESSION['admin'])) send_json(['error' => 'Unauthorized'], 401);
        
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? '';
        $days = (int)($data['days'] ?? 7);

        $stmt = $pdo->prepare("SELECT expires_at FROM clipboards WHERE id = ?");
        $stmt->execute([$id]);
        $cb = $stmt->fetch();
        if (!$cb) send_json(['error' => 'Not found'], 404);

        $current = $cb['expires_at'] ? new DateTime($cb['expires_at']) : new DateTime();
        if ($current < new DateTime()) $current = new DateTime();
        $current->add(new DateInterval("P{$days}D"));
        
        $stmt = $pdo->prepare("UPDATE clipboards SET expires_at = ? WHERE id = ?");
        $stmt->execute([$current->format('Y-m-d H:i:s'), $id]);
        send_json(['success' => true]);
        break;

    case 'admin_edit':
        session_start();
        if (!isset($_SESSION['admin'])) send_json(['error' => 'Unauthorized'], 401);
        
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? '';
        $content = $data['content'] ?? '';

        $stmt = $pdo->prepare("UPDATE clipboards SET content = ? WHERE id = ?");
        $stmt->execute([$content, $id]);
        send_json(['success' => true]);
        break;

    case 'admin':
        session_start();
        $data = json_decode(file_get_contents('php://input'), true);

        if (isset($data['login_password'])) {
            if ($data['login_password'] === $config['password']) {
                $_SESSION['admin'] = true;
                send_json(['success' => true]);
            } else {
                send_json(['error' => 'Invalid password'], 401);
            }
        }

        if (!isset($_SESSION['admin'])) send_json(['error' => 'Unauthorized', 'needs_login' => true], 401);

        $search = $_GET['search'] ?? '';
        if ($search) {
            $stmt = $pdo->prepare("SELECT * FROM clipboards WHERE id LIKE ? OR content LIKE ? ORDER BY created_at DESC");
            $stmt->execute(["%$search%", "%$search%"]);
        } else {
            $stmt = $pdo->query("SELECT * FROM clipboards ORDER BY created_at DESC");
        }
        $clipboards = $stmt->fetchAll();

        send_json(['clipboards' => $clipboards]);
        break;

    default:
        send_json(['error' => 'Invalid action'], 400);
}
