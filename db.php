<?php
// ===================================================================
// Begehungs-Tool – DB-Connection & Auth
// ===================================================================

require_once __DIR__ . '/config.php';

// CORS-Header (damit auch lokal vom Tablet aus zugegriffen werden kann)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Token');
header('Content-Type: application/json; charset=utf-8');

// Preflight-Requests sofort beantworten
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function db() {
  static $pdo = null;
  if ($pdo === null) {
    try {
      $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
          PDO::ATTR_EMULATE_PREPARES => false,
        ]
      );
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode([
        'error' => 'Datenbank-Verbindung fehlgeschlagen',
        'detail' => DEBUG_MODE ? $e->getMessage() : null
      ]);
      exit;
    }
  }
  return $pdo;
}

function require_api_token() {
  $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
  if (empty($token) || !hash_equals(API_TOKEN, $token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Ungueltiger oder fehlender API-Token']);
    exit;
  }
}

function json_input() {
  $raw = file_get_contents('php://input');
  if (empty($raw)) return [];
  $data = json_decode($raw, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungueltiges JSON', 'detail' => json_last_error_msg()]);
    exit;
  }
  return $data ?: [];
}

function json_response($data) {
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Geraet (Device) registrieren / "last_seen" aktualisieren
function register_device($deviceId, $deviceName = null) {
  if (empty($deviceId)) return;
  $stmt = db()->prepare(
    "INSERT INTO begehung_devices (id, name, created_at, last_seen)
     VALUES (:id, :name, NOW(), NOW())
     ON DUPLICATE KEY UPDATE name = COALESCE(VALUES(name), name), last_seen = NOW()"
  );
  $stmt->execute([':id' => $deviceId, ':name' => $deviceName]);
}
