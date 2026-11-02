<?php
// ===================================================================
// Begehungs-Tool – Portal-Upload-Fotos für Haupttool abrufen
// GET ?file=portal_uploads/abc123.jpg  (API-Token erforderlich)
// ===================================================================
require_once __DIR__ . '/db.php';

// Zugriff erlaubt mit (a) API-Token (Haupttool) ODER (b) gueltigem Portal-Token (Firmenportal).
// Token kommt aus Header X-API-Token oder URL-Parameter ?token= ,
// da ein <img src="..."> keine Header senden kann.
$tok = $_SERVER['HTTP_X_API_TOKEN'] ?? $_GET['token'] ?? '';

$authorized = false;
if (!empty($tok)) {
    // (a) API-Token?
    if (hash_equals(API_TOKEN, $tok)) {
        $authorized = true;
    } else {
        // (b) Gueltiger, nicht abgelaufener Portal-Token?
        try {
            $stmt = db()->prepare(
                "SELECT 1 FROM begehung_portal_tokens
                 WHERE id=? AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())"
            );
            $stmt->execute([$tok]);
            if ($stmt->fetchColumn()) $authorized = true;
        } catch (Exception $e) { /* Tabelle fehlt o.ae. -> nicht autorisiert */ }
    }
}

if (!$authorized) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Ungueltiger oder fehlender Token']);
    exit;
}

$file = $_GET['file'] ?? '';

// Sicherheit: nur portal_uploads/ erlauben, keine Pfad-Traversal
if (empty($file) || !preg_match('#^portal_uploads/[\w\-]+\.(jpg|jpeg|png|webp)$#i', $file)) {
    http_response_code(400);
    exit('Ungültiger Dateipfad');
}

// portal_uploads liegt im begehung-Root (eine Ebene über /api/)
$path = dirname(__DIR__) . '/' . $file;

if (!file_exists($path)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error'=>'Datei nicht gefunden','looked_in'=>$path,'dir_exists'=>is_dir(dirname($path))]);
    exit;
}

$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$ext] ?? 'image/jpeg';

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($path));
readfile($path);
