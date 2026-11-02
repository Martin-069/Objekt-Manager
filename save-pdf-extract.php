<?php
// ===================================================================
// Begehungs-Tool - extracted_json eines PDF-Protokolls speichern
// Wird nach KI-Auswertung im Client aufgerufen (nur Admin)
//
// POST /api/save-pdf-extract.php
// Header: X-API-Token: <token>
// Body JSON: { id: "...", extracted_json: "..." }
// ===================================================================

require_once __DIR__ . '/db.php';
require_api_token();

// Nur Admin
$in   = json_input();
$pusherRole = trim($_SERVER['HTTP_X_PUSHER_ROLE'] ?? $in['pusher_role'] ?? '');
if ($pusherRole !== 'admin') {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Nur Admin']);
  exit;
}

header('Content-Type: application/json');
$id   = trim((string)($in['id']             ?? ''));
$json = trim((string)($in['extracted_json'] ?? ''));

if (!$id) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'id fehlt']);
  exit;
}

try {
  $stmt = db()->prepare("
    UPDATE begehung_pdfs
       SET extracted_json = :json, updated_at = NOW()
     WHERE id = :id
  ");
  $stmt->execute([':json' => $json, ':id' => $id]);
  echo json_encode(['ok' => true]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
