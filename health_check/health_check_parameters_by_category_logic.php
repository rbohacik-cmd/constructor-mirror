<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$pdoMy     = db();
$serverKey = 'ts'; // MSSQL

$TBL_PARAMS  = 'S4_Agenda_PCB.dbo.Artikly_ParametrArtiklu';
$TBL_ARTIKLY = 'S4_Agenda_PCB.dbo.Artikly_Artikl';
$TBL_A_PARAM = 'S4_Agenda_PCB.dbo.Artikly_ArtiklParametr'; // Artikl_ID (GUID), Parametr_ID (GUID)

// MySQL tables (GUID-safe)
$MAP_TABLE  = 'hc_category_param_group_guid';   // mssql_category_id (GUID), mssql_param_group_id (GUID)
$META_TABLE = 'hc_param_group_meta_guid';       // mssql_param_group_id (GUID) -> display_name

$action = (string)($_GET['action'] ?? '');

function out($ok, $data=null, $error=null) {
  echo json_encode(['ok'=>$ok, 'data'=>$data, 'error'=>$error], JSON_UNESCAPED_UNICODE);
  exit;
}

$GUID_RE = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

try {
  // ---- List distinct Group_IDs (GUID), include mapped display names from MySQL ----
  if ($action === 'groups_list') {
    $rows = qall("
      SELECT
        CAST(Group_ID AS NVARCHAR(36)) AS Group_ID,
        COUNT(*) AS cnt,
        MIN(Nazev) AS sample
      FROM {$TBL_PARAMS}
      WHERE Group_ID IS NOT NULL
      GROUP BY Group_ID
      ORDER BY Group_ID ASC
    ", [], null, $serverKey);

    // load meta names
    $metaRows = qall("SELECT mssql_param_group_id, display_name FROM {$META_TABLE}");
    $nameByG  = [];
    foreach ($metaRows as $m) $nameByG[(string)$m['mssql_param_group_id']] = (string)$m['display_name'];

    $data = array_map(function($r) use ($nameByG) {
      $gid = (string)$r['Group_ID'];
      return [
        'group_id'     => $gid,
        'display_name' => $nameByG[$gid] ?? '',
        'params_count' => (int)$r['cnt'],
        'sample'       => (string)($r['sample'] ?? ''),
      ];
    }, $rows);

    out(true, $data);
  }

  // ---- Save mapping: { category_id: GUID string, group_id: GUID|null } ----
  if ($action === 'save') {
    $raw = file_get_contents('php://input') ?: '';
    $in  = json_decode($raw, true) ?: [];
    $cat = trim((string)($in['category_id'] ?? ''));
    $grp = array_key_exists('group_id', $in) && $in['group_id'] !== null ? trim((string)$in['group_id']) : null;

    if ($cat === '') out(false, null, 'category_id required');
    if ($grp !== null && $grp !== '' && !preg_match($GUID_RE, $grp)) {
      out(false, null, 'group_id must be GUID');
    }

    if ($grp !== null && $grp !== '') {
      qexec("
        INSERT INTO {$MAP_TABLE} (mssql_category_id, mssql_param_group_id)
        VALUES (?,?)
        ON DUPLICATE KEY UPDATE mssql_param_group_id=VALUES(mssql_param_group_id), updated_at=NOW()
      ", [$cat, $grp]);
      out(true, ['saved'=>true]);
    } else {
      qexec("DELETE FROM {$MAP_TABLE} WHERE mssql_category_id=?", [$cat]);
      out(true, ['deleted'=>true]);
    }
  }

  // ---- Validate products in category against group's required parameters ----
  if ($action === 'validate') {
    $cat = trim((string)($_GET['category_id'] ?? ''));
    if ($cat === '') out(false, null, 'category_id required');
    if (!preg_match($GUID_RE, $cat)) out(false, null, 'category_id must be GUID');

    $map = qrow("SELECT mssql_param_group_id AS grp FROM {$MAP_TABLE} WHERE mssql_category_id=?", [$cat]);
    if (!$map) out(true, ['group_id'=>null, 'summary'=>['total_products'=>0, 'fully_ok'=>0, 'with_missing'=>0], 'missing'=>[]]);

    $groupGuid = trim((string)$map['grp']);
    if ($groupGuid === '' || !preg_match($GUID_RE, $groupGuid)) {
      out(false, null, 'Mapped group_id is not a valid GUID');
    }

    // Friendly label (optional)
    $meta = qrow("SELECT display_name FROM {$META_TABLE} WHERE mssql_param_group_id=?", [$groupGuid]);
    $groupLabel = ($meta && isset($meta['display_name']) && strlen((string)$meta['display_name'])) ? (string)$meta['display_name'] : $groupGuid;

    // Required parameter IDs (GUID strings)
    $req = qall("
      SELECT CAST(ID AS NVARCHAR(36)) AS Parametr_ID
      FROM {$TBL_PARAMS}
      WHERE Group_ID = CAST(? AS UNIQUEIDENTIFIER)
    ", [$groupGuid], null, $serverKey);
    $requiredParams = array_map(fn($r)=>(string)$r['Parametr_ID'], $req);
    if (!$requiredParams) {
      out(true, ['group_id'=>$groupGuid, 'group_label'=>$groupLabel, 'summary'=>['total_products'=>0, 'fully_ok'=>0, 'with_missing'=>0], 'missing'=>[]]);
    }

    // Products in this category (GUID list)
    $artikly = qall("
      SELECT CAST(ID AS NVARCHAR(36)) AS Artikl_ID
      FROM {$TBL_ARTIKLY}
      WHERE KategorieArtiklu_ID = CAST(? AS UNIQUEIDENTIFIER)
    ", [$cat], null, $serverKey);

    $ids = array_map(fn($r)=> (string)$r['Artikl_ID'], $artikly);
    $total = count($ids);
    if ($total === 0) {
      out(true, ['group_id'=>$groupGuid, 'group_label'=>$groupLabel, 'summary'=>['total_products'=>0, 'fully_ok'=>0, 'with_missing'=>0], 'missing'=>[]]);
    }

    // Filter to valid GUIDs (avoid conversion errors)
    $validIds = array_values(array_filter($ids, fn($s)=> preg_match($GUID_RE, $s)));
    if (!$validIds) {
      out(true, ['group_id'=>$groupGuid, 'group_label'=>$groupLabel, 'summary'=>['total_products'=>$total, 'fully_ok'=>0, 'with_missing'=>$total], 'missing'=>[]]);
    }

    // Load parameters for these articles in chunks
    $have = [];
    $chunk = 300;
    for ($i=0; $i<count($validIds); $i += $chunk) {
      $slice = array_slice($validIds, $i, $chunk);
      $in = implode(',', array_fill(0, count($slice), 'CAST(? AS UNIQUEIDENTIFIER)'));
      $rows = qall("
        SELECT
          CAST(Artikl_ID AS NVARCHAR(36))  AS Artikl_ID,
          CAST(Parametr_ID AS NVARCHAR(36)) AS Parametr_ID
        FROM {$TBL_A_PARAM}
        WHERE Artikl_ID IN ($in)
      ", $slice, null, $serverKey);

      foreach ($rows as $ln) {
        $aid = (string)$ln['Artikl_ID'];
        $pid = (string)$ln['Parametr_ID'];
        $have[$aid][$pid] = true;
      }
    }

    // Compare per article
    $missingList = [];
    $ok = 0;
    foreach ($ids as $aid) {
      $haveSet = array_keys($have[$aid] ?? []);
      $missing = array_diff($requiredParams, $haveSet);
      if (!$missing) $ok++;
      else $missingList[] = ['artikl_id'=>$aid, 'missing_params'=>array_values($missing)];
    }

    out(true, [
      'group_id'    => $groupGuid,
      'group_label' => $groupLabel,
      'summary'     => [
        'total_products' => $total,
        'fully_ok'       => $ok,
        'with_missing'   => $total - $ok
      ],
      'missing'     => $missingList
    ]);
  }

  out(false, null, 'Unknown action');
} catch (Throwable $e) {
  out(false, null, $e->getMessage());
}
