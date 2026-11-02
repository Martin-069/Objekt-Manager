<?php
// ===================================================================
// Begehungs-Tool - PDF-Protokoll-Upload (Nur Admin)
//
// POST /api/upload-pdf.php
// Header: X-API-Token: <token>
// FormData:
//   id          - UUID des Eintrags
//   apartment_id- UUID der Wohnung
//   filename    - Originalname der PDF
//   device_id   - Geraete-ID
//   file        - PDF-Datei (multipart)
// ===================================================================

require_once __DIR__ . '/db.php';
require_api_token();

// Nur Admin darf hochladen (pusherRole im Header oder POST-Param)
$pusherRole = trim($_SERVER['HTTP_X_PUSHER_ROLE'] ?? $_POST['pusher_role'] ?? '');
if ($pusherRole !== 'admin') {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Nur Admin']);
  exit;
}

header('Content-Type: application/json');

$id           = trim($_POST['id']           ?? '');
$apartmentId  = trim($_POST['apartment_id'] ?? '');
$filename     = trim($_POST['filename']     ?? '');
$deviceId     = trim($_POST['device_id']    ?? '');

if (!$id || !$apartmentId) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'id und apartment_id erforderlich']);
  exit;
}

// Nur PDFs erlaubt
$file = $_FILES['file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Keine Datei oder Upload-Fehler']);
  exit;
}

$mime = mime_content_type($file['tmp_name']);
if ($mime !== 'application/pdf') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Nur PDF-Dateien erlaubt']);
  exit;
}

// Speicherpfad
$uploadDir = __DIR__ . '/../pdfs/';
if (!is_dir($uploadDir)) {
  mkdir($uploadDir, 0755, true);
}
$safeName  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $id) . '.pdf';
$filePath  = $uploadDir . $safeName;
$relPath   = 'pdfs/' . $safeName;

if (!move_uploaded_file($file['tmp_name'], $filePath)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Speichern fehlgeschlagen']);
  exit;
}

// Dateigroesse
$sizeBytes = filesize($filePath);

// Tabelle anlegen falls nicht vorhanden
db()->exec("
  CREATE TABLE IF NOT EXISTS begehung_pdfs (
    id            VARCHAR(36)   PRIMARY KEY,
    apartment_id  VARCHAR(36)   NOT NULL,
    filename      VARCHAR(255),
    file_path     VARCHAR(255),
    size_bytes    INT           DEFAULT 0,
    extracted_json MEDIUMTEXT,
    created_at    DATETIME      DEFAULT NOW(),
    updated_at    DATETIME      DEFAULT NOW(),
    device_id     VARCHAR(64),
    deleted       TINYINT(1)    DEFAULT 0,
    INDEX idx_apt (apartment_id)
  )
");

$stmt = db()->prepare("
  INSERT INTO begehung_pdfs
    (id, apartment_id, filename, file_path, size_bytes, device_id, created_at, updated_at, deleted)
  VALUES
    (:id, :apt, :filename, :path, :size, :device, NOW(), NOW(), 0)
  ON DUPLICATE KEY UPDATE
    filename   = VALUES(filename),
    file_path  = VALUES(file_path),
    size_bytes = VALUES(size_bytes),
    updated_at = NOW(),
    device_id  = VALUES(device_id),
    deleted    = 0
");
$stmt->execute([
  ':id'       => $id,
  ':apt'      => $apartmentId,
  ':filename' => $filename ?: $safeName,
  ':path'     => $relPath,
  ':size'     => $sizeBytes,
  ':device'   => $deviceId,
]);

echo json_encode(['ok' => true, 'id' => $id, 'path' => $relPath]);
