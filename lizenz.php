<?php
// ===================================================================
// Lizenz-Server  (Stufe 4: echte Server-seitige Lizenzprüfung)
// Deployment: /api/lizenz.php auf Martins All-Inkl (martin069.de)
// VERSION: v1.0
//
// Zweck:
//   Das HTML-Tool (darf auf fremdem Server liegen, z.B. SteSta) ruft
//   beim Start + periodisch diesen Endpunkt mit Lizenzschlüssel auf.
//   Server prüft: existiert / aktiv / nicht gesperrt / nicht abgelaufen
//   / Domain passt. -> OK oder Nein. Nein => Tool sperrt sich (Not-Aus).
//
//   Domain-Lock: der Browser schickt automatisch einen Origin-Header,
//   den JavaScript NICHT fälschen kann. Der Server vergleicht ihn mit
//   den für den Key hinterlegten Domains. Eine kopierte HTML auf einer
//   fremden Domain fällt damit durch (status=domain_mismatch).
//   CORS wird bewusst für alle Origins geöffnet, damit die legitime
//   Cross-Origin-Abfrage die Antwort lesen darf — der eigentliche Lock
//   passiert in der Logik, nicht über CORS.
//
// ----- Öffentlich (vom Tool, kein Token nötig, Key IST das Geheimnis):
//   POST/GET  ?action=check   { key:"LIZ-..." }   (oder key= im Query)
//   Antwort:
//     { ok:true/false, status:"valid|grace|expired|blocked|
//       domain_mismatch|unknown|inactive",
//       valid_until:"YYYY-MM-DD"|null, grace_days:N,
//       days_left:N|null, message:"...", serverTime:"..." }
//
// ----- Admin (Header X-Admin-Token: <LIZENZ_ADMIN_TOKEN>):
//   action=list
//   action=create   { customer, domains:["a.de","b.de"], valid_until, grace_days, key? }
//   action=block     { key }
//   action=unblock   { key }
//   action=extend    { key, valid_until }
//   action=setdomains{ key, domains:[...] }
//   action=delete    { key }
// ===================================================================

// --- CORS zuerst, VOR allem anderen (auch vor evtl. Fehlern in db.php) ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . ($origin !== '' ? $origin : '*'));
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token, X-API-Token');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

// Preflight sofort beantworten (kein DB-Zugriff nötig)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

require_once __DIR__ . '/db.php';

// --- Admin-Token: hier eintragen ODER in db.php als Konstante definieren ---
if (!defined('LIZENZ_ADMIN_TOKEN')) {
  define('LIZENZ_ADMIN_TOKEN', 'Kq7SteSta9mXvR2pLwZ4nTfB6cHjD8gAeY3u');
}

// Globaler Fallback: immer sauberes JSON statt leerer 500er.
set_exception_handler(function ($e) {
  http_response_code(500);
  echo json_encode([
    'ok'     => false,
    'error'  => 'Serverfehler in lizenz.php',
    'detail' => (defined('DEBUG_MODE') && DEBUG_MODE)
      ? ($e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine())
      : null,
  ]);
  exit;
});

setup_license_table();

// Eingabe aus JSON-Body, POST oder GET zusammenführen (robust für fetch + curl)
$in = license_input();
$action = strtolower(trim((string)($in['action'] ?? 'check')));

$serverTime = (db()->query("SELECT NOW() AS t"))->fetch()['t'];

// --------- Admin-Bereich ---------
$adminActions = ['list','create','block','unblock','extend','setdomains','delete'];
if (in_array($action, $adminActions, true)) {
  require_admin();
  switch ($action) {
    case 'list':       admin_list();                 break;
    case 'create':     admin_create($in);            break;
    case 'block':      admin_set_status($in, 'blocked'); break;
    case 'unblock':    admin_set_status($in, 'active');  break;
    case 'extend':     admin_extend($in);            break;
    case 'setdomains': admin_setdomains($in);         break;
    case 'delete':     admin_delete($in);            break;
  }
  exit;
}

// --------- Öffentliche Lizenzprüfung ---------
handle_check($in, $origin, $serverTime);


// =================== Helfer ===================

function license_input() {
  $data = [];
  // JSON-Body
  $raw = file_get_contents('php://input');
  if ($raw) {
    $j = json_decode($raw, true);
    if (is_array($j)) $data = $j;
  }
  // GET/POST überschreiben/ergänzen (für einfache Requests ohne JSON)
  foreach ($_GET as $k => $v)  { if (!isset($data[$k])) $data[$k] = $v; }
  foreach ($_POST as $k => $v) { if (!isset($data[$k])) $data[$k] = $v; }
  return $data;
}

function require_admin() {
  $tok = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
  if (!hash_equals(LIZENZ_ADMIN_TOKEN, (string)$tok)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin-Token ungültig']);
    exit;
  }
}

// Tabelle anlegen (idempotent, bei jedem Request)
function setup_license_table() {
  db()->exec("CREATE TABLE IF NOT EXISTS begehung_licenses (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    license_key   VARCHAR(64)  NOT NULL UNIQUE,
    customer      VARCHAR(255) DEFAULT '',
    domains       TEXT         DEFAULT NULL,   -- JSON-Array erlaubter Hosts, [] = keine Domain-Bindung
    status        VARCHAR(16)  NOT NULL DEFAULT 'active',  -- active | blocked
    valid_until   DATE         DEFAULT NULL,   -- NULL = unbefristet
    grace_days    INT          NOT NULL DEFAULT 5,
    note          VARCHAR(500) DEFAULT '',
    last_check_at DATETIME     DEFAULT NULL,
    last_origin   VARCHAR(255) DEFAULT '',
    check_count   INT          NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL,
    updated_at    DATETIME     NOT NULL,
    INDEX idx_key (license_key),
    INDEX idx_status (status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

// Host aus Origin/Referer/Eingabe herausziehen und normalisieren
function norm_host($value) {
  $value = trim((string)$value);
  if ($value === '') return '';
  // Schema weg
  $value = preg_replace('#^[a-z]+://#i', '', $value);
  // Pfad weg
  $value = explode('/', $value)[0];
  // Port weg
  $value = explode(':', $value)[0];
  $value = strtolower($value);
  // führendes www. normalisieren
  if (strpos($value, 'www.') === 0) $value = substr($value, 4);
  return $value;
}

function fetch_license($key) {
  $stmt = db()->prepare("SELECT * FROM begehung_licenses WHERE license_key = ? LIMIT 1");
  $stmt->execute([$key]);
  return $stmt->fetch() ?: null;
}

// --- Die eigentliche Prüfung ---
function handle_check($in, $origin, $serverTime) {
  $key = trim((string)($in['key'] ?? $in['license_key'] ?? ''));

  $respond = function($ok, $status, $lic = null, $msg = '') use ($serverTime) {
    $out = [
      'ok'          => $ok,
      'status'      => $status,
      'message'     => $msg,
      'serverTime'  => $serverTime,
      'valid_until' => $lic['valid_until'] ?? null,
      'grace_days'  => isset($lic['grace_days']) ? (int)$lic['grace_days'] : null,
      'days_left'   => null,
      'customer'    => $lic['customer'] ?? null,
    ];
    if (!empty($lic['valid_until'])) {
      $diff = (strtotime($lic['valid_until'] . ' 23:59:59') - time());
      $out['days_left'] = (int)floor($diff / 86400);
    }
    echo json_encode($out);
    exit;
  };

  if ($key === '') {
    $respond(false, 'unknown', null, 'Kein Lizenzschlüssel übergeben.');
  }

  $lic = fetch_license($key);
  if (!$lic) {
    $respond(false, 'unknown', null, 'Lizenzschlüssel nicht bekannt.');
  }

  // Zähler/Diagnose aktualisieren (best effort, blockiert nie)
  try {
    $callerHost = norm_host($origin ?: ($_SERVER['HTTP_REFERER'] ?? ''));
    $u = db()->prepare("UPDATE begehung_licenses
      SET last_check_at = NOW(), last_origin = ?, check_count = check_count + 1
      WHERE license_key = ?");
    $u->execute([$callerHost, $key]);
  } catch (Exception $e) {}

  // 1) Gesperrt (Not-Aus)
  if (($lic['status'] ?? 'active') === 'blocked') {
    $respond(false, 'blocked', $lic, 'Diese Lizenz wurde gesperrt.');
  }
  if (($lic['status'] ?? 'active') !== 'active') {
    $respond(false, 'inactive', $lic, 'Lizenz ist nicht aktiv.');
  }

  // 2) Domain-Lock
  $domains = $lic['domains'] ? (json_decode($lic['domains'], true) ?: []) : [];
  $domains = array_values(array_filter(array_map('norm_host', $domains)));
  if (!empty($domains)) {
    // Browser-Origin ist die vertrauenswürdige Quelle. Referer als Fallback,
    // Body-domain nur für Nicht-Browser-Aufrufe (curl/Test), nie wenn Origin da ist.
    $caller = norm_host($origin);
    if ($caller === '') $caller = norm_host($_SERVER['HTTP_REFERER'] ?? '');
    if ($caller === '' && !$origin) $caller = norm_host($in['domain'] ?? '');

    if ($caller === '' || !in_array($caller, $domains, true)) {
      $respond(false, 'domain_mismatch', $lic,
        'Diese Lizenz ist nicht für diese Domain freigegeben.');
    }
  }

  // 3) Ablauf (mit Toleranz/Grace)
  if (!empty($lic['valid_until'])) {
    $end      = strtotime($lic['valid_until'] . ' 23:59:59');
    $grace    = (int)($lic['grace_days'] ?? 0);
    $graceEnd = $end + $grace * 86400;
    $now      = time();
    if ($now > $graceEnd) {
      $respond(false, 'expired', $lic, 'Lizenz ist abgelaufen.');
    }
    if ($now > $end) {
      // In der Toleranzphase: noch gültig, aber Warnung
      $respond(true, 'grace', $lic, 'Lizenz abgelaufen — Toleranzphase läuft. Bitte verlängern.');
    }
  }

  // 4) Alles gut
  $respond(true, 'valid', $lic, 'Lizenz gültig.');
}

// =================== Admin-Funktionen ===================

function lic_row_public($r) {
  return [
    'license_key'   => $r['license_key'],
    'customer'      => $r['customer'],
    'domains'       => $r['domains'] ? (json_decode($r['domains'], true) ?: []) : [],
    'status'        => $r['status'],
    'valid_until'   => $r['valid_until'],
    'grace_days'    => (int)$r['grace_days'],
    'note'          => $r['note'],
    'last_check_at' => $r['last_check_at'],
    'last_origin'   => $r['last_origin'],
    'check_count'   => (int)$r['check_count'],
    'created_at'    => $r['created_at'],
    'updated_at'    => $r['updated_at'],
  ];
}

function admin_list() {
  $rows = db()->query("SELECT * FROM begehung_licenses ORDER BY created_at DESC")->fetchAll();
  echo json_encode(['ok' => true, 'licenses' => array_map('lic_row_public', $rows)]);
}

function gen_key() {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // ohne 0/O/1/I
  $block = function() use ($alphabet) {
    $s = '';
    for ($i = 0; $i < 4; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $s;
  };
  return 'LIZ-' . $block() . '-' . $block() . '-' . $block() . '-' . $block();
}

function admin_create($in) {
  $key = trim((string)($in['key'] ?? ''));
  if ($key === '') {
    do { $key = gen_key(); } while (fetch_license($key)); // Kollision praktisch ausgeschlossen
  }
  $domains = $in['domains'] ?? [];
  if (is_string($domains)) {
    // Komma-getrennte Eingabe erlauben
    $domains = array_filter(array_map('trim', explode(',', $domains)));
  }
  if (!is_array($domains)) $domains = [];
  $domains = array_values(array_filter(array_map('norm_host', $domains)));

  $validUntil = trim((string)($in['valid_until'] ?? ''));
  $validUntil = $validUntil !== '' ? substr($validUntil, 0, 10) : null;
  $graceDays  = isset($in['grace_days']) ? max(0, (int)$in['grace_days']) : 5;

  $stmt = db()->prepare("INSERT INTO begehung_licenses
    (license_key, customer, domains, status, valid_until, grace_days, note, created_at, updated_at)
    VALUES (?,?,?,?,?,?,?,NOW(),NOW())");
  $stmt->execute([
    $key,
    (string)($in['customer'] ?? ''),
    json_encode($domains, JSON_UNESCAPED_UNICODE),
    'active',
    $validUntil,
    $graceDays,
    (string)($in['note'] ?? ''),
  ]);
  echo json_encode(['ok' => true, 'license' => lic_row_public(fetch_license($key))]);
}

function admin_set_status($in, $status) {
  $key = trim((string)($in['key'] ?? ''));
  if ($key === '' || !fetch_license($key)) {
    echo json_encode(['ok' => false, 'error' => 'Key unbekannt']); exit;
  }
  db()->prepare("UPDATE begehung_licenses SET status = ?, updated_at = NOW() WHERE license_key = ?")
     ->execute([$status, $key]);
  echo json_encode(['ok' => true, 'license' => lic_row_public(fetch_license($key))]);
}

function admin_extend($in) {
  $key = trim((string)($in['key'] ?? ''));
  $validUntil = trim((string)($in['valid_until'] ?? ''));
  if ($key === '' || !fetch_license($key)) {
    echo json_encode(['ok' => false, 'error' => 'Key unbekannt']); exit;
  }
  $validUntil = $validUntil !== '' ? substr($validUntil, 0, 10) : null;
  db()->prepare("UPDATE begehung_licenses SET valid_until = ?, updated_at = NOW() WHERE license_key = ?")
     ->execute([$validUntil, $key]);
  echo json_encode(['ok' => true, 'license' => lic_row_public(fetch_license($key))]);
}

function admin_setdomains($in) {
  $key = trim((string)($in['key'] ?? ''));
  if ($key === '' || !fetch_license($key)) {
    echo json_encode(['ok' => false, 'error' => 'Key unbekannt']); exit;
  }
  $domains = $in['domains'] ?? [];
  if (is_string($domains)) $domains = array_filter(array_map('trim', explode(',', $domains)));
  if (!is_array($domains)) $domains = [];
  $domains = array_values(array_filter(array_map('norm_host', $domains)));
  db()->prepare("UPDATE begehung_licenses SET domains = ?, updated_at = NOW() WHERE license_key = ?")
     ->execute([json_encode($domains, JSON_UNESCAPED_UNICODE), $key]);
  echo json_encode(['ok' => true, 'license' => lic_row_public(fetch_license($key))]);
}

function admin_delete($in) {
  $key = trim((string)($in['key'] ?? ''));
  if ($key === '') { echo json_encode(['ok' => false, 'error' => 'Kein Key']); exit; }
  db()->prepare("DELETE FROM begehung_licenses WHERE license_key = ?")->execute([$key]);
  echo json_encode(['ok' => true, 'deleted' => $key]);
}
