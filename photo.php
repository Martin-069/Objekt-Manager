<?php
// ===================================================================
// Begehungs-Tool – Foto-Download
//
// Request:
//   GET /api/photo.php?id=xxx&size=thumb|full
//   Header: X-API-Token
//
// Response:
//   Binary Image (image/jpeg, image/png oder image/webp)
//   404 wenn nicht gefunden
// ===================================================================

require_once __DIR__ . '/db.php';
require_api_token();

$id   = trim($_GET['id'] ?? '');
$size = ($_GET['size'] ?? 'full') === 'thumb' ? 'thumb' : 'full';

if (empty($id) || !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
  http_response_code(400);
  json_response(['error' => 'Ungueltige Photo-ID']);
}

$stmt = db()->prepare("SELECT filename, mime_type FROM begehung_photos WHERE id = :id AND deleted = 0");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) {
  http_response_code(404);
  json_response(['error' => 'Foto nicht gefunden']);
}

$mime = $row['mime_type'] ?: 'image/jpeg';
$ext  = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');
$path = PHOTO_DIR . '/' . $row['filename'] . '_' . $size . '.' . $ext;

// Falls Thumbnail fehlt, fallback auf Full
if (!file_exists($path) && $size === 'thumb') {
  $path = PHOTO_DIR . '/' . $row['filename'] . '_full.' . $ext;
}
if (!file_exists($path)) {
  http_response_code(404);
  json_response(['error' => 'Foto-Datei nicht gefunden auf dem Server', 'path' => $row['filename']]);
}

// Datei ausliefern. CORS-Header sind bereits durch db.php gesetzt.
// JSON-Content-Type ueberschreiben mit echtem Bild-Mime
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
exit;
