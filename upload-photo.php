<?php
// ===================================================================
// Begehungs-Tool – Foto-Upload
//
// Request:
//   POST /api/upload-photo.php
//   Header: X-API-Token
//   FormData:
//     id          – Photo-ID (vom Client)
//     owner_type  – "property-cover", "bld-general", "ai-defect", ...
//     owner_id    – Begehungs-ID / Property-ID / Defect-ID
//     mime_type   – "image/jpeg"
//     device_id   – ID des Tablets
//     full        – Datei (Vollbild, max ~2 MB)
//     thumb       – Datei (Thumbnail 240px, max ~50 KB)
//
// Response:
//   { ok: true, id: "...", size: 12345 }
// ===================================================================

require_once __DIR__ . '/db.php';
require_api_token();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  json_response(['error' => 'POST erforderlich']);
}

$id        = trim($_POST['id'] ?? '');
$ownerType = trim($_POST['owner_type'] ?? '');
$ownerId   = trim($_POST['owner_id'] ?? '');
$mimeType  = trim($_POST['mime_type'] ?? 'image/jpeg');
$deviceId  = trim($_POST['device_id'] ?? '');

if (empty($id) || !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
  http_response_code(400);
  json_response(['error' => 'Ungueltige Photo-ID']);
}
if (empty($_FILES['full']) && empty($_FILES['thumb'])) {
  http_response_code(400);
  json_response(['error' => 'Keine Datei hochgeladen']);
}

// Photo-Verzeichnis sicherstellen (mit Sharding)
$shard = substr($id, -2);  // letzte 2 Zeichen als Unterordner
$dir = PHOTO_DIR . '/' . $shard;
if (!is_dir($dir)) {
  @mkdir($dir, 0775, true);
  if (!is_dir($dir)) {
    http_response_code(500);
    json_response(['error' => 'Photo-Verzeichnis nicht erstellbar', 'path' => $dir]);
  }
}

$ext = ($mimeType === 'image/png') ? 'png' : (($mimeType === 'image/webp') ? 'webp' : 'jpg');

// Full speichern
if (!empty($_FILES['full']) && $_FILES['full']['error'] === UPLOAD_ERR_OK) {
  if ($_FILES['full']['size'] > MAX_PHOTO_SIZE) {
    http_response_code(413);
    json_response(['error' => 'Foto zu gross (max ' . (MAX_PHOTO_SIZE/1024/1024) . ' MB)']);
  }
  $fullPath = $dir . '/' . $id . '_full.' . $ext;
  if (!move_uploaded_file($_FILES['full']['tmp_name'], $fullPath)) {
    http_response_code(500);
    json_response(['error' => 'Upload (full) fehlgeschlagen']);
  }
}

// Thumb speichern
if (!empty($_FILES['thumb']) && $_FILES['thumb']['error'] === UPLOAD_ERR_OK) {
  $thumbPath = $dir . '/' . $id . '_thumb.' . $ext;
  move_uploaded_file($_FILES['thumb']['tmp_name'], $thumbPath);
}

// Metadaten in DB (UPSERT)
$totalSize = ($_FILES['full']['size'] ?? 0) + ($_FILES['thumb']['size'] ?? 0);
$filename = $shard . '/' . $id;  // Pfad-Präfix, ohne _full/_thumb-Suffix

$sql = "INSERT INTO begehung_photos
  (id, owner_type, owner_id, filename, mime_type, size_bytes, created_at, device_id, deleted)
  VALUES (:id, :ownerType, :ownerId, :fn, :mt, :sz, NOW(), :dev, 0)
  ON DUPLICATE KEY UPDATE
    owner_type = VALUES(owner_type),
    owner_id   = VALUES(owner_id),
    filename   = VALUES(filename),
    mime_type  = VALUES(mime_type),
    size_bytes = VALUES(size_bytes),
    deleted    = 0";
$stmt = db()->prepare($sql);
$stmt->execute([
  ':id'        => $id,
  ':ownerType' => $ownerType,
  ':ownerId'   => $ownerId,
  ':fn'        => $filename,
  ':mt'        => $mimeType,
  ':sz'        => $totalSize,
  ':dev'       => $deviceId,
]);

register_device($deviceId);
json_response(['ok' => true, 'id' => $id, 'size' => $totalSize]);
