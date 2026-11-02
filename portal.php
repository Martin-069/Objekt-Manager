<?php
// ===================================================================
// Begehungs-Tool – Firmenportal API
// db.php setzt bereits alle Headers + OPTIONS-Handling
// ===================================================================
require_once __DIR__ . '/db.php';

// Nur fatale Fehler abfangen (keine Warnings)
set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'line'=>$e->getLine(),'file'=>basename($e->getFile())], JSON_UNESCAPED_UNICODE);
    exit;
});

// Photo-Serve (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['photo'])) {
    serve_photo($_GET['photo'], $_GET['token'] ?? '');
    exit;
}

$in     = json_input();
// Bei multipart/form-data (Foto-Upload) ist php://input leer.
// Dann kommen action/token/defectId/inspectionId als Form-Felder via $_POST.
if (empty($in) && !empty($_POST)) { $in = $_POST; }
$action = trim($in['action'] ?? $_GET['action'] ?? '');
$token  = trim($in['token']  ?? $_GET['token']  ?? '');

if (empty($token)) { json_response(['ok'=>false,'error'=>'Token fehlt']); }

setup_portal_tables();

$contractor = validate_portal_token($token);
if (!$contractor) { json_response(['ok'=>false,'error'=>'Ungültiger oder abgelaufener Token']); }

db()->prepare("UPDATE begehung_portal_tokens SET last_used=UTC_TIMESTAMP() WHERE id=?")
   ->execute([$token]);

switch ($action) {

  case 'validate':
    json_response(['ok'=>true,'contractor'=>[
      'id'=>$contractor['id'], 'name'=>$contractor['name'], 'trade'=>$contractor['trade']??'',
    ]]);
    break;

  case 'defects':
    json_response(['ok'=>true,'defects'=>get_defects_for_contractor($contractor)]);
    break;

  case 'debug':
    $rows = db()->query(
      "SELECT id, SUBSTRING(data,1,300) AS dp FROM begehung_apartment_inspections WHERE deleted=0 LIMIT 3"
    )->fetchAll();
    $contractors = db()->query("SELECT name FROM begehung_contractors WHERE deleted=0 LIMIT 10")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $d = json_decode($r['dp'].'...', true) ?: json_decode($r['dp'], true) ?: [];
        $out[] = ['insp_id'=>$r['id'], 'data_start'=>$r['dp']];
    }
    json_response([
      'ok'             => true,
      'contractor_name'=> $contractor['name'],
      'contractors_db' => array_column($contractors,'name'),
      'inspections'    => $out,
    ]);
    break;

  case 'update':
    $defectId = trim($in['defectId']     ?? '');
    $inspId   = trim($in['inspectionId'] ?? '');
    $status   = trim($in['status']       ?? '');
    $message  = trim($in['message']      ?? '');
    if (!$defectId || !$inspId) json_response(['ok'=>false,'error'=>'Fehlende Parameter']);
    if ($status && !in_array($status,['offen','beauftragt','in_bearbeitung','erledigt']))
        json_response(['ok'=>false,'error'=>'Ungültiger Status']);
    // v1.7: Termin-Felder (Vorabtermin + Durchführung)
    $termin = [
        'vorabDate' => clean_date($in['vorabDate'] ?? ''),
        'vorabTime' => clean_time($in['vorabTime'] ?? ''),
        'vorabNote' => mb_substr(trim($in['vorabNote'] ?? ''), 0, 500),
        'execDate'  => clean_date($in['execDate'] ?? ''),
        'execTime'  => clean_time($in['execTime'] ?? ''),
        'execNote'  => mb_substr(trim($in['execNote'] ?? ''), 0, 500),
    ];
    upsert_feedback($inspId,$defectId,$contractor['name'],$status,$message,null,$termin);
    json_response(['ok'=>true]);
    break;

  case 'photo':
    $defectId = trim($in['defectId']     ?? '');
    $inspId   = trim($in['inspectionId'] ?? '');
    if (!$defectId || !$inspId) json_response(['ok'=>false,'error'=>'Fehlende Parameter']);
    $path = save_portal_photo($defectId);
    if (!$path) json_response(['ok'=>false,'error'=>'Foto-Upload fehlgeschlagen']);
    upsert_feedback($inspId,$defectId,$contractor['name'],'','',$path);
    json_response(['ok'=>true,'path'=>$path]);
    break;

  default:
    json_response(['ok'=>false,'error'=>'Unbekannte Aktion: '.$action]);
}

// ── Funktionen ────────────────────────────────────────────────

function setup_portal_tables() {
    try {
        // Collation explizit auf general_ci setzen – muss mit bestehenden Tabellen uebereinstimmen
        db()->exec("CREATE TABLE IF NOT EXISTS begehung_portal_tokens (
            id              VARCHAR(64)  PRIMARY KEY,
            contractor_id   VARCHAR(64)  NOT NULL,
            contractor_name VARCHAR(255),
            created_at      DATETIME,
            expires_at      DATETIME NULL,
            last_used       DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS begehung_portal_feedback (
            id              VARCHAR(64)  PRIMARY KEY,
            inspection_id   VARCHAR(64)  NOT NULL,
            defect_id       VARCHAR(64)  NOT NULL,
            contractor_name VARCHAR(255),
            status          VARCHAR(32),
            message         TEXT,
            photo_path      VARCHAR(255) NULL,
            created_at      DATETIME,
            updated_at      DATETIME,
            deleted         TINYINT DEFAULT 0,
            INDEX idx_defect (defect_id),
            INDEX idx_insp   (inspection_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        // Collation bestehender Tabellen korrigieren falls noetig
        try {
            db()->exec("ALTER TABLE begehung_portal_tokens    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            db()->exec("ALTER TABLE begehung_portal_feedback  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        } catch (Exception $e) {}
    } catch (Exception $e) { /* bereits vorhanden */ }
    ensure_portal_feedback_columns();
}

// v1.7: Termin-Felder nachrüsten (Vorabtermin + Durchführung, je Datum/Uhrzeit/Freitext).
//       Jede Spalte einzeln in try/catch -> funktioniert auf MariaDB und MySQL, auch mehrfach.
function ensure_portal_feedback_columns() {
    $cols = [
        "vorab_date VARCHAR(10) NULL",
        "vorab_time VARCHAR(5)  NULL",
        "vorab_note VARCHAR(500) NULL",
        "exec_date  VARCHAR(10) NULL",
        "exec_time  VARCHAR(5)  NULL",
        "exec_note  VARCHAR(500) NULL",
    ];
    foreach ($cols as $def) {
        try { db()->exec("ALTER TABLE begehung_portal_feedback ADD COLUMN $def"); } catch (Exception $e) { /* existiert schon */ }
    }
}

function validate_portal_token($token) {
    $stmt = db()->prepare(
        "SELECT t.contractor_name AS name, t.contractor_id AS cid, c.trade
         FROM begehung_portal_tokens t
         LEFT JOIN begehung_contractors c ON c.id COLLATE utf8mb4_general_ci = t.contractor_id COLLATE utf8mb4_general_ci
         WHERE t.id=? AND (t.expires_at IS NULL OR t.expires_at > UTC_TIMESTAMP())"
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return ['id'=>$row['cid'], 'name'=>$row['name'], 'trade'=>$row['trade']??''];
}

// v1.6: zentrale Rechnungsempfänger-Liste aus begehung_settings (skey=billing_recipients_json, svalue=JSON)
//       Spalten sind skey/svalue (v1.5 nutzte fälschlich key/value).
//       Rückgabe: Map "propertyNumber" => ['billing' => …, 'service' => …]
function load_central_billing_map() {
    $map = [];
    try {
        $stmt = db()->prepare("SELECT svalue FROM begehung_settings WHERE skey=? LIMIT 1");
        $stmt->execute(['billing_recipients_json']);
        $row = $stmt->fetch();
        if (!$row || empty($row['svalue'])) return $map;
        $list = json_decode($row['svalue'], true);
        if (!is_array($list)) return $map;
        foreach ($list as $r) {
            $nums = $r['propertyNumbers'] ?? [];
            if (!is_array($nums)) continue;
            foreach ($nums as $n) {
                $num = trim((string)$n);
                if ($num === '') continue;
                $map[$num] = [
                    'billing' => (string)($r['billing'] ?? ''),
                    'service' => (string)($r['service'] ?? ''),
                ];
            }
        }
    } catch (Exception $e) { /* Setting nicht da → leere Map */ }
    return $map;
}

function get_defects_for_contractor($contractor) {
    $name = trim($contractor['name']);
    // v1.3: apartments-Tabelle hat eigene Spalten phone/email/floor/building_unit — direkt joinen.
    // v1.4: Rech-/Leistungsempfänger aus begehung_properties mitziehen (falls Spalten da).
    // v1.5: zusätzlich zentrale Rechnungsempfänger-Liste (billing_recipients_json) laden — schlägt zuerst dort nach.
    $centralMap = load_central_billing_map();
    //       Zweistufiger Try: erst mit p.billing_recipient/p.service_recipient,
    //       fallback ohne (ältere Schemata ohne diese Spalten).
    try {
        $rows = db()->query(
            "SELECT i.id AS insp_id, i.property_id, i.apartment_id,
                    i.date_inspection, i.tenant_snapshot, i.data,
                    p.number AS prop_number, p.name AS prop_name, p.address AS prop_address,
                    p.billing_recipient AS prop_billing, p.service_recipient AS prop_service,
                    a.number AS apt_number, a.location AS apt_location,
                    a.phone AS apt_phone, a.email AS apt_email,
                    a.floor AS apt_floor, a.building_unit AS apt_building_unit,
                    a.tenant AS apt_tenant
             FROM begehung_apartment_inspections i
             LEFT JOIN begehung_properties p ON p.id=i.property_id
             LEFT JOIN begehung_apartments  a ON a.id=i.apartment_id
             WHERE i.deleted=0"
        )->fetchAll();
    } catch (Exception $e) {
        // Fallback ohne billing/service (Spalten fehlen im Schema)
        try {
            $rows = db()->query(
                "SELECT i.id AS insp_id, i.property_id, i.apartment_id,
                        i.date_inspection, i.tenant_snapshot, i.data,
                        p.number AS prop_number, p.name AS prop_name, p.address AS prop_address,
                        a.number AS apt_number, a.location AS apt_location,
                        a.phone AS apt_phone, a.email AS apt_email,
                        a.floor AS apt_floor, a.building_unit AS apt_building_unit,
                        a.tenant AS apt_tenant
                 FROM begehung_apartment_inspections i
                 LEFT JOIN begehung_properties p ON p.id=i.property_id
                 LEFT JOIN begehung_apartments  a ON a.id=i.apartment_id
                 WHERE i.deleted=0"
            )->fetchAll();
        } catch (Exception $e2) { return []; }
    }
    if (!$rows) return [];

    $fbMap = [];
    try {
        foreach (db()->query("SELECT * FROM begehung_portal_feedback WHERE deleted=0")->fetchAll() as $fb)
            $fbMap[$fb['defect_id']] = $fb;
    } catch (Exception $e) {}

    $result = [];
    foreach ($rows as $row) {
        $data    = json_decode($row['data'] ?? '{}', true) ?: [];
        $defects = $data['defects'] ?? [];
        // v1.3: aus eigenen Spalten der begehung_apartments-Tabelle
        $aptPhone       = trim($row['apt_phone']         ?? '');
        $aptFloor       = trim($row['apt_floor']         ?? '');
        $aptBuildingUnit= trim($row['apt_building_unit'] ?? '');
        $aptEmail       = trim($row['apt_email']         ?? '');
        // Falls tenant_snapshot leer, aktuellen Wohnungs-Mieter nehmen
        $tenantEff      = trim($row['tenant_snapshot'] ?? '') ?: trim($row['apt_tenant'] ?? '');
        foreach ($defects as $d) {
            $assigned = trim($d['assignedTo'] ?? '');
            if (empty($assigned) || strcasecmp($assigned, $name) !== 0) continue;
            if (!empty($d['deleted'])) continue;
            $fb = $fbMap[$d['id']] ?? null;
            $result[] = [
                'defectId'      => $d['id']          ?? '',
                'inspectionId'  => $row['insp_id'],
                'propertyId'    => $row['property_id'],
                'description'   => $d['description'] ?? '',
                'priority'      => $d['priority']    ?? 'mittel',
                'status'        => $fb['status']     ?? $d['status'] ?? 'offen',
                'feedback'      => $fb['message']    ?? '',
                'feedbackPhoto' => !empty($fb['photo_path']) ? basename($fb['photo_path']) : null,
                'feedbackAt'    => $fb['updated_at'] ?? null,
                // v1.7: Termin-Felder
                'vorabDate'     => $fb['vorab_date'] ?? '',
                'vorabTime'     => $fb['vorab_time'] ?? '',
                'vorabNote'     => $fb['vorab_note'] ?? '',
                'execDate'      => $fb['exec_date']  ?? '',
                'execTime'      => $fb['exec_time']  ?? '',
                'execNote'      => $fb['exec_note']  ?? '',
                'inspDate'      => $row['date_inspection'],
                'tenant'        => $tenantEff,              // v1.3: Snapshot mit Fallback auf aktuellen Mieter
                'propNumber'    => $row['prop_number']  ?? '',
                'propName'      => $row['prop_name']    ?? '',
                'propAddress'   => $row['prop_address'] ?? '',
                // v1.5: zentrale Liste hat Vorrang; Fallback = property-Spalte
                'billingRecipient' => (($centralMap[trim($row['prop_number'] ?? '')]['billing'] ?? '') ?: trim($row['prop_billing'] ?? '')),
                'serviceRecipient' => (($centralMap[trim($row['prop_number'] ?? '')]['service'] ?? '') ?: trim($row['prop_service'] ?? '')),
                'aptNumber'     => $row['apt_number']   ?? '',
                'aptLocation'   => $row['apt_location'] ?? '',
                'aptFloor'      => $aptFloor,               // v1.3
                'aptBuildingUnit' => $aptBuildingUnit,      // v1.3
                'phone'         => $aptPhone,               // v1.3 — Telefon Mieter (aus eigener Spalte)
                'email'         => $aptEmail,               // v1.3 — E-Mail Mieter
                'photos'        => $d['photos']         ?? [],
            ];
        }
    }
    return $result;
}

function upsert_feedback($inspId,$defectId,$contractorName,$status,$message,$photoPath,$termin=[]) {
    $check = db()->prepare("SELECT id FROM begehung_portal_feedback WHERE defect_id=?");
    $check->execute([$defectId]); $existing = $check->fetch();
    $now = gmdate('Y-m-d H:i:s');
    // v1.7: Termin-Felder — nur gesetzte Werte schreiben (leere lassen Bestehendes unberührt beim Update)
    $tmap = [
        'vorab_date'=>$termin['vorabDate'] ?? '', 'vorab_time'=>$termin['vorabTime'] ?? '', 'vorab_note'=>$termin['vorabNote'] ?? '',
        'exec_date' =>$termin['execDate']  ?? '', 'exec_time' =>$termin['execTime']  ?? '', 'exec_note' =>$termin['execNote']  ?? '',
    ];
    if ($existing) {
        $sets=[]; $params=[];
        if ($status!=='')     { $sets[]='status=?';     $params[]=$status; }
        if ($message!=='')    { $sets[]='message=?';    $params[]=$message; }
        if ($photoPath!==null){ $sets[]='photo_path=?'; $params[]=$photoPath; }
        foreach ($tmap as $col=>$val) { if ($val!=='') { $sets[]="$col=?"; $params[]=$val; } }
        $sets[]='updated_at=?'; $params[]=$now; $params[]=$defectId;
        if (count($sets)>1) db()->prepare("UPDATE begehung_portal_feedback SET ".implode(',',$sets)." WHERE defect_id=?")->execute($params);
    } else {
        db()->prepare("INSERT INTO begehung_portal_feedback (id,inspection_id,defect_id,contractor_name,status,message,photo_path,vorab_date,vorab_time,vorab_note,exec_date,exec_time,exec_note,created_at,updated_at,deleted) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)")
           ->execute([bin2hex(random_bytes(16)),$inspId,$defectId,$contractorName,$status,$message,$photoPath,
                      $tmap['vorab_date'],$tmap['vorab_time'],$tmap['vorab_note'],$tmap['exec_date'],$tmap['exec_time'],$tmap['exec_note'],$now,$now]);
    }
}
// v1.7: Eingaben säubern
function clean_date($s){ $s=trim($s); return preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)?$s:''; }
function clean_time($s){ $s=trim($s); return preg_match('/^\d{2}:\d{2}$/',$s)?$s:''; }

function save_portal_photo($defectId) {
    $file = $_FILES['photo'] ?? null;
    if (!$file || $file['error']!==UPLOAD_ERR_OK) return null;
    if (!in_array($file['type'],['image/jpeg','image/png','image/webp'])) return null;
    // portal_uploads liegt im begehung-Root (eine Ebene über /api/)
    $dir = dirname(__DIR__) . '/portal_uploads/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(8)).'.'.( pathinfo($file['name'],PATHINFO_EXTENSION)?:'jpg');
    return move_uploaded_file($file['tmp_name'],$dir.$name) ? 'portal_uploads/'.$name : null;
}

function serve_photo($photoId, $token) {
    if (empty($token)) { http_response_code(401); exit; }
    // Portal-Token validieren
    setup_portal_tables();
    if (!validate_portal_token($token)) { http_response_code(401); exit; }
    try {
        $stmt = db()->prepare("SELECT filename, mime_type FROM begehung_photos WHERE id=? AND deleted=0");
        $stmt->execute([$photoId]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error'=>'Kein DB-Eintrag für Photo-ID','id'=>$photoId]);
            exit;
        }
        $mime = $row['mime_type'] ?: 'image/jpeg';
        $ext  = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');
        $path = PHOTO_DIR . '/' . $row['filename'] . '_full.' . $ext;
        // Fallback auf thumb
        if (!file_exists($path)) {
            $path = PHOTO_DIR . '/' . $row['filename'] . '_thumb.' . $ext;
        }
        if (!file_exists($path)) {
            // Debug: zeige welcher Pfad gesucht wurde
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error'=>'Datei nicht gefunden','path'=>$path,'PHOTO_DIR'=>PHOTO_DIR,'filename'=>$row['filename']]);
            exit;
        }
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    } catch (Exception $e) { http_response_code(500); exit; }
}
