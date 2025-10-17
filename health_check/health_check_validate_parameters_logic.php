<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$serverKey = 'ts';

// Tables
$TBL_CATS     = 'S4_Agenda_PCB.dbo.Artikly_KategorieArtiklu';
$TBL_PARAMS   = 'S4_Agenda_PCB.dbo.Artikly_ParametrArtiklu';
$TBL_ARTIKLY  = 'S4_Agenda_PCB.dbo.Artikly_Artikl';
$TBL_A_HOD    = 'S4_Agenda_PCB.dbo.Artikly_ArtiklHodnotaParametru'; // Parent_ID = Artikl_ID, Parametr_ID = Parameter GUID (FK)

// MySQL metadata
$MAP_TABLE  = 'hc_category_param_group_guid';   // cat GUID -> group GUID
$META_TABLE = 'hc_param_group_meta_guid';       // group GUID -> display_name

$GUID_RE = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

function out($ok, $data=null, $error=null) {
  echo json_encode(['ok'=>$ok, 'data'=>$data, 'error'=>$error], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $action = (string)($_GET['action'] ?? '');

  if ($action === 'validate') {
    $cat = trim((string)($_GET['category_id'] ?? ''));
    if ($cat === '') out(false, null, 'category_id required');
    if (!preg_match($GUID_RE, $cat)) out(false, null, 'category_id must be GUID');

    // Load mapping (MySQL)
    $map = qrow("SELECT mssql_param_group_id AS grp FROM {$MAP_TABLE} WHERE mssql_category_id=?", [$cat]);
    if (!$map) {
      out(true, ['group_id'=>null, 'summary'=>['total_products'=>0, 'fully_ok'=>0, 'with_missing'=>0], 'missing'=>[]]);
    }

    $groupGuid = trim((string)$map['grp']);
    if (!preg_match($GUID_RE, $groupGuid)) out(false, null, 'Mapped group_id is not a valid GUID');

    // Friendly label (MySQL meta)
    $meta = qrow("SELECT display_name FROM {$META_TABLE} WHERE mssql_param_group_id=?", [$groupGuid]);
    $groupLabel = ($meta && !empty($meta['display_name'])) ? (string)$meta['display_name'] : $groupGuid;

    // Required parameters (GUIDs)
    $req = qall("
      SELECT CAST(ID AS NVARCHAR(36)) AS Parametr_ID
      FROM {$TBL_PARAMS}
      WHERE Group_ID = CAST(? AS UNIQUEIDENTIFIER)
    ", [$groupGuid], null, $serverKey);
    $required       = array_values(array_unique(array_map(fn($r)=>(string)$r['Parametr_ID'], $req)));
    $requiredTotal  = count($required);

    if (!$requiredTotal) {
      out(true, [
        'group_id'    => $groupGuid,
        'group_label' => $groupLabel,
        'summary'     => ['total_products'=>0, 'fully_ok'=>0, 'with_missing'=>0],
        'missing'     => []
      ]);
    }

    // Articles in this category (Kod + Nazev)
    $artikly = qall("
      SELECT CAST(ID AS NVARCHAR(36)) AS Artikl_ID,
             Kod,
             Nazev
      FROM {$TBL_ARTIKLY} WITH (NOLOCK)
      WHERE Zkratka20 LIKE N'eshop%'
        AND EXISTS (
          SELECT 1
          FROM STRING_SPLIT(ISNULL(Kategorie, N''), N'|') AS s
          WHERE LTRIM(RTRIM(s.value)) = CAST(? AS NVARCHAR(36))
        )
    ", [$cat], null, $serverKey);

    $info = [];
    foreach ($artikly as $r) {
      $aid = (string)$r['Artikl_ID'];
      $info[$aid] = [
        'kod'   => (string)($r['Kod'] ?? ''),
        'nazev' => (string)($r['Nazev'] ?? '')
      ];
    }
    $ids   = array_keys($info);
    $total = count($ids);

    if ($total === 0) {
      out(true, [
        'group_id'    => $groupGuid,
        'group_label' => $groupLabel,
        'summary'     => ['total_products'=>0, 'fully_ok'=>0, 'with_missing'=>0],
        'missing'     => []
      ]);
    }

    // Filter to valid GUIDs
    $validIds = array_values(array_filter($ids, fn($s)=> preg_match($GUID_RE, $s)));
    if (!$validIds) {
      out(true, [
        'group_id'    => $groupGuid,
        'group_label' => $groupLabel,
        'summary'     => ['total_products'=>$total, 'fully_ok'=>0, 'with_missing'=>$total],
        'missing'     => []
      ]);
    }

    // Present parameters on these articles:
    // IMPORTANT: use Parametr_ID (not ID) for the parameter FK
    $have  = [];
    $chunk = 300;
    for ($i=0; $i<count($validIds); $i += $chunk) {
      $slice = array_slice($validIds, $i, $chunk);
      $in = implode(',', array_fill(0, count($slice), 'CAST(? AS UNIQUEIDENTIFIER)'));
      $rows = qall("
        SELECT
          CAST(Parent_ID    AS NVARCHAR(36)) AS Artikl_ID,
          CAST(Parametr_ID  AS NVARCHAR(36)) AS Parametr_ID
        FROM {$TBL_A_HOD} WITH (NOLOCK)
        WHERE Parent_ID IN ($in)
      ", $slice, null, $serverKey);

      foreach ($rows as $ln) {
        $have[(string)$ln['Artikl_ID']][(string)$ln['Parametr_ID']] = true;
      }
    }

    // Compare (count present vs required)
    $missingList = [];
    $ok = 0;
    foreach ($ids as $aid) {
      $haveSet      = array_keys($have[$aid] ?? []);
      $present      = $haveSet ? count(array_intersect($required, $haveSet)) : 0;
      $missingCount = max(0, $requiredTotal - $present);

      if ($missingCount === 0) {
        $ok++;
      } else {
        $missingList[] = [
          'artikl_id'      => $aid,
          'kod'            => $info[$aid]['kod'] ?? '',
          'nazev'          => $info[$aid]['nazev'] ?? '',
          'missing'        => $missingCount,
          'required_total' => $requiredTotal
        ];
      }
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
