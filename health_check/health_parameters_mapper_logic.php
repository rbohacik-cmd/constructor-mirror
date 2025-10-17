<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$pdoMy = db();
$serverKey = 'ts'; // MSSQL server key

$TBL_PARAMS = 'S4_Agenda_PCB.dbo.Artikly_ParametrArtiklu';
$META_TABLE = 'hc_param_group_meta_guid';

$action = (string)($_GET['action'] ?? '');

function out($ok, $data=null, $error=null) {
  echo json_encode(['ok'=>$ok, 'data'=>$data, 'error'=>$error], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  // Groups list (GUIDs) + counts + display_name (MySQL)
  if ($action === 'groups_index') {
    $q = trim((string)($_GET['q'] ?? ''));

    if ($q === '') {
      $rows = qall("
        SELECT CAST(Group_ID AS NVARCHAR(36)) AS Group_ID, COUNT(*) AS cnt, MIN(Nazev) AS sample
        FROM {$TBL_PARAMS}
        WHERE Group_ID IS NOT NULL
        GROUP BY Group_ID
        ORDER BY Group_ID ASC
      ", [], null, $serverKey);
    } else {
      // GUID search vs name search
      $isGuid = (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $q);
      if ($isGuid) {
        $rows = qall("
          SELECT CAST(Group_ID AS NVARCHAR(36)) AS Group_ID, COUNT(*) AS cnt, MIN(Nazev) AS sample
          FROM {$TBL_PARAMS}
          WHERE Group_ID = CAST(? AS UNIQUEIDENTIFIER)
          GROUP BY Group_ID
          ORDER BY Group_ID ASC
        ", [$q], null, $serverKey);
      } else {
        $rows = qall("
          SELECT CAST(Group_ID AS NVARCHAR(36)) AS Group_ID, COUNT(*) AS cnt, MIN(Nazev) AS sample
          FROM {$TBL_PARAMS}
          WHERE Nazev LIKE ?
          GROUP BY Group_ID
          ORDER BY Group_ID ASC
        ", ['%'.$q.'%'], null, $serverKey);
      }
    }

    // MySQL meta names
    $metaRows = qall("SELECT mssql_param_group_id, display_name FROM {$META_TABLE}");
    $meta = [];
    foreach ($metaRows as $m) $meta[(string)$m['mssql_param_group_id']] = (string)$m['display_name'];

    $data = array_map(function($r) use ($meta) {
      $gid = (string)$r['Group_ID'];
      return [
        'group_id'     => $gid,
        'params_count' => (int)$r['cnt'],
        'sample'       => (string)($r['sample'] ?? ''),
        'display_name' => $meta[$gid] ?? '',
      ];
    }, $rows);

    out(true, $data);
  }

  // Save/update display name (GUID key)
  if ($action === 'save_meta') {
    $raw = file_get_contents('php://input') ?: '';
    $in  = json_decode($raw, true) ?: [];
    $gid = trim((string)($in['group_id'] ?? ''));
    $name = trim((string)($in['display_name'] ?? ''));
    if ($gid === '') out(false, null, 'group_id required');
    if ($name === '') out(false, null, 'display_name required');

    qexec("
      INSERT INTO {$META_TABLE} (mssql_param_group_id, display_name)
      VALUES (?,?)
      ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), updated_at=NOW()
    ", [$gid, $name]);

    out(true, ['saved'=>true]);
  }

  // Parameters in a group — show ID (GUID), Kod, Nazev
  if ($action === 'group_params') {
    $gid = trim((string)($_GET['group_id'] ?? ''));
    if ($gid === '') out(false, null, 'group_id required');

    $rows = qall("
      SELECT
        CAST(ID AS NVARCHAR(36)) AS ID,
        CAST(Kod AS NVARCHAR(50)) AS Kod,
        CAST(Nazev AS NVARCHAR(100)) AS Nazev
      FROM {$TBL_PARAMS}
      WHERE Group_ID = CAST(? AS UNIQUEIDENTIFIER)
      ORDER BY Nazev ASC
    ", [$gid], null, $serverKey);

    $data = array_map(fn($r)=>[
      'id'   => (string)$r['ID'],
      'kod'  => isset($r['Kod']) ? (string)$r['Kod'] : '',
      'nazev'=> isset($r['Nazev']) ? (string)$r['Nazev'] : '',
    ], $rows);

    out(true, $data);
  }

  out(false, null, 'Unknown action');
} catch (Throwable $e) {
  out(false, null, $e->getMessage());
}
