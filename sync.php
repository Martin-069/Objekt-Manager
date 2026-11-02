<?php
// ===================================================================
// Begehungs-Tool – Stammdaten-Sync (Push + Pull)
//
// Request:
//   POST /api/sync.php
//   Header: X-API-Token: <token>
//   Body (JSON):
//     {
//       deviceId: "id-xxx",
//       deviceName: "Tablet 1",
//       lastSync: "2025-05-21 10:00:00" oder null,
//       push: {
//         properties: [...],
//         apartments: [...],
//         contractors: [...]
//       }
//     }
//
// Response:
//     {
//       serverTime: "2025-05-21 10:30:15",
//       stats: { pushed: {properties:3,...}, pulled: {properties:5,...} },
//       pull: {
//         properties: [...],
//         apartments: [...],
//         contractors: [...]
//       }
//     }
// ===================================================================

require_once __DIR__ . '/db.php';

// Globaler Fallback: falls irgendwo eine unerwartete Exception durchrutscht,
// soll IMMER sauberes JSON zurueckkommen statt ein leerer 500er ohne Info.
// TEMPORAER zur Fehlersuche - Detail nur bei DEBUG_MODE sichtbar.
set_exception_handler(function ($e) {
  http_response_code(500);
  echo json_encode([
    'error'  => 'Unerwarteter Serverfehler beim Sync',
    'detail' => (defined('DEBUG_MODE') && DEBUG_MODE)
      ? ($e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine())
      : null,
  ]);
  exit;
});

require_api_token();

$in = json_input();
$deviceId   = trim((string)($in['deviceId']   ?? ''));
$deviceName = trim((string)($in['deviceName'] ?? ''));
$lastSync   = (string)($in['lastSync'] ?? '');
if (empty($lastSync) || !preg_match('/^\d{4}-\d{2}-\d{2}/', $lastSync)) {
  $lastSync = '1970-01-01 00:00:00';
}
// Rolle des pushenden Benutzers (nur Admin darf Benutzer schreiben)
$pusherRole = trim((string)($in['pusherRole'] ?? ''));
$pusherUserId = trim((string)($in['pusherUserId'] ?? ''));

register_device($deviceId, $deviceName);

// Tombstone-Tabelle fuer endgueltig geloeschte Objekte sicherstellen
setup_purge_table();

// Auto-Migration: fehlende Spalten anlegen (jeder Sync, idempotent)
run_migrations();

// Endgueltiges Loeschen (nur Admin): action=purge  -> antwortet und beendet
if (($in['action'] ?? '') === 'purge') {
  if ($pusherRole !== 'admin') {
    json_response(['ok' => false, 'error' => 'Nur Admin darf endgueltig loeschen']);
  }
  handle_purge($in);
}

// Freshness-Check: Client schickt [{type, id, updated_at}],
// Server antwortet welche auf dem Server neuer sind als der Client-Stand.
// Leichtgewichtig, kein Push/Pull — nur Metadaten-Vergleich.
if (($in['action'] ?? '') === 'check_freshness') {
  handle_check_freshness($in);
}

$serverTime = (db()->query("SELECT NOW() AS t"))->fetch()['t'];

// Stammdaten (Liegenschaften, Wohnungen/Mieter, Firmen, Benutzer, Einstellungen)
// nimmt der Server NUR vom Admin an -> Datenhoheit liegt beim Admin.
// Begehungen (Haus + Wohnung) duerfen alle Geraete schreiben (das ist die Arbeit der User).
$isAdminPush = ($pusherRole === 'admin');
$blocked = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'blocked' => 'non-admin'];

$pushed = [
  'properties'  => $isAdminPush ? upsert_properties($in['push']['properties']  ?? [], $deviceId) : upsert_property_covers($in['push']['properties'] ?? [], $deviceId),
  'apartments'  => $isAdminPush ? upsert_apartments($in['push']['apartments']  ?? [], $deviceId) : upsert_apartments_user($in['push']['apartments'] ?? [], $deviceId, $pusherUserId),
  'contractors' => $isAdminPush ? upsert_contractors($in['push']['contractors'] ?? [], $deviceId) : $blocked,
  'buildingInspections'  => upsert_building_inspections($in['push']['buildingInspections']  ?? [], $deviceId),
  'apartmentInspections' => upsert_apartment_inspections($in['push']['apartmentInspections'] ?? [], $deviceId),
  'handovers'            => upsert_handovers($in['push']['handovers'] ?? [], $deviceId),
  'tickets'              => upsert_tickets($in['push']['tickets'] ?? [], $deviceId),
  'meters'               => upsert_meters($in['push']['meters'] ?? [], $deviceId),
  'users'       => $isAdminPush ? upsert_users($in['push']['users'] ?? [], $deviceId) : $blocked,
  'settings'    => upsert_settings_filtered($in['push']['settings'] ?? [], $deviceId, $isAdminPush),
  // v10.24: Benachrichtigungen — jeder User darf senden.
  'notifications' => upsert_notifications($in['push']['notifications'] ?? [], $deviceId),
];

$pull = [
  'properties'  => pull_table_full('begehung_properties'),
  'apartments'  => pull_table_full('begehung_apartments'),
  'contractors' => pull_table_full('begehung_contractors'),
  'buildingInspections'  => pull_table_full('begehung_building_inspections'),
  'apartmentInspections' => pull_table_full('begehung_apartment_inspections'),
  'handovers'            => pull_handovers_full(),
  'tickets'              => pull_tickets_full(),
  'meters'               => pull_meters_full(),
  'users'            => pull_users_full(),
  'portalFeedback'   => pull_portal_feedback($lastSync),
  'settings'    => pull_settings($lastSync, $deviceId),
  'purged'      => pull_purged($lastSync),
  'pdfs'        => pull_pdfs_meta(),
  // v10.24: Notifications sind user-bezogen — nur die Zeilen fuer diesen User + Broadcasts + eigene.
  'notifications' => pull_notifications_for_user($pusherUserId),
];

// Liste aller Photo-IDs auf dem Server (nur Metadaten, nicht die Datei selber)
// Damit Clients lazy nachladen koennen und wissen was bereits hochgeladen ist.
$photoStmt = db()->query(
  "SELECT id, owner_type, owner_id, mime_type, size_bytes, created_at
     FROM begehung_photos
    WHERE deleted = 0 AND owner_type = 'property-cover'

    UNION

  SELECT * FROM (
    SELECT id, owner_type, owner_id, mime_type, size_bytes, created_at
      FROM begehung_photos
     WHERE deleted = 0 AND owner_type <> 'property-cover'
     ORDER BY created_at DESC
     LIMIT 5000
  ) AS recent_photos"  // begrenzen, falls extrem viele Fotos (Cover-Fotos sind davon ausgenommen, s.o.)
);
$pull['photos'] = $photoStmt->fetchAll();

json_response([
  'serverTime' => $serverTime,
  'stats' => [
    'pushed' => $pushed,
    'pulled' => [
      'properties'  => count($pull['properties']),
      'apartments'  => count($pull['apartments']),
      'contractors' => count($pull['contractors']),
      'buildingInspections'  => count($pull['buildingInspections']),
      'apartmentInspections' => count($pull['apartmentInspections']),
      'handovers'            => count($pull['handovers']),
      'tickets'              => count($pull['tickets']),
      'meters'               => count($pull['meters']),
      'photos'      => count($pull['photos']),
      'users'       => count($pull['users']),
      'settings'    => count($pull['settings']),
      'purged'      => count($pull['purged']),
      'pdfs'        => count($pull['pdfs']),
      'notifications' => count($pull['notifications']),
    ],
  ],
  'pull' => $pull,
]);

// ---------------- Helper-Funktionen ----------------

// Tombstone-Tabelle: haelt IDs endgueltig geloeschter Objekte fest,
// damit andere Geraete sie nicht per Sync wieder einfuegen koennen.
function setup_purge_table() {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS begehung_purged (
      obj_type  VARCHAR(32) NOT NULL,
      obj_id    VARCHAR(64) NOT NULL,
      purged_at DATETIME NOT NULL,
      PRIMARY KEY (obj_type, obj_id),
      INDEX idx_purged_at (purged_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Exception $e) { /* bereits vorhanden */ }
}

// Liefert (gecacht) die Menge der endgueltig geloeschten IDs eines Typs.
function purged_ids($type) {
  static $cache = [];
  if (!array_key_exists($type, $cache)) {
    $cache[$type] = [];
    try {
      $stmt = db()->prepare("SELECT obj_id FROM begehung_purged WHERE obj_type=?");
      $stmt->execute([$type]);
      foreach ($stmt->fetchAll() as $row) $cache[$type][$row['obj_id']] = true;
    } catch (Exception $e) {}
  }
  return $cache[$type];
}

// Ein einzelnes Objekt hart loeschen + Tombstone setzen.
function purge_one($type, $id, $table, &$collected) {
  db()->prepare("DELETE FROM `$table` WHERE id=?")->execute([$id]);
  db()->prepare("INSERT INTO begehung_purged (obj_type, obj_id, purged_at)
                 VALUES (?,?,NOW())
                 ON DUPLICATE KEY UPDATE purged_at=NOW()")
     ->execute([$type, $id]);
  $collected[] = ['type' => $type, 'id' => $id];
}

// Endgueltiges Loeschen mit Kaskade. Antwortet per JSON und beendet.
function handle_purge($in) {
  $type = trim((string)($in['objType'] ?? ''));
  $id   = trim((string)($in['objId']   ?? ''));
  $tableOf = [
    'property'            => 'begehung_properties',
    'apartment'           => 'begehung_apartments',
    'contractor'          => 'begehung_contractors',
    'buildingInspection'  => 'begehung_building_inspections',
    'apartmentInspection' => 'begehung_apartment_inspections',
    'handover'            => 'begehung_handovers',
    'ticket'              => 'begehung_tickets',
    'meter'               => 'begehung_meters',
    'user'                => 'begehung_users',
  ];
  if (!isset($tableOf[$type]) || $id === '') {
    json_response(['ok' => false, 'error' => 'Ungueltiger Typ oder ID']);
  }
  $collected = [];
  try {
    // Kaskade: Liegenschaft -> Wohnungen + Haus-/Wohnungsbegehungen
    if ($type === 'property') {
      $q = db()->prepare("SELECT id FROM begehung_apartments WHERE property_id=?");
      $q->execute([$id]);
      foreach ($q->fetchAll() as $x) purge_one('apartment', $x['id'], 'begehung_apartments', $collected);

      $q = db()->prepare("SELECT id FROM begehung_building_inspections WHERE property_id=?");
      $q->execute([$id]);
      foreach ($q->fetchAll() as $x) purge_one('buildingInspection', $x['id'], 'begehung_building_inspections', $collected);

      $q = db()->prepare("SELECT id FROM begehung_apartment_inspections WHERE property_id=?");
      $q->execute([$id]);
      foreach ($q->fetchAll() as $x) purge_one('apartmentInspection', $x['id'], 'begehung_apartment_inspections', $collected);

      try {
        $q = db()->prepare("SELECT id FROM begehung_handovers WHERE property_id=?");
        $q->execute([$id]);
        foreach ($q->fetchAll() as $x) purge_one('handover', $x['id'], 'begehung_handovers', $collected);
      } catch (Exception $e) { /* Tabelle existiert evtl. noch nicht */ }
    }
    // Kaskade: Wohnung -> Wohnungsbegehungen + Übergaben
    if ($type === 'apartment') {
      $q = db()->prepare("SELECT id FROM begehung_apartment_inspections WHERE apartment_id=?");
      $q->execute([$id]);
      foreach ($q->fetchAll() as $x) purge_one('apartmentInspection', $x['id'], 'begehung_apartment_inspections', $collected);

      try {
        $q = db()->prepare("SELECT id FROM begehung_handovers WHERE apartment_id=?");
        $q->execute([$id]);
        foreach ($q->fetchAll() as $x) purge_one('handover', $x['id'], 'begehung_handovers', $collected);
      } catch (Exception $e) { /* Tabelle existiert evtl. noch nicht */ }
    }
    // Das eigentliche Objekt
    purge_one($type, $id, $tableOf[$type], $collected);
  } catch (Exception $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()]);
  }
  json_response(['ok' => true, 'purged' => $collected]);
}

// Liefert die seit $since hinzugekommenen Tombstones (Typ + ID),
// damit andere Geraete die Objekte lokal entfernen.
function pull_purged($since) {
  try {
    $stmt = db()->prepare(
      "SELECT obj_type AS type, obj_id AS id
         FROM begehung_purged
        WHERE purged_at > :since
        ORDER BY purged_at ASC"
    );
    $stmt->execute([':since' => $since]);
    return $stmt->fetchAll();
  } catch (Exception $e) { return []; }
}

// Wie pull_table, aber ohne Zeit-/Geraetefilter: liefert die komplette Tabelle.
// Fuer ueberschaubare Stammdaten (Liegenschaften, Wohnungen), damit Aenderungen
// unabhaengig von Uhrzeit-Differenzen der Geraete zuverlaessig ankommen.
function pull_table_full($table) {
  $rows = db()->query("SELECT * FROM `$table` ORDER BY updated_at ASC")->fetchAll();

  if ($table === 'begehung_properties') {
    foreach ($rows as &$r) {
      $r['building_units'] = $r['building_units']
        ? (json_decode($r['building_units'], true) ?: [])
        : [];
    }
    unset($r);
  }
  if ($table === 'begehung_apartments') {
    foreach ($rows as &$r) {
      $r['tenant_history'] = !empty($r['tenant_history'])
        ? (json_decode($r['tenant_history'], true) ?: [])
        : [];
    }
    unset($r);
  }
  if ($table === 'begehung_building_inspections' ||
      $table === 'begehung_apartment_inspections' ||
      $table === 'begehung_handovers' ||
      $table === 'begehung_tickets') {
    foreach ($rows as &$r) {
      $r['data'] = $r['data'] ? (json_decode($r['data'], true) ?: []) : [];
    }
    unset($r);
  }
  return $rows;
}

// Holt Datensaetze, die nach $since aktualisiert wurden,
// jedoch ohne die vom aktuellen Geraet selber gepushten
// (sonst wuerden sie sofort wieder zurueckkommen).
function pull_table($table, $since, $deviceId) {
  $stmt = db()->prepare(
    "SELECT * FROM `$table`
      WHERE updated_at > :since
        AND (device_id IS NULL OR device_id <> :dev)
      ORDER BY updated_at ASC"
  );
  $stmt->execute([':since' => $since, ':dev' => $deviceId]);
  $rows = $stmt->fetchAll();

  if ($table === 'begehung_properties') {
    foreach ($rows as &$r) {
      $r['building_units'] = $r['building_units']
        ? (json_decode($r['building_units'], true) ?: [])
        : [];
    }
  }
  if ($table === 'begehung_apartments') {
    foreach ($rows as &$r) {
      $r['tenant_history'] = !empty($r['tenant_history'])
        ? (json_decode($r['tenant_history'], true) ?: [])
        : [];
    }
  }
  // Inspection-Tabellen: data-Feld als JSON dekodieren
  if ($table === 'begehung_building_inspections' ||
      $table === 'begehung_apartment_inspections' ||
      $table === 'begehung_handovers' ||
      $table === 'begehung_tickets') {
    foreach ($rows as &$r) {
      $r['data'] = $r['data'] ? (json_decode($r['data'], true) ?: []) : [];
    }
  }
  // Users: assigned_properties als JSON dekodieren
  if ($table === 'begehung_users') {
    foreach ($rows as &$r) {
      $r['assigned_properties'] = $r['assigned_properties']
        ? (json_decode($r['assigned_properties'], true) ?: [])
        : [];
    }
  }
  return $rows;
}

// Nicht-Admins duerfen NUR das Hauptfoto (cover_photo_id) bestehender
// Liegenschaften setzen - alle anderen Stammdaten-Felder bleiben unberuehrt.
// updated_at wird auf Server-NOW() gesetzt, damit das Foto uhrzeit-unabhaengig
// an alle Geraete propagiert. Update nur, wenn sich das Cover wirklich aendert
// (NULL-sicherer Vergleich via <=>), das verhindert Sync-Schleifen.
function upsert_property_covers($rows, $deviceId) {
  $cnt = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
  if (!is_array($rows)) return $cnt;
  $sql = "UPDATE begehung_properties
             SET cover_photo_id = :cover, updated_at = NOW(), device_id = :device
           WHERE id = :id AND NOT (cover_photo_id <=> :cover_cmp)";
  $stmt = db()->prepare($sql);
  foreach ($rows as $r) {
    if (empty($r['id'])) { $cnt['skipped']++; continue; }
    $cover = $r['cover_photo_id'] ?? $r['coverPhotoId'] ?? null;
    $stmt->execute([':cover' => $cover, ':cover_cmp' => $cover, ':device' => $deviceId, ':id' => $r['id']]);
    if ($stmt->rowCount() > 0) $cnt['updated']++; else $cnt['skipped']++;
  }
  return $cnt;
}

// UPSERT von Properties (Last-Write-Wins via updated_at)
function upsert_properties($rows, $deviceId) {
  $cnt = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
  if (!is_array($rows)) return $cnt;
  // v9.73-serverpatch: billing_recipient/service_recipient (v9.28-Felder) waren
  // in upsert_properties nicht enthalten — Client hat sie zwar gepusht, Server
  // hat sie stumm ignoriert. Deshalb tauchten sie bei Rechnern nach Sync nie auf.
  $sql = "INSERT INTO begehung_properties
    (id, number, name, address, caretaker, building_units, cover_photo_id,
     billing_recipient, service_recipient,
     created_at, updated_at, deleted, device_id)
    VALUES
    (:id, :number, :name, :address, :caretaker, :units, :cover,
     :billing, :service,
     :created, :updated, :deleted, :device)
    ON DUPLICATE KEY UPDATE
      number            = IF(VALUES(updated_at) > updated_at, VALUES(number), number),
      name              = IF(VALUES(updated_at) > updated_at, VALUES(name), name),
      address           = IF(VALUES(updated_at) > updated_at, VALUES(address), address),
      caretaker         = IF(VALUES(updated_at) > updated_at, VALUES(caretaker), caretaker),
      building_units    = IF(VALUES(updated_at) > updated_at, VALUES(building_units), building_units),
      cover_photo_id    = IF(VALUES(updated_at) > updated_at, VALUES(cover_photo_id), cover_photo_id),
      billing_recipient = IF(VALUES(updated_at) > updated_at, VALUES(billing_recipient), billing_recipient),
      service_recipient = IF(VALUES(updated_at) > updated_at, VALUES(service_recipient), service_recipient),
      deleted           = IF(VALUES(updated_at) > updated_at, VALUES(deleted), deleted),
      updated_at        = GREATEST(VALUES(updated_at), updated_at),
      device_id         = IF(VALUES(updated_at) > updated_at, VALUES(device_id), device_id)";
  $stmt = db()->prepare($sql);
  $pg = purged_ids('property');
  foreach ($rows as $r) {
    if (empty($r['id'])) { $cnt['skipped']++; continue; }
    if (isset($pg[$r['id']])) { $cnt['skipped']++; continue; }
    $units = $r['building_units'] ?? $r['buildingUnits'] ?? [];
    if (!is_array($units)) $units = [];
    $params = [
      ':id'        => $r['id'],
      ':number'    => (string)($r['number'] ?? ''),
      ':name'      => (string)($r['name'] ?? ''),
      ':address'   => (string)($r['address'] ?? ''),
      ':caretaker' => (string)($r['caretaker'] ?? ''),
      ':units'     => json_encode($units, JSON_UNESCAPED_UNICODE),
      ':cover'     => $r['cover_photo_id'] ?? $r['coverPhotoId'] ?? null,
      ':billing'   => (string)($r['billing_recipient'] ?? $r['billingRecipient'] ?? ''),
      ':service'   => (string)($r['service_recipient'] ?? $r['serviceRecipient'] ?? ''),
      ':created'   => mysql_datetime($r['created_at'] ?? $r['createdAt'] ?? null),
      ':updated'   => mysql_datetime($r['updated_at'] ?? $r['updatedAt'] ?? null),
      ':deleted'   => !empty($r['deleted']) ? 1 : 0,
      ':device'    => $deviceId,
    ];
    $stmt->execute($params);
    $cnt[$stmt->rowCount() === 1 ? 'inserted' : 'updated']++;
  }
  return $cnt;
}

// UPSERT von Apartments
function upsert_apartments($rows, $deviceId) {
  $cnt = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
  if (!is_array($rows)) return $cnt;
  // Spalten type + usage_type + inspection_skip ggf. anlegen (Migration)
  try { db()->exec("ALTER TABLE begehung_apartments ADD COLUMN type VARCHAR(20) DEFAULT 'wohnung'"); } catch (Exception $e) {}
  try { db()->exec("ALTER TABLE begehung_apartments ADD COLUMN usage_type VARCHAR(100) DEFAULT ''"); } catch (Exception $e) {}
  try { db()->exec("ALTER TABLE begehung_apartments ADD COLUMN inspection_skip VARCHAR(32) DEFAULT ''"); } catch (Exception $e) {}
  try { db()->exec("ALTER TABLE begehung_apartments ADD COLUMN inspection_skip_note VARCHAR(500) DEFAULT ''"); } catch (Exception $e) {}
  try { db()->exec("ALTER TABLE begehung_apartments ADD COLUMN inspection_skip_until DATE DEFAULT NULL"); } catch (Exception $e) {}
  try { db()->exec("ALTER TABLE begehung_apartments ADD COLUMN tenant_history JSON DEFAULT NULL"); } catch (Exception $e) {}

  $sql = "INSERT INTO begehung_apartments
    (id, property_id, number, floor, building_unit, location, tenant, phone, email, rooms, notes,
     type, usage_type, inspection_skip, inspection_skip_note, inspection_skip_until, tenant_history,
     created_at, updated_at, deleted, device_id)
    VALUES
    (:id, :propId, :number, :floor, :unit, :location, :tenant, :phone, :email, :rooms, :notes,
     :type, :usageType, :inspSkip, :inspSkipNote, :inspSkipUntil, :tenantHistory,
     :created, :updated, :deleted, :device)
    ON DUPLICATE KEY UPDATE
      property_id          = IF(VALUES(updated_at) > updated_at, VALUES(property_id), property_id),
      number               = IF(VALUES(updated_at) > updated_at, VALUES(number), number),
      floor                = IF(VALUES(updated_at) > updated_at, VALUES(floor), floor),
      building_unit        = IF(VALUES(updated_at) > updated_at, VALUES(building_unit), building_unit),
      location             = IF(VALUES(updated_at) > updated_at, VALUES(location), location),
      tenant               = IF(VALUES(updated_at) > updated_at, VALUES(tenant), tenant),
      phone                = IF(VALUES(updated_at) > updated_at, VALUES(phone), phone),
      email                = IF(VALUES(updated_at) > updated_at, VALUES(email), email),
      rooms                = IF(VALUES(updated_at) > updated_at, VALUES(rooms), rooms),
      notes                = IF(VALUES(updated_at) > updated_at, VALUES(notes), notes),
      type                 = IF(VALUES(updated_at) > updated_at, VALUES(type), type),
      usage_type           = IF(VALUES(updated_at) > updated_at, VALUES(usage_type), usage_type),
      inspection_skip      = IF(VALUES(updated_at) > updated_at, VALUES(inspection_skip), inspection_skip),
      inspection_skip_note = IF(VALUES(updated_at) > updated_at, VALUES(inspection_skip_note), inspection_skip_note),
      inspection_skip_until= IF(VALUES(updated_at) > updated_at, VALUES(inspection_skip_until), inspection_skip_until),
      tenant_history       = IF(VALUES(updated_at) > updated_at, VALUES(tenant_history), tenant_history),
      deleted              = IF(VALUES(updated_at) > updated_at, VALUES(deleted), deleted),
      updated_at           = GREATEST(VALUES(updated_at), updated_at),
      device_id            = IF(VALUES(updated_at) > updated_at, VALUES(device_id), device_id)";
  $stmt = db()->prepare($sql);
  $pg = purged_ids('apartment');
  foreach ($rows as $r) {
    if (empty($r['id']) || empty($r['property_id'] ?? $r['propertyId'])) {
      $cnt['skipped']++; continue;
    }
    if (isset($pg[$r['id']])) { $cnt['skipped']++; continue; }
    $tenantHistory = $r['tenant_history'] ?? $r['tenantHistory'] ?? null;
    if (is_array($tenantHistory)) $tenantHistory = json_encode($tenantHistory, JSON_UNESCAPED_UNICODE);
    $skipUntil = $r['inspection_skip_until'] ?? $r['inspectionSkipUntil'] ?? null;
    if ($skipUntil) $skipUntil = substr($skipUntil, 0, 10);
    $params = [
      ':id'            => $r['id'],
      ':propId'        => $r['property_id'] ?? $r['propertyId'],
      ':number'        => (string)($r['number'] ?? ''),
      ':floor'         => (string)($r['floor'] ?? ''),
      ':unit'          => (string)($r['building_unit'] ?? $r['buildingUnit'] ?? ''),
      ':location'      => (string)($r['location'] ?? ''),
      ':tenant'        => (string)($r['tenant'] ?? ''),
      ':phone'         => (string)($r['phone'] ?? ''),
      ':email'         => (string)($r['email'] ?? ''),
      ':rooms'         => (string)($r['rooms'] ?? ''),
      ':notes'         => (string)($r['notes'] ?? ''),
      ':type'          => (string)($r['type'] ?? 'wohnung'),
      ':usageType'     => (string)($r['usage_type'] ?? $r['usageType'] ?? ''),
      ':inspSkip'      => (string)($r['inspection_skip'] ?? $r['inspectionSkip'] ?? ''),
      ':inspSkipNote'  => (string)($r['inspection_skip_note'] ?? $r['inspectionSkipNote'] ?? ''),
      ':inspSkipUntil' => $skipUntil ?: null,
      ':tenantHistory' => $tenantHistory ?: '[]',
      ':created'       => mysql_datetime($r['created_at'] ?? $r['createdAt'] ?? null),
      ':updated'       => mysql_datetime($r['updated_at'] ?? $r['updatedAt'] ?? null),
      ':deleted'       => !empty($r['deleted']) ? 1 : 0,
      ':device'        => $deviceId,
    ];
    $stmt->execute($params);
    $cnt[$stmt->rowCount() === 1 ? 'inserted' : 'updated']++;
  }
  return $cnt;
}

// UPSERT von Apartments durch Nicht-Admin-User:
// Nur type='gewerbe' erlaubt, und nur fuer Liegenschaften die dem User zugewiesen sind.
// Alle anderen Datensaetze werden uebersprungen (Datenhoheit bleibt beim Admin).
function upsert_apartments_user($rows, $deviceId, $userId) {
  $cnt = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
  if (!is_array($rows) || empty($rows)) return $cnt;
  if (empty($userId)) {
    $cnt['blocked'] = 'pusherUserId fehlt';
    return $cnt;
  }

  // Zugewiesene Liegenschaften des Users aus DB holen (Server = Single Source of Truth)
  $assignedRaw = '';
  try {
    $stmt = db()->prepare("SELECT assigned_properties FROM begehung_users WHERE id = :uid LIMIT 1");
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();
    $assignedRaw = $row ? ($row['assigned_properties'] ?? '') : '';
  } catch (Exception $e) {
    $cnt['blocked'] = 'User nicht gefunden';
    return $cnt;
  }
  $assigned = $assignedRaw ? (json_decode($assignedRaw, true) ?: []) : [];
  if (empty($assigned)) {
    $cnt['blocked'] = 'Keine Liegenschaften zugewiesen';
    return $cnt;
  }
  $allowedProps = array_flip($assigned);

  // Nur Gewerbemieter fuer zugewiesene Liegenschaften durchlassen
  $filtered = [];
  foreach ($rows as $r) {
    $type = (string)($r['type'] ?? 'wohnung');
    $propId = $r['property_id'] ?? $r['propertyId'] ?? '';
    if ($type === 'gewerbe' && isset($allowedProps[$propId])) {
      $filtered[] = $r;
    } else {
      $cnt['skipped']++;
    }
  }

  if (empty($filtered)) return $cnt;

  // Spalten sicherstellen (gleiche Migration wie in upsert_apartments)
  try { db()->exec("ALTER TABLE begehung_apartments ADD COLUMN type VARCHAR(20) DEFAULT 'wohnung'"); } catch (Exception $e) {}
  try { db()->exec("ALTER TABLE begehung_apartments ADD COLUMN usage_type VARCHAR(100) DEFAULT ''"); } catch (Exception $e) {}
  try { db()->exec("ALTER TABLE begehung_apartments ADD COLUMN inspection_skip VARCHAR(32) DEFAULT ''"); } catch (Exception $e) {}
  try { db()->exec("ALTER TABLE begehung_apartments ADD COLUMN inspection_skip_note VARCHAR(500) DEFAULT ''"); } catch (Exception $e) {}
  try { db()->exec("ALTER TABLE begehung_apartments ADD COLUMN inspection_skip_until DATE DEFAULT NULL"); } catch (Exception $e) {}
  try { db()->exec("ALTER TABLE begehung_apartments ADD COLUMN tenant_history JSON DEFAULT NULL"); } catch (Exception $e) {}

  $sql = "INSERT INTO begehung_apartments
    (id, property_id, number, floor, building_unit, location, tenant, phone, email, rooms, notes,
     type, usage_type, inspection_skip, inspection_skip_note, inspection_skip_until, tenant_history,
     created_at, updated_at, deleted, device_id)
    VALUES
    (:id, :propId, :number, :floor, :unit, :location, :tenant, :phone, :email, :rooms, :notes,
     :type, :usageType, :inspSkip, :inspSkipNote, :inspSkipUntil, :tenantHistory,
     :created, :updated, :deleted, :device)
    ON DUPLICATE KEY UPDATE
      property_id          = IF(VALUES(updated_at) > updated_at, VALUES(property_id), property_id),
      number               = IF(VALUES(updated_at) > updated_at, VALUES(number), number),
      floor                = IF(VALUES(updated_at) > updated_at, VALUES(floor), floor),
      building_unit        = IF(VALUES(updated_at) > updated_at, VALUES(building_unit), building_unit),
      location             = IF(VALUES(updated_at) > updated_at, VALUES(location), location),
      tenant               = IF(VALUES(updated_at) > updated_at, VALUES(tenant), tenant),
      phone                = IF(VALUES(updated_at) > updated_at, VALUES(phone), phone),
      email                = IF(VALUES(updated_at) > updated_at, VALUES(email), email),
      rooms                = IF(VALUES(updated_at) > updated_at, VALUES(rooms), rooms),
      notes                = IF(VALUES(updated_at) > updated_at, VALUES(notes), notes),
      type                 = IF(VALUES(updated_at) > updated_at, VALUES(type), type),
      usage_type           = IF(VALUES(updated_at) > updated_at, VALUES(usage_type), usage_type),
      inspection_skip      = IF(VALUES(updated_at) > updated_at, VALUES(inspection_skip), inspection_skip),
      inspection_skip_note = IF(VALUES(updated_at) > updated_at, VALUES(inspection_skip_note), inspection_skip_note),
      inspection_skip_until= IF(VALUES(updated_at) > updated_at, VALUES(inspection_skip_until), inspection_skip_until),
      tenant_history       = IF(VALUES(updated_at) > updated_at, VALUES(tenant_history), tenant_history),
      deleted              = IF(VALUES(updated_at) > updated_at, VALUES(deleted), deleted),
      updated_at           = GREATEST(VALUES(updated_at), updated_at),
      device_id            = IF(VALUES(updated_at) > updated_at, VALUES(device_id), device_id)";
  $stmt = db()->prepare($sql);
  $pg = purged_ids('apartment');
  foreach ($filtered as $r) {
    if (empty($r['id']) || empty($r['property_id'] ?? $r['propertyId'])) {
      $cnt['skipped']++; continue;
    }
    if (isset($pg[$r['id']])) { $cnt['skipped']++; continue; }
    $tenantHistory = $r['tenant_history'] ?? $r['tenantHistory'] ?? null;
    if (is_array($tenantHistory)) $tenantHistory = json_encode($tenantHistory, JSON_UNESCAPED_UNICODE);
    $skipUntil = $r['inspection_skip_until'] ?? $r['inspectionSkipUntil'] ?? null;
    if ($skipUntil) $skipUntil = substr($skipUntil, 0, 10);
    $params = [
      ':id'            => $r['id'],
      ':propId'        => $r['property_id'] ?? $r['propertyId'],
      ':number'        => (string)($r['number'] ?? ''),
      ':floor'         => (string)($r['floor'] ?? ''),
      ':unit'          => (string)($r['building_unit'] ?? $r['buildingUnit'] ?? ''),
      ':location'      => (string)($r['location'] ?? ''),
      ':tenant'        => (string)($r['tenant'] ?? ''),
      ':phone'         => (string)($r['phone'] ?? ''),
      ':email'         => (string)($r['email'] ?? ''),
      ':rooms'         => (string)($r['rooms'] ?? ''),
      ':notes'         => (string)($r['notes'] ?? ''),
      ':type'          => 'gewerbe',
      ':usageType'     => (string)($r['usage_type'] ?? $r['usageType'] ?? ''),
      ':inspSkip'      => (string)($r['inspection_skip'] ?? $r['inspectionSkip'] ?? ''),
      ':inspSkipNote'  => (string)($r['inspection_skip_note'] ?? $r['inspectionSkipNote'] ?? ''),
      ':inspSkipUntil' => $skipUntil ?: null,
      ':tenantHistory' => $tenantHistory ?: '[]',
      ':created'   => mysql_datetime($r['created_at'] ?? $r['createdAt'] ?? null),
      ':updated'   => mysql_datetime($r['updated_at'] ?? $r['updatedAt'] ?? null),
      ':deleted'   => !empty($r['deleted']) ? 1 : 0,
      ':device'    => $deviceId,
    ];
    try {
      $stmt->execute($params);
      $cnt[$stmt->rowCount() === 1 ? 'inserted' : 'updated']++;
    } catch (Exception $e) {
      $cnt['skipped']++;
    }
  }
  return $cnt;
}

// UPSERT von Contractors
function upsert_contractors($rows, $deviceId) {
  $cnt = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
  if (!is_array($rows)) return $cnt;
  $sql = "INSERT INTO begehung_contractors
    (id, name, email, phone, mobile, contact, address, trade, notes,
     created_at, updated_at, deleted, device_id)
    VALUES
    (:id, :name, :email, :phone, :mobile, :contact, :address, :trade, :notes,
     :created, :updated, :deleted, :device)
    ON DUPLICATE KEY UPDATE
      name      = IF(VALUES(updated_at) > updated_at, VALUES(name), name),
      email     = IF(VALUES(updated_at) > updated_at, VALUES(email), email),
      phone     = IF(VALUES(updated_at) > updated_at, VALUES(phone), phone),
      mobile    = IF(VALUES(updated_at) > updated_at, VALUES(mobile), mobile),
      contact   = IF(VALUES(updated_at) > updated_at, VALUES(contact), contact),
      address   = IF(VALUES(updated_at) > updated_at, VALUES(address), address),
      trade     = IF(VALUES(updated_at) > updated_at, VALUES(trade), trade),
      notes     = IF(VALUES(updated_at) > updated_at, VALUES(notes), notes),
      deleted   = IF(VALUES(updated_at) > updated_at, VALUES(deleted), deleted),
      updated_at = GREATEST(VALUES(updated_at), updated_at),
      device_id = IF(VALUES(updated_at) > updated_at, VALUES(device_id), device_id)";
  $stmt = db()->prepare($sql);
  $pgc = purged_ids('contractor');
  foreach ($rows as $r) {
    if (empty($r['id']) || empty($r['name'])) { $cnt['skipped']++; continue; }
    if (isset($pgc[$r['id']])) { $cnt['skipped']++; continue; }
    $params = [
      ':id'      => $r['id'],
      ':name'    => (string)$r['name'],
      ':email'   => (string)($r['email'] ?? ''),
      ':phone'   => (string)($r['phone'] ?? ''),
      ':mobile'  => (string)($r['mobile'] ?? ''),
      ':contact' => (string)($r['contact'] ?? ''),
      ':address' => (string)($r['address'] ?? ''),
      ':trade'   => (string)($r['trade'] ?? ''),
      ':notes'   => (string)($r['notes'] ?? ''),
      ':created' => mysql_datetime($r['created_at'] ?? $r['createdAt'] ?? null),
      ':updated' => mysql_datetime($r['updated_at'] ?? $r['updatedAt'] ?? null),
      ':deleted' => !empty($r['deleted']) ? 1 : 0,
      ':device'  => $deviceId,
    ];
    $stmt->execute($params);
    $cnt[$stmt->rowCount() === 1 ? 'inserted' : 'updated']++;
  }
  return $cnt;
}

// UPSERT von Benutzern (inkl. PBKDF2-Hashes fuer geraeteuebergreifendes Login)
function upsert_users($rows, $deviceId) {
  $cnt = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
  if (!is_array($rows)) return $cnt;
  // v9.70: Zusätzlich email, phone, mobile, position übernehmen (aus Kontaktdaten des Users)
  $sql = "INSERT INTO begehung_users
    (id, username, display_name, password_hash, password_salt, role,
     assigned_properties, active, email, phone, mobile, position,
     created_at, updated_at, deleted, device_id)
    VALUES
    (:id, :username, :display, :hash, :salt, :role,
     :assigned, :active, :email, :phone, :mobile, :position,
     :created, :updated, :deleted, :device)
    ON DUPLICATE KEY UPDATE
      username            = IF(VALUES(updated_at) > updated_at, VALUES(username), username),
      display_name        = IF(VALUES(updated_at) > updated_at, VALUES(display_name), display_name),
      password_hash       = IF(VALUES(updated_at) > updated_at, VALUES(password_hash), password_hash),
      password_salt       = IF(VALUES(updated_at) > updated_at, VALUES(password_salt), password_salt),
      role                = IF(VALUES(updated_at) > updated_at, VALUES(role), role),
      assigned_properties = IF(VALUES(updated_at) > updated_at, VALUES(assigned_properties), assigned_properties),
      active              = IF(VALUES(updated_at) > updated_at, VALUES(active), active),
      email               = IF(VALUES(updated_at) > updated_at, VALUES(email), email),
      phone               = IF(VALUES(updated_at) > updated_at, VALUES(phone), phone),
      mobile              = IF(VALUES(updated_at) > updated_at, VALUES(mobile), mobile),
      position            = IF(VALUES(updated_at) > updated_at, VALUES(position), position),
      deleted             = IF(VALUES(updated_at) > updated_at, VALUES(deleted), deleted),
      updated_at          = GREATEST(VALUES(updated_at), updated_at),
      device_id           = IF(VALUES(updated_at) > updated_at, VALUES(device_id), device_id)";
  $stmt = db()->prepare($sql);
  $pgu = purged_ids('user');
  $errors = [];
  foreach ($rows as $r) {
    if (empty($r['id']) || empty($r['username'])) { $cnt['skipped']++; continue; }
    if (isset($pgu[$r['id']])) { $cnt['skipped']++; continue; }
    $assigned = $r['assigned_properties'] ?? $r['assignedProperties'] ?? [];
    if (!is_array($assigned)) $assigned = [];
    // v9.67: Rollen-Whitelist erweitert um 'super-user' (Firmen verwalten, Portal, Tickets)
    $rawRole = (string)($r['role'] ?? 'user');
    $role = in_array($rawRole, ['admin', 'super-user', 'user'], true) ? $rawRole : 'user';
    $params = [
      ':id'       => $r['id'],
      ':username' => (string)$r['username'],
      ':display'  => (string)($r['display_name'] ?? $r['displayName'] ?? ''),
      ':hash'     => (string)($r['password_hash'] ?? $r['passwordHash'] ?? ''),
      ':salt'     => (string)($r['password_salt'] ?? $r['passwordSalt'] ?? ''),
      ':role'     => $role,
      ':assigned' => json_encode($assigned, JSON_UNESCAPED_UNICODE),
      ':active'   => !empty($r['active']) ? 1 : 0,
      // v9.70: Kontaktdaten (Position/E-Mail/Festnetz/Mobil)
      ':email'    => (string)($r['email']    ?? ''),
      ':phone'    => (string)($r['phone']    ?? ''),
      ':mobile'   => (string)($r['mobile']   ?? ''),
      ':position' => (string)($r['position'] ?? ''),
      ':created'  => mysql_datetime($r['created_at'] ?? $r['createdAt'] ?? null),
      ':updated'  => mysql_datetime($r['updated_at'] ?? $r['updatedAt'] ?? null),
      ':deleted'  => !empty($r['deleted']) ? 1 : 0,
      ':device'   => $deviceId,
    ];
    // TEMPORAER: pro-Zeile abgesichert, damit ein fehlerhafter Benutzer-Datensatz
    // nicht den gesamten Sync mit 500 abschiessen kann.
    try {
      $stmt->execute($params);
      $cnt[$stmt->rowCount() === 1 ? 'inserted' : 'updated']++;
    } catch (Exception $e) {
      $cnt['skipped']++;
      $errors[] = [
        'username' => $r['username'],
        'message'  => (defined('DEBUG_MODE') && DEBUG_MODE) ? $e->getMessage() : 'Fehler beim Speichern',
      ];
    }
  }
  if ($errors) $cnt['errors'] = $errors;
  return $cnt;
}

// UPSERT von globalen Einstellungen (Key-Value, z. B. Checklisten-Vorlage)
// Wrapper: Admins duerfen alle Settings pushen, Non-Admins nur seen_feedback_*
function upsert_settings_filtered($rows, $deviceId, $isAdmin) {
  if (!is_array($rows)) return ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
  if ($isAdmin) return upsert_settings($rows, $deviceId);
  // Non-Admin: nur seen_feedback_* durchlassen
  $filtered = array_filter($rows, function($r) {
    $k = $r['key'] ?? $r['skey'] ?? '';
    return strpos($k, 'seen_feedback_') === 0;
  });
  return upsert_settings(array_values($filtered), $deviceId);
}

function upsert_settings($rows, $deviceId) {
  $cnt = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
  if (!is_array($rows)) return $cnt;

  // History-Tabelle sicherstellen (v9.51)
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS begehung_settings_history (
      id           INT AUTO_INCREMENT PRIMARY KEY,
      skey         VARCHAR(100),
      svalue       LONGTEXT,
      section_cnt  INT,
      changed_at   DATETIME,
      device_id    VARCHAR(64),
      INDEX idx_hist_key (skey, changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Exception $e) {}

  $sql = "INSERT INTO begehung_settings (skey, svalue, updated_at, device_id)
    VALUES (:k, :v, :updated, :device)
    ON DUPLICATE KEY UPDATE
      svalue     = IF(VALUES(updated_at) > updated_at, VALUES(svalue), svalue),
      updated_at = GREATEST(VALUES(updated_at), updated_at),
      device_id  = IF(VALUES(updated_at) > updated_at, VALUES(device_id), device_id)";
  $stmt = db()->prepare($sql);
  foreach ($rows as $r) {
    $k = $r['key'] ?? $r['skey'] ?? '';
    if (empty($k)) { $cnt['skipped']++; continue; }
    $v = $r['value'] ?? $r['svalue'] ?? '';
    if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);

    // SCHUTZ (v9.51): Vorlagen dürfen NIE schrumpfen
    if ($k === 'apartment_checklist' || strpos($k, 'building_checklist_') === 0) {
      $incoming = json_decode($v, true);
      $incomingCnt = is_array($incoming) ? count($incoming) : 0;

      // Aktuellen Server-Stand holen
      $q = db()->prepare("SELECT svalue FROM begehung_settings WHERE skey = ?");
      $q->execute([$k]);
      $existing = $q->fetch();
      if ($existing && $existing['svalue']) {
        $existingArr = json_decode($existing['svalue'], true);
        $existingCnt = is_array($existingArr) ? count($existingArr) : 0;
        if ($existingCnt > 0 && $incomingCnt < $existingCnt) {
          // Reduzierung blockiert
          error_log("Vorlagen-Schutz: $k Reduzierung blockiert ($incomingCnt < $existingCnt) von device $deviceId");
          $cnt['skipped']++;
          continue;
        }
        // Backup der alten Version in History speichern
        try {
          $hist = db()->prepare("INSERT INTO begehung_settings_history (skey, svalue, section_cnt, changed_at, device_id) VALUES (?, ?, ?, NOW(), ?)");
          $hist->execute([$k, $existing['svalue'], $existingCnt, $deviceId]);
        } catch (Exception $e) {}
      }
    }

    $stmt->execute([
      ':k'       => $k,
      ':v'       => $v,
      ':updated' => mysql_datetime($r['updated_at'] ?? $r['updatedAt'] ?? null),
      ':device'  => $deviceId,
    ]);
    $cnt[$stmt->rowCount() === 1 ? 'inserted' : 'updated']++;
  }
  return $cnt;
}

// Pull der Einstellungen (neuer als $since, nicht vom eigenen Geraet)
// Liefert die volle Benutzerliste (Admin ist autoritative Quelle).
// Immer komplett, damit andere Geraete sich exakt angleichen koennen
// und nicht von Uhrzeit-Differenzen abhaengen.
// Liefert die volle Firmenliste. Firmen sind wenige Zeilen -> immer komplett,
// damit neu angelegte Firmen unabhaengig von Uhrzeit-Differenzen der Geraete
// zuverlaessig bei allen ankommen.
function pull_contractors_full() {
  return db()->query(
    "SELECT id, name, email, phone, mobile, contact, address, trade, notes,
            created_at, updated_at, deleted
       FROM begehung_contractors
      ORDER BY updated_at ASC"
  )->fetchAll();
}

function pull_users_full() {
  $rows = db()->query("SELECT * FROM begehung_users ORDER BY updated_at ASC")->fetchAll();
  foreach ($rows as &$r) {
    $r['assigned_properties'] = $r['assigned_properties']
      ? (json_decode($r['assigned_properties'], true) ?: [])
      : [];
  }
  return $rows;
}

function pull_settings($since, $deviceId) {
  // Settings sind wenige globale Zeilen -> immer komplett liefern,
  // damit Vorlage + allgemeine Einstellungen unabhaengig von Uhrzeit-
  // Differenzen zwischen Geraeten zuverlaessig ankommen.
  $stmt = db()->query(
    "SELECT skey AS `key`, svalue AS `value`, updated_at
       FROM begehung_settings
      ORDER BY updated_at ASC"
  );
  return $stmt->fetchAll();
}

// Normalisiert ISO-Datum (vom Client) zu MySQL-Format "Y-m-d H:i:s"
function pull_portal_feedback($since) {
  try {
    // v1.7: Termin-Spalten absichern (falls Portal noch nie benutzt wurde)
    $cols = [
      "vorab_date VARCHAR(10) NULL","vorab_time VARCHAR(5) NULL","vorab_note VARCHAR(500) NULL",
      "exec_date VARCHAR(10) NULL","exec_time VARCHAR(5) NULL","exec_note VARCHAR(500) NULL",
    ];
    foreach ($cols as $def) { try { db()->exec("ALTER TABLE begehung_portal_feedback ADD COLUMN $def"); } catch (Exception $e) {} }
    // Alles seit 1970 zurueckgeben wenn kein gueltiges Datum
    if (empty($since)) $since = '1970-01-01 00:00:00';
    $stmt = db()->prepare(
      "SELECT id, inspection_id, defect_id, contractor_name, status, message,
              photo_path, vorab_date, vorab_time, vorab_note, exec_date, exec_time, exec_note,
              created_at, updated_at, deleted
       FROM begehung_portal_feedback
       ORDER BY updated_at ASC"
    );
    $stmt->execute();
    return $stmt->fetchAll();
  } catch (Exception $e) { return []; }
}

function mysql_datetime($iso) {
  if (empty($iso)) return date('Y-m-d H:i:s');
  // ISO 8601 mit T und Z parsen
  $iso = str_replace(['T', 'Z'], [' ', ''], $iso);
  // Millisekunden abschneiden
  $iso = preg_replace('/\.\d+/', '', $iso);
  $ts = strtotime($iso);
  return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
}

// UPSERT von Hausbegehungen
function upsert_building_inspections($rows, $deviceId) {
  $cnt = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
  if (!is_array($rows)) return $cnt;
  $sql = "INSERT INTO begehung_building_inspections
    (id, property_id, date_inspection, inspector, summary, data, created_by,
     created_at, updated_at, deleted, device_id)
    VALUES
    (:id, :propId, :date, :inspector, :summary, :data, :createdBy,
     :created, :updated, :deleted, :device)
    ON DUPLICATE KEY UPDATE
      property_id      = IF(VALUES(updated_at) > updated_at, VALUES(property_id), property_id),
      date_inspection  = IF(VALUES(updated_at) > updated_at, VALUES(date_inspection), date_inspection),
      inspector        = IF(VALUES(updated_at) > updated_at, VALUES(inspector), inspector),
      summary          = IF(VALUES(updated_at) > updated_at, VALUES(summary), summary),
      data             = IF(VALUES(updated_at) > updated_at, VALUES(data), data),
      created_by       = IF(VALUES(updated_at) > updated_at, VALUES(created_by), created_by),
      deleted          = IF(VALUES(updated_at) > updated_at, VALUES(deleted), deleted),
      updated_at       = GREATEST(VALUES(updated_at), updated_at),
      device_id        = IF(VALUES(updated_at) > updated_at, VALUES(device_id), device_id)";
  $stmt = db()->prepare($sql);
  $pgb = purged_ids('buildingInspection');
  foreach ($rows as $r) {
    if (empty($r['id']) || empty($r['property_id'] ?? $r['propertyId'])) {
      $cnt['skipped']++;
      continue;
    }
    if (isset($pgb[$r['id']])) { $cnt['skipped']++; continue; }
    $data = $r['data'] ?? null;
    if (is_array($data)) $data = json_encode($data, JSON_UNESCAPED_UNICODE);
    $params = [
      ':id'        => $r['id'],
      ':propId'    => $r['property_id'] ?? $r['propertyId'],
      ':date'      => !empty($r['date_inspection'] ?? $r['date'])
                       ? substr($r['date_inspection'] ?? $r['date'], 0, 10) : null,
      ':inspector' => (string)($r['inspector'] ?? ''),
      ':summary'   => (string)($r['summary'] ?? ''),
      ':data'      => $data ?: '{}',
      ':createdBy' => $r['created_by'] ?? $r['createdBy'] ?? null,
      ':created'   => mysql_datetime($r['created_at'] ?? $r['createdAt'] ?? null),
      ':updated'   => mysql_datetime($r['updated_at'] ?? $r['updatedAt'] ?? null),
      ':deleted'   => !empty($r['deleted']) ? 1 : 0,
      ':device'    => $deviceId,
    ];
    $stmt->execute($params);
    $cnt[$stmt->rowCount() === 1 ? 'inserted' : 'updated']++;
  }
  return $cnt;
}

// UPSERT von Wohnungsbegehungen
function upsert_apartment_inspections($rows, $deviceId) {
  $cnt = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
  if (!is_array($rows)) return $cnt;
  $sql = "INSERT INTO begehung_apartment_inspections
    (id, property_id, apartment_id, date_inspection, inspector, tenant_snapshot,
     not_met, data, created_by, created_at, updated_at, deleted, device_id)
    VALUES
    (:id, :propId, :aptId, :date, :inspector, :tenant,
     :notMet, :data, :createdBy, :created, :updated, :deleted, :device)
    ON DUPLICATE KEY UPDATE
      property_id      = IF(VALUES(updated_at) > updated_at, VALUES(property_id), property_id),
      apartment_id     = IF(VALUES(updated_at) > updated_at, VALUES(apartment_id), apartment_id),
      date_inspection  = IF(VALUES(updated_at) > updated_at, VALUES(date_inspection), date_inspection),
      inspector        = IF(VALUES(updated_at) > updated_at, VALUES(inspector), inspector),
      tenant_snapshot  = IF(VALUES(updated_at) > updated_at, VALUES(tenant_snapshot), tenant_snapshot),
      not_met          = IF(VALUES(updated_at) > updated_at, VALUES(not_met), not_met),
      data             = IF(VALUES(updated_at) > updated_at, VALUES(data), data),
      created_by       = IF(VALUES(updated_at) > updated_at, VALUES(created_by), created_by),
      deleted          = IF(VALUES(updated_at) > updated_at, VALUES(deleted), deleted),
      updated_at       = GREATEST(VALUES(updated_at), updated_at),
      device_id        = IF(VALUES(updated_at) > updated_at, VALUES(device_id), device_id)";
  $stmt = db()->prepare($sql);
  $pga = purged_ids('apartmentInspection');
  foreach ($rows as $r) {
    if (empty($r['id']) ||
        empty($r['property_id'] ?? $r['propertyId']) ||
        empty($r['apartment_id'] ?? $r['apartmentId'])) {
      $cnt['skipped']++;
      continue;
    }
    if (isset($pga[$r['id']])) { $cnt['skipped']++; continue; }
    $data = $r['data'] ?? null;
    if (is_array($data)) $data = json_encode($data, JSON_UNESCAPED_UNICODE);
    $params = [
      ':id'        => $r['id'],
      ':propId'    => $r['property_id'] ?? $r['propertyId'],
      ':aptId'     => $r['apartment_id'] ?? $r['apartmentId'],
      ':date'      => !empty($r['date_inspection'] ?? $r['date'])
                       ? substr($r['date_inspection'] ?? $r['date'], 0, 10) : null,
      ':inspector' => (string)($r['inspector'] ?? ''),
      ':tenant'    => (string)($r['tenant_snapshot'] ?? $r['tenantSnapshot'] ?? ''),
      ':notMet'    => !empty($r['not_met'] ?? $r['notMet']) ? 1 : 0,
      ':data'      => $data ?: '{}',
      ':createdBy' => $r['created_by'] ?? $r['createdBy'] ?? null,
      ':created'   => mysql_datetime($r['created_at'] ?? $r['createdAt'] ?? null),
      ':updated'   => mysql_datetime($r['updated_at'] ?? $r['updatedAt'] ?? null),
      ':deleted'   => !empty($r['deleted']) ? 1 : 0,
      ':device'    => $deviceId,
    ];
    $stmt->execute($params);
    $cnt[$stmt->rowCount() === 1 ? 'inserted' : 'updated']++;
  }
  return $cnt;
}

// UPSERT von Übergaben/Abnahmen (v8.0)
function upsert_handovers($rows, $deviceId) {
  $cnt = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
  if (!is_array($rows)) return $cnt;

  // Tabelle ggf. anlegen (Auto-Migration)
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS begehung_handovers (
      id              VARCHAR(64) NOT NULL PRIMARY KEY,
      property_id     VARCHAR(64),
      apartment_id    VARCHAR(64),
      type            VARCHAR(20)  DEFAULT 'auszug',
      date_handover   DATE,
      inspector       VARCHAR(255) DEFAULT '',
      tenant_snapshot VARCHAR(255) DEFAULT '',
      new_tenant      VARCHAR(255) DEFAULT '',
      data            JSON,
      created_by      VARCHAR(64),
      created_at      DATETIME,
      updated_at      DATETIME,
      deleted         TINYINT(1) DEFAULT 0,
      device_id       VARCHAR(64),
      INDEX idx_ho_prop    (property_id),
      INDEX idx_ho_apt     (apartment_id),
      INDEX idx_ho_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Exception $e) { /* bereits vorhanden */ }

  $sql = "INSERT INTO begehung_handovers
    (id, property_id, apartment_id, type, date_handover, inspector,
     tenant_snapshot, new_tenant, data, created_by,
     created_at, updated_at, deleted, device_id)
    VALUES
    (:id, :propId, :aptId, :type, :date, :inspector,
     :tenant, :newTenant, :data, :createdBy,
     :created, :updated, :deleted, :device)
    ON DUPLICATE KEY UPDATE
      property_id      = IF(VALUES(updated_at) > updated_at, VALUES(property_id), property_id),
      apartment_id     = IF(VALUES(updated_at) > updated_at, VALUES(apartment_id), apartment_id),
      type             = IF(VALUES(updated_at) > updated_at, VALUES(type), type),
      date_handover    = IF(VALUES(updated_at) > updated_at, VALUES(date_handover), date_handover),
      inspector        = IF(VALUES(updated_at) > updated_at, VALUES(inspector), inspector),
      tenant_snapshot  = IF(VALUES(updated_at) > updated_at, VALUES(tenant_snapshot), tenant_snapshot),
      new_tenant       = IF(VALUES(updated_at) > updated_at, VALUES(new_tenant), new_tenant),
      data             = IF(VALUES(updated_at) > updated_at, VALUES(data), data),
      created_by       = IF(VALUES(updated_at) > updated_at, VALUES(created_by), created_by),
      deleted          = IF(VALUES(updated_at) > updated_at, VALUES(deleted), deleted),
      updated_at       = GREATEST(VALUES(updated_at), updated_at),
      device_id        = IF(VALUES(updated_at) > updated_at, VALUES(device_id), device_id)";
  $stmt = db()->prepare($sql);
  $pgh = purged_ids('handover');
  foreach ($rows as $r) {
    if (empty($r['id']) ||
        empty($r['property_id'] ?? $r['propertyId']) ||
        empty($r['apartment_id'] ?? $r['apartmentId'])) {
      $cnt['skipped']++;
      continue;
    }
    if (isset($pgh[$r['id']])) { $cnt['skipped']++; continue; }
    $data = $r['data'] ?? null;
    if (is_array($data)) $data = json_encode($data, JSON_UNESCAPED_UNICODE);
    $params = [
      ':id'        => $r['id'],
      ':propId'    => $r['property_id'] ?? $r['propertyId'],
      ':aptId'     => $r['apartment_id'] ?? $r['apartmentId'],
      ':type'      => (string)($r['type'] ?? 'auszug'),
      ':date'      => !empty($r['date_handover'] ?? $r['date'])
                       ? substr($r['date_handover'] ?? $r['date'], 0, 10) : null,
      ':inspector' => (string)($r['inspector'] ?? ''),
      ':tenant'    => (string)($r['tenant_snapshot'] ?? $r['tenantSnapshot'] ?? ''),
      ':newTenant' => (string)($r['new_tenant'] ?? $r['newTenant'] ?? ''),
      ':data'      => $data ?: '{}',
      ':createdBy' => $r['created_by'] ?? $r['createdBy'] ?? null,
      ':created'   => mysql_datetime($r['created_at'] ?? $r['createdAt'] ?? null),
      ':updated'   => mysql_datetime($r['updated_at'] ?? $r['updatedAt'] ?? null),
      ':deleted'   => !empty($r['deleted']) ? 1 : 0,
      ':device'    => $deviceId,
    ];
    $stmt->execute($params);
    $cnt[$stmt->rowCount() === 1 ? 'inserted' : 'updated']++;
  }
  return $cnt;
}

// Pull Übergaben (komplett, analog zu Begehungen)
function pull_handovers_full() {
  try {
    $rows = db()->query("SELECT * FROM begehung_handovers ORDER BY updated_at ASC")->fetchAll();
    foreach ($rows as &$r) {
      $r['data'] = $r['data'] ? (json_decode($r['data'], true) ?: []) : [];
    }
    unset($r);
    return $rows;
  } catch (Exception $e) {
    return [];
  }
}

// UPSERT von Reparatur-Tickets (v9.0)
function upsert_tickets($rows, $deviceId) {
  $cnt = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
  if (!is_array($rows)) return $cnt;

  try {
    db()->exec("CREATE TABLE IF NOT EXISTS begehung_tickets (
      id              VARCHAR(64) NOT NULL PRIMARY KEY,
      ticket_nr       VARCHAR(32),
      property_id     VARCHAR(64),
      apartment_id    VARCHAR(64),
      category        VARCHAR(32)  DEFAULT 'sonstige',
      priority        VARCHAR(20)  DEFAULT 'normal',
      subject         VARCHAR(500) DEFAULT '',
      status          VARCHAR(32)  DEFAULT 'open',
      data            JSON,
      created_by      VARCHAR(64),
      created_at      DATETIME,
      updated_at      DATETIME,
      deleted         TINYINT(1) DEFAULT 0,
      device_id       VARCHAR(64),
      INDEX idx_tk_prop    (property_id),
      INDEX idx_tk_nr      (ticket_nr),
      INDEX idx_tk_status  (status),
      INDEX idx_tk_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Exception $e) { /* bereits vorhanden */ }

  $sql = "INSERT INTO begehung_tickets
    (id, ticket_nr, property_id, apartment_id, category, priority, subject, status,
     data, created_by, created_at, updated_at, deleted, device_id)
    VALUES
    (:id, :nr, :propId, :aptId, :cat, :prio, :subj, :status,
     :data, :createdBy, :created, :updated, :deleted, :device)
    ON DUPLICATE KEY UPDATE
      ticket_nr        = IF(VALUES(updated_at) > updated_at, VALUES(ticket_nr), ticket_nr),
      property_id      = IF(VALUES(updated_at) > updated_at, VALUES(property_id), property_id),
      apartment_id     = IF(VALUES(updated_at) > updated_at, VALUES(apartment_id), apartment_id),
      category         = IF(VALUES(updated_at) > updated_at, VALUES(category), category),
      priority         = IF(VALUES(updated_at) > updated_at, VALUES(priority), priority),
      subject          = IF(VALUES(updated_at) > updated_at, VALUES(subject), subject),
      status           = IF(VALUES(updated_at) > updated_at, VALUES(status), status),
      data             = IF(VALUES(updated_at) > updated_at, VALUES(data), data),
      created_by       = IF(VALUES(updated_at) > updated_at, VALUES(created_by), created_by),
      deleted          = IF(VALUES(updated_at) > updated_at, VALUES(deleted), deleted),
      updated_at       = GREATEST(VALUES(updated_at), updated_at),
      device_id        = IF(VALUES(updated_at) > updated_at, VALUES(device_id), device_id)";
  $stmt = db()->prepare($sql);
  $ptk = purged_ids('ticket');
  foreach ($rows as $r) {
    if (empty($r['id']) || empty($r['property_id'])) { $cnt['skipped']++; continue; }
    if (isset($ptk[$r['id']])) { $cnt['skipped']++; continue; }
    $data = $r['data'] ?? null;
    if (is_array($data)) $data = json_encode($data, JSON_UNESCAPED_UNICODE);
    $params = [
      ':id'        => $r['id'],
      ':nr'        => $r['ticket_nr'] ?? '',
      ':propId'    => $r['property_id'],
      ':aptId'     => $r['apartment_id'] ?? null,
      ':cat'       => (string)($r['category'] ?? 'sonstige'),
      ':prio'      => (string)($r['priority'] ?? 'normal'),
      ':subj'      => (string)($r['subject'] ?? ''),
      ':status'    => (string)($r['status'] ?? 'open'),
      ':data'      => $data ?: '{}',
      ':createdBy' => $r['created_by'] ?? null,
      ':created'   => mysql_datetime($r['created_at'] ?? null),
      ':updated'   => mysql_datetime($r['updated_at'] ?? null),
      ':deleted'   => !empty($r['deleted']) ? 1 : 0,
      ':device'    => $deviceId,
    ];
    $stmt->execute($params);
    $cnt[$stmt->rowCount() === 1 ? 'inserted' : 'updated']++;
  }
  return $cnt;
}

// Pull Tickets (komplett)
function pull_tickets_full() {
  try {
    $rows = db()->query("SELECT * FROM begehung_tickets ORDER BY updated_at ASC")->fetchAll();
    foreach ($rows as &$r) {
      $r['data'] = $r['data'] ? (json_decode($r['data'], true) ?: []) : [];
    }
    unset($r);
    return $rows;
  } catch (Exception $e) {
    return [];
  }
}

// UPSERT von Zaehlern (v9.37)
function upsert_meters($rows, $deviceId) {
  $cnt = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
  if (!is_array($rows)) return $cnt;

  try {
    db()->exec("CREATE TABLE IF NOT EXISTS begehung_meters (
      id              VARCHAR(64) NOT NULL PRIMARY KEY,
      property_id     VARCHAR(64),
      apartment_id    VARCHAR(64),
      type            VARCHAR(32)  DEFAULT 'allgemein',
      meter_number    VARCHAR(100) DEFAULT '',
      location        VARCHAR(200) DEFAULT '',
      data            JSON,
      created_at      DATETIME,
      updated_at      DATETIME,
      deleted         TINYINT(1) DEFAULT 0,
      device_id       VARCHAR(64),
      INDEX idx_mt_prop    (property_id),
      INDEX idx_mt_number  (meter_number),
      INDEX idx_mt_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Exception $e) { /* bereits vorhanden */ }

  $sql = "INSERT INTO begehung_meters
    (id, property_id, apartment_id, type, meter_number, location,
     data, created_at, updated_at, deleted, device_id)
    VALUES
    (:id, :propId, :aptId, :type, :number, :location,
     :data, :created, :updated, :deleted, :device)
    ON DUPLICATE KEY UPDATE
      property_id      = IF(VALUES(updated_at) > updated_at, VALUES(property_id), property_id),
      apartment_id     = IF(VALUES(updated_at) > updated_at, VALUES(apartment_id), apartment_id),
      type             = IF(VALUES(updated_at) > updated_at, VALUES(type), type),
      meter_number     = IF(VALUES(updated_at) > updated_at, VALUES(meter_number), meter_number),
      location         = IF(VALUES(updated_at) > updated_at, VALUES(location), location),
      data             = IF(VALUES(updated_at) > updated_at, VALUES(data), data),
      deleted          = IF(VALUES(updated_at) > updated_at, VALUES(deleted), deleted),
      updated_at       = GREATEST(VALUES(updated_at), updated_at),
      device_id        = IF(VALUES(updated_at) > updated_at, VALUES(device_id), device_id)";
  $stmt = db()->prepare($sql);
  $pmr = purged_ids('meter');
  foreach ($rows as $r) {
    if (empty($r['id'])) { $cnt['skipped']++; continue; }
    if (isset($pmr[$r['id']])) { $cnt['skipped']++; continue; }
    $data = $r['data'] ?? null;
    if (is_array($data)) $data = json_encode($data, JSON_UNESCAPED_UNICODE);
    $params = [
      ':id'        => $r['id'],
      ':propId'    => $r['property_id'] ?? '',
      ':aptId'     => $r['apartment_id'] ?? null,
      ':type'      => (string)($r['type'] ?? 'allgemein'),
      ':number'    => (string)($r['meter_number'] ?? ''),
      ':location'  => (string)($r['location'] ?? ''),
      ':data'      => $data ?: '{}',
      ':created'   => mysql_datetime($r['created_at'] ?? null),
      ':updated'   => mysql_datetime($r['updated_at'] ?? null),
      ':deleted'   => !empty($r['deleted']) ? 1 : 0,
      ':device'    => $deviceId,
    ];
    $stmt->execute($params);
    $cnt[$stmt->rowCount() === 1 ? 'inserted' : 'updated']++;
  }
  return $cnt;
}

// Pull Zaehler (komplett)
function pull_meters_full() {
  try {
    $rows = db()->query("SELECT * FROM begehung_meters ORDER BY updated_at ASC")->fetchAll();
    foreach ($rows as &$r) {
      $r['data'] = $r['data'] ? (json_decode($r['data'], true) ?: []) : [];
    }
    unset($r);
    return $rows;
  } catch (Exception $e) {
    return [];
  }
}

// Liefert Metadaten aller PDF-Protokolle (ohne extracted_json, ist gross)
function pull_pdfs_meta() {
  try {
    $rows = db()->query("
      SELECT id, apartment_id, filename, file_path, size_bytes, created_at, updated_at, deleted
        FROM begehung_pdfs
       ORDER BY created_at DESC
    ")->fetchAll();
    return $rows;
  } catch (Exception $e) {
    // Tabelle existiert noch nicht auf diesem Server
    return [];
  }
}

// Speichert extracted_json fuer ein PDF (wird nach KI-Auswertung im Client aufgerufen)
// POST /api/sync.php mit action=save_pdf_extract
// Wird am Anfang von sync.php abgefangen (analog zu purge)

// Freshness-Check: prueft ob Inspektionen auf dem Server neuer sind als lokal.
// Input:  items: [{type: 'apartmentInspection'|'buildingInspection'|'handover'|'ticket', id: '...', updated_at: '...'}]
// Output: stale: [{type, id, server_updated_at, local_updated_at}]  (nur die veralteten)
function handle_check_freshness($in) {
  $items = $in['items'] ?? [];
  if (!is_array($items) || count($items) === 0) {
    json_response(['ok' => true, 'stale' => []]);
  }
  $tableOf = [
    'buildingInspection'  => 'begehung_building_inspections',
    'apartmentInspection' => 'begehung_apartment_inspections',
    'handover'            => 'begehung_handovers',
    'ticket'              => 'begehung_tickets',
    'meter'               => 'begehung_meters',
  ];
  $stale = [];
  foreach ($items as $item) {
    $type = trim((string)($item['type'] ?? ''));
    $id   = trim((string)($item['id'] ?? ''));
    $localUpdated = trim((string)($item['updated_at'] ?? ''));
    if (!isset($tableOf[$type]) || $id === '' || $localUpdated === '') continue;
    // ISO-Format (2026-08-21T08:02:59.782Z) → Unix-Timestamp fuer sauberen Vergleich
    $localTs = strtotime($localUpdated);
    if ($localTs === false) continue;

    $stmt = db()->prepare(
      "SELECT updated_at FROM `{$tableOf[$type]}` WHERE id = ? AND deleted = 0 LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) continue; // existiert nicht auf Server -> kein Konflikt

    $serverTs = strtotime($row['updated_at'] . ' UTC');
    // Toleranz von 2 Sekunden: Millisekunden-Präzision des Clients ist nicht in DB
    if ($serverTs > $localTs + 2) {
      $stale[] = [
        'type'              => $type,
        'id'                => $id,
        'server_updated_at' => $row['updated_at'],
        'local_updated_at'  => $localUpdated,
      ];
    }
  }
  json_response(['ok' => true, 'stale' => $stale]);
}

// ==================== Auto-Migration ====================
function run_migrations() {
  $migrations = [
    // Apartments: Vermerk-Felder + Typ + History
    "ALTER TABLE begehung_apartments ADD COLUMN type VARCHAR(20) DEFAULT 'wohnung'",
    "ALTER TABLE begehung_apartments ADD COLUMN usage_type VARCHAR(100) DEFAULT ''",
    "ALTER TABLE begehung_apartments ADD COLUMN inspection_skip VARCHAR(32) DEFAULT ''",
    "ALTER TABLE begehung_apartments ADD COLUMN inspection_skip_note VARCHAR(500) DEFAULT ''",
    "ALTER TABLE begehung_apartments ADD COLUMN inspection_skip_until DATE DEFAULT NULL",
    "ALTER TABLE begehung_apartments ADD COLUMN tenant_history JSON DEFAULT NULL",
    // v9.28: Rechnungs-/Leistungsempfaenger pro Liegenschaft
    "ALTER TABLE begehung_properties ADD COLUMN billing_recipient TEXT DEFAULT NULL",
    "ALTER TABLE begehung_properties ADD COLUMN service_recipient TEXT DEFAULT NULL",
    // v9.70: User-Kontaktdaten (Position, E-Mail, Festnetz, Mobil)
    "ALTER TABLE begehung_users ADD COLUMN email    VARCHAR(255) NOT NULL DEFAULT ''",
    "ALTER TABLE begehung_users ADD COLUMN phone    VARCHAR(64)  NOT NULL DEFAULT ''",
    "ALTER TABLE begehung_users ADD COLUMN mobile   VARCHAR(64)  NOT NULL DEFAULT ''",
    "ALTER TABLE begehung_users ADD COLUMN position VARCHAR(128) NOT NULL DEFAULT ''",
    // v9.67: role-Spalte muss auch 'super-user' aufnehmen können. Bei ENUM/VARCHAR(20)
    // reicht das, aber wenn eine strikte ENUM('admin','user') definiert ist, muss sie erweitert werden.
    // MODIFY ist idempotent — falls die Spalte bereits VARCHAR ist, tut es nichts kaputt.
    "ALTER TABLE begehung_users MODIFY COLUMN role VARCHAR(32) NOT NULL DEFAULT 'user'",
  ];
  foreach ($migrations as $sql) {
    try { db()->exec($sql); } catch (Exception $e) { /* Spalte existiert bereits */ }
  }
}

// ============================================================
// v10.24: Benachrichtigungen (Termine, Zuweisungen, Kurznachrichten)
// User-bezogen. Jede Zeile ist an genau einen Empfaenger adressiert
// (to_user_id = User-ID) oder an alle (to_user_id = 'all').
// Absender darf jeder eingeloggte User sein.
// ============================================================
function upsert_notifications($rows, $deviceId) {
  $cnt = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
  if (!is_array($rows)) return $cnt;

  try {
    db()->exec("CREATE TABLE IF NOT EXISTS begehung_notifications (
      id             VARCHAR(64) NOT NULL PRIMARY KEY,
      to_user_id     VARCHAR(64) NOT NULL,
      from_user_id   VARCHAR(64) DEFAULT NULL,
      from_user_name VARCHAR(200) DEFAULT '',
      kind           VARCHAR(32)  DEFAULT 'info',
      subject        VARCHAR(255) DEFAULT '',
      message        TEXT,
      ticket_id      VARCHAR(64) DEFAULT NULL,
      due_at         VARCHAR(32) DEFAULT NULL,
      read_at        VARCHAR(32) DEFAULT NULL,
      created_at     DATETIME,
      updated_at     DATETIME,
      deleted        TINYINT(1) DEFAULT 0,
      device_id      VARCHAR(64),
      INDEX idx_nt_to      (to_user_id),
      INDEX idx_nt_from    (from_user_id),
      INDEX idx_nt_ticket  (ticket_id),
      INDEX idx_nt_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Exception $e) { /* bereits vorhanden */ }

  $sql = "INSERT INTO begehung_notifications
    (id, to_user_id, from_user_id, from_user_name, kind, subject, message,
     ticket_id, due_at, read_at, created_at, updated_at, deleted, device_id)
    VALUES
    (:id, :toU, :frU, :frN, :kind, :subj, :msg,
     :tk, :due, :rd, :created, :updated, :deleted, :device)
    ON DUPLICATE KEY UPDATE
      to_user_id     = IF(VALUES(updated_at) > updated_at, VALUES(to_user_id), to_user_id),
      from_user_id   = IF(VALUES(updated_at) > updated_at, VALUES(from_user_id), from_user_id),
      from_user_name = IF(VALUES(updated_at) > updated_at, VALUES(from_user_name), from_user_name),
      kind           = IF(VALUES(updated_at) > updated_at, VALUES(kind), kind),
      subject        = IF(VALUES(updated_at) > updated_at, VALUES(subject), subject),
      message        = IF(VALUES(updated_at) > updated_at, VALUES(message), message),
      ticket_id      = IF(VALUES(updated_at) > updated_at, VALUES(ticket_id), ticket_id),
      due_at         = IF(VALUES(updated_at) > updated_at, VALUES(due_at), due_at),
      read_at        = IF(VALUES(updated_at) > updated_at, VALUES(read_at), read_at),
      deleted        = IF(VALUES(updated_at) > updated_at, VALUES(deleted), deleted),
      updated_at     = GREATEST(VALUES(updated_at), updated_at),
      device_id      = IF(VALUES(updated_at) > updated_at, VALUES(device_id), device_id)";
  $stmt = db()->prepare($sql);

  foreach ($rows as $r) {
    if (empty($r['id']) || empty($r['to_user_id'])) { $cnt['skipped']++; continue; }
    $params = [
      ':id'      => (string)$r['id'],
      ':toU'     => (string)$r['to_user_id'],
      ':frU'     => isset($r['from_user_id']) && $r['from_user_id'] !== '' ? (string)$r['from_user_id'] : null,
      ':frN'     => (string)($r['from_user_name'] ?? ''),
      ':kind'    => (string)($r['kind'] ?? 'info'),
      ':subj'    => (string)($r['subject'] ?? ''),
      ':msg'     => (string)($r['message'] ?? ''),
      ':tk'      => isset($r['ticket_id']) && $r['ticket_id'] !== '' ? (string)$r['ticket_id'] : null,
      ':due'     => isset($r['due_at']) && $r['due_at'] !== '' ? (string)$r['due_at'] : null,
      ':rd'      => isset($r['read_at']) && $r['read_at'] !== '' ? (string)$r['read_at'] : null,
      ':created' => mysql_datetime($r['created_at'] ?? null),
      ':updated' => mysql_datetime($r['updated_at'] ?? null),
      ':deleted' => !empty($r['deleted']) ? 1 : 0,
      ':device'  => $deviceId,
    ];
    $stmt->execute($params);
    $cnt[$stmt->rowCount() === 1 ? 'inserted' : 'updated']++;
  }
  return $cnt;
}

// Liefert alle Notifications, die diesen User etwas angehen:
// - direkt an ihn adressierte (to_user_id = $userId)
// - Broadcasts (to_user_id = 'all')
// - von ihm selbst versendete (from_user_id = $userId) -> "Gesendet"-Ordner
// Ohne User-ID (z.B. Recovery/Boot ohne Login) wird ein leeres Array geliefert.
function pull_notifications_for_user($userId) {
  $userId = trim((string)$userId);
  if ($userId === '') return [];
  try {
    // Tabelle sicher vorhanden
    db()->exec("CREATE TABLE IF NOT EXISTS begehung_notifications (
      id             VARCHAR(64) NOT NULL PRIMARY KEY,
      to_user_id     VARCHAR(64) NOT NULL,
      from_user_id   VARCHAR(64) DEFAULT NULL,
      from_user_name VARCHAR(200) DEFAULT '',
      kind           VARCHAR(32)  DEFAULT 'info',
      subject        VARCHAR(255) DEFAULT '',
      message        TEXT,
      ticket_id      VARCHAR(64) DEFAULT NULL,
      due_at         VARCHAR(32) DEFAULT NULL,
      read_at        VARCHAR(32) DEFAULT NULL,
      created_at     DATETIME,
      updated_at     DATETIME,
      deleted        TINYINT(1) DEFAULT 0,
      device_id      VARCHAR(64),
      INDEX idx_nt_to      (to_user_id),
      INDEX idx_nt_from    (from_user_id),
      INDEX idx_nt_ticket  (ticket_id),
      INDEX idx_nt_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
  } catch (Exception $e) {}
  try {
    $stmt = db()->prepare("SELECT * FROM begehung_notifications
      WHERE to_user_id = :u OR to_user_id = 'all' OR from_user_id = :u
      ORDER BY updated_at ASC");
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchAll();
  } catch (Exception $e) {
    return [];
  }
}
