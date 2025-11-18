<?php
declare(strict_types=1);

/**
 * Health — Tabs: EOL | No Stock | Bad EAN
 * Generic version with manufacturer selector.
 *
 * Source of truth for per-manufacturer settings:
 *   /health_system/lib/hs_manufacturers.php
 *
 * Usage:
 *   /health_system/hs_manufacturer_check.php?mfg=lindy
 *   /health_system/hs_manufacturer_check.php?mfg=roline
 */

// PhpSpreadsheet (UTF-8 safe XLSX)
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
// Extra helpers for explicit text cells (EAN / Čiarový kód)
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// Load composer autoload if not already loaded elsewhere
if (!class_exists(Spreadsheet::class)) {
  $autoload = __DIR__ . '/../../lib/PhpSpreadsheet/vendor/autoload.php';
  if (is_file($autoload)) require_once $autoload;
}

require_once __DIR__ . '/../bootstrap_hs.php';               // hs_pdo(), hs_cfg(), q*, debug_sentinel
require_once __DIR__ . '/../lib/hs_manufacturers.php';       // hs_manufacturer_profiles(), hs_mfg()

/* ---------------- Inputs & manufacturer profile ---------------- */
$MSSQL_SERVER_KEY   = (string)($_GET['mssql']     ?? hs_cfg('mssql_server_key', 'ts'));
$TBL_ARTIKLY        = (string)($_GET['artikly']   ?? hs_cfg('mssql_tbl_artikly', 'S4_Agenda_PCB.dbo.Artikly_Artikl'));
$TBL_SKLADY         = (string)($_GET['sklady']    ?? hs_cfg('mssql_tbl_sklady',  'S4_Agenda_PCB.dbo.Sklady_Zasoba'));

$activeTab          = (string)($_GET['tab']       ?? 'eol'); // eol | nostock | badean
$limit              = max(100, (int)($_GET['limit'] ?? 50000));
$export             = (int)($_GET['export'] ?? 0);

/* --- Sorting config --- */
$sort = (string)($_GET['sort'] ?? '');
$dir  = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

/* --- Manufacturer selection strictly from registry --- */
$allProfiles = hs_manufacturer_profiles(); // associative [slug => config]
$slug        = (string)($_GET['mfg'] ?? array_key_first($allProfiles));
if (!isset($allProfiles[$slug])) $slug = array_key_first($allProfiles);
$mfg         = hs_mfg($slug); // validated config

// Extract required settings from profile (NO manual overrides here — profile is the source of truth)
$HS_TABLE         = (string)($mfg['hs_table'] ?? '');
$MS_NAME          = (string)($mfg['ms_name'] ?? '');
$MS_CODE_COL      = (string)($mfg['ms_code_col'] ?? 'Kod');     // 'Kod' | 'Katalog'
$CODE_PREFIX      = (string)($mfg['code_prefix'] ?? '');
$MAKE_ARTICLE_KEY = !empty($mfg['make_article_key']);

if ($HS_TABLE === '' || $MS_NAME === '') {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Manufacturer profile for '{$slug}' is incomplete (missing hs_table or ms_name).";
  exit;
}
if (!in_array($MS_CODE_COL, ['Kod','Katalog'], true)) {
  $MS_CODE_COL = 'Kod';
}

/* --- Other filters --- */
$FILTER_ZKRATKA20  = (string)hs_cfg('filter_zkratka20', 'eshop');
$SKLAD_ID_FILTER   = (string)hs_cfg('sklad_id_filter',  '139C441A-DAF2-4717-9A10-7CA8C2BFAA2E');
$eshopOnly         = (int)($_GET['eshop_only'] ?? 0) === 1;

/* --- Sortable keys mapping --- */
$sortable = [
  'eol' => [
    'kod'        => ['kod',        'alpha'],
    'article'    => ['article',    'alpha'],
    'nazev'      => ['nazev',      'alpha'],
    'zkratka20'  => ['zkratka20',  'alpha'],
    'vyrobce'    => ['vyrobce',    'alpha'],
    'dostupne'   => ['dostupne',   'num'],
    'eta'        => ['eta',        'date'],
  ],
  'nostock' => [
    'code'             => ['Code',             'alpha'],
    'nazev'            => ['Nazev',            'alpha'],
    'zkratka20'        => ['Zkratka20',        'alpha'],
    'dostupnemnozstvi' => ['DostupneMnozstvi', 'num'],
    'stock'            => ['Stock',            'num'],
    'eta'              => ['ETA',              'date'],
  ],
  'badean' => [
    'code'       => ['Kod',       'alpha'],
    'nazev'      => ['Nazev',     'alpha'],
    'ean'        => ['EAN',       'alpha'],
    'carovykod'  => ['CarovyKod', 'alpha'],
    'stock'      => ['stock',     'num'],
    'eta'        => ['eta',       'date'],
  ],
];
if (!isset($sortable[$activeTab][$sort])) {
  $sort = $activeTab === 'eol' ? 'kod' : 'code';
}

/* ---------------- Small helpers ---------------- */
function hs_build_url(array $overrides = []): string {
  $q = array_merge($_GET, $overrides);
  return '?' . http_build_query($q);
}
function hs_norm_ean(?string $s): string {
  return preg_replace('/\D+/', '', (string)$s);
}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function hs_sort_link(string $tab, string $key, string $label, string $currentSort, string $currentDir): string {
  $isActive = ($currentSort === $key);
  $nextDir  = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
  $url = hs_build_url(['tab' => $tab, 'sort' => $key, 'dir' => $nextDir]);
  $chev = $isActive ? ($currentDir === 'asc' ? '↑' : '↓') : '';
  return '<a class="text-decoration-none" href="'.htmlspecialchars($url).'">'.htmlspecialchars($label).' '.($chev ? "<span class=\"opacity-75\">$chev</span>" : '').'</a>';
}
function hs_cmp_mixed($a, $b, string $type, string $dir): int {
  $aNull = ($a === null || $a === '');
  $bNull = ($b === null || $b === '');
  if ($aNull && !$bNull) return 1;
  if ($bNull && !$aNull) return -1;
  switch ($type) {
    case 'num':  $cmp = (float)$a <=> (float)$b; break;
    case 'date':
      $ta = $a ? @strtotime((string)$a) : null;
      $tb = $b ? @strtotime((string)$b) : null;
      $cmp = ($ta === $tb) ? 0 : (($ta ?? PHP_INT_MAX) <=> ($tb ?? PHP_INT_MAX));
      break;
    default:     $cmp = strcmp(mb_strtolower((string)$a), mb_strtolower((string)$b));
  }
  return $dir === 'desc' ? -$cmp : $cmp;
}

/* ---------------- DB handles & Sentinel ---------------- */
$pdoMy = hs_pdo();

if (!function_exists('db_for')) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Missing db_for() for MSSQL connection. Define db_for('{$MSSQL_SERVER_KEY}').";
  exit;
}
$pdoMs = db_for($MSSQL_SERVER_KEY);

$sent = new debug_sentinel('hs_manufacturer_tabs', $pdoMy);

/* ---------------- Build HS code set (for EOL matching) ---------------- */
try {
  $myRows = qall("SELECT DISTINCT TRIM(code) AS code FROM `{$HS_TABLE}` WHERE code IS NOT NULL AND code <> ''");
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Failed to read MySQL table '{$HS_TABLE}': " . $e->getMessage();
  exit;
}

$codeSet   = [];
$prefixLen = mb_strlen($CODE_PREFIX);

foreach ($myRows as $r) {
  $code = trim((string)$r['code']);
  if ($code === '') continue;

  // Always include raw HS code
  $codeSet[mb_strtolower($code)] = true;

  // Optionally include "article key" (only relevant when MS_CODE_COL='Kod')
  if ($MAKE_ARTICLE_KEY && $MS_CODE_COL === 'Kod' && $CODE_PREFIX !== '') {
    $hasPrefix = (mb_strtolower(mb_substr($code, 0, $prefixLen)) === mb_strtolower($CODE_PREFIX));
    if ($hasPrefix) {
      $article = trim(mb_substr($code, $prefixLen));
      if ($article !== '') $codeSet[mb_strtolower($article)] = true;
    }
  }
}
$hsKeyCount = count($codeSet);

/* ---------------- Data per tab ---------------- */
$rows = [];
$msCount = 0;
$totalCount = 0;

$MS_LABEL = $MS_CODE_COL; // For UI headings

if ($activeTab === 'eol') {
  // MSSQL rows for given manufacturer and (optional) Zkratka20 filter,
  // then show those that are NOT present in HS (using codeSet with rules above).
  $zkrCond = $eshopOnly
    ? "AND a.Zkratka20 IS NOT NULL AND LOWER(LTRIM(RTRIM(a.Zkratka20))) LIKE LOWER(?)"
    : "AND a.Zkratka20 IS NOT NULL AND LOWER(LTRIM(RTRIM(a.Zkratka20))) = LOWER(?)";

  $sqlMs = "
    SELECT a.{$MS_CODE_COL} AS MsCode,
           a.Zkratka20,
           a.Nazev,
           a.Vyrobce_Nazev,
           ISNULL(s.Dostupne, 0) AS DostupneMnozstvi
      FROM {$TBL_ARTIKLY} a WITH (NOLOCK)
      OUTER APPLY (
        SELECT SUM(z.DostupneMnozstvi) AS Dostupne
          FROM {$TBL_SKLADY} z WITH (NOLOCK)
         WHERE z.Artikl_ID = a.ID
           AND z.Sklad_ID  = CONVERT(uniqueidentifier, ?)
      ) s
     WHERE a.Vyrobce_Nazev IS NOT NULL
       AND LOWER(LTRIM(RTRIM(a.Vyrobce_Nazev))) = LOWER(?)
       {$zkrCond}
  ";
  $params = [
    $SKLAD_ID_FILTER,
    $MS_NAME,
    $eshopOnly ? (mb_strtolower($FILTER_ZKRATKA20).'%') : mb_strtolower($FILTER_ZKRATKA20),
  ];
  $stMs = qexec($sqlMs, $params, null, $MSSQL_SERVER_KEY);

  while ($row = $stMs->fetch(PDO::FETCH_ASSOC)) {
    $msCount++;
    $msCode = trim((string)($row['MsCode'] ?? ''));
    if ($msCode === '') continue;

    // comparable keys to check
    $keysToCheck = [ mb_strtolower($msCode) ];

    // if using Kod + article key, also check w/o prefix
    $article = '';
    $hasPrefix = false;
    if ($MAKE_ARTICLE_KEY && $MS_CODE_COL === 'Kod' && $CODE_PREFIX !== '') {
      $hasPrefix = (mb_strtolower(mb_substr($msCode, 0, $prefixLen)) === mb_strtolower($CODE_PREFIX));
      if ($hasPrefix) {
        $article = trim(mb_substr($msCode, $prefixLen));
        if ($article !== '') $keysToCheck[] = mb_strtolower($article);
      }
    }

    $found = false;
    foreach ($keysToCheck as $k) {
      if (isset($codeSet[$k])) { $found = true; break; }
    }
    if (!$found) {
      $rows[] = [
        'kod'       => $msCode,
        'article'   => ($hasPrefix ? $article : ''),
        'vyrobce'   => (string)($row['Vyrobce_Nazev'] ?? ''),
        'zkratka20' => (string)($row['Zkratka20'] ?? ''),
        'nazev'     => (string)($row['Nazev'] ?? ''),
        'dostupne'  => (float)($row['DostupneMnozstvi'] ?? 0),
        'eta'       => null,
      ];
    }
  }
  $totalCount = count($rows);

} elseif ($activeTab === 'nostock') {
  // HS rows with stock <= 0, show MSSQL Nazev; MSSQL lookup by MS_CODE_COL
  $hsRows2 = qall("
    SELECT code AS code, stock AS stock, eta AS eta
      FROM `{$HS_TABLE}`
     WHERE (stock IS NULL OR stock <= 0)
     LIMIT {$limit}
  ");
  $codes = array_values(array_unique(array_map(static fn($r) => trim((string)$r['code']), $hsRows2)));
  $codes = array_values(array_filter($codes, static fn($c) => $c !== ''));

  $totalCount = (int)qcell("SELECT COUNT(*) FROM `{$HS_TABLE}` WHERE (stock IS NULL OR stock <= 0)");

  if ($codes) {
    $chunkSize = 800;
    $msMap = []; // MSSQL code (Kod/Katalog) => ['Nazev','Zkratka20','DostupneMnozstvi']

    for ($i = 0; $i < count($codes); $i += $chunkSize) {
      $chunk = array_slice($codes, $i, $chunkSize);
      $placeholders = implode(',', array_fill(0, count($chunk), '?'));

      // Zkratka20 restriction (exact vs LIKE) and Manufacturer restriction (Vyrobce_Nazev)
      $zkrCond = $eshopOnly
        ? "AND a.Zkratka20 IS NOT NULL AND LOWER(LTRIM(RTRIM(a.Zkratka20))) LIKE LOWER(?)"
        : "AND a.Zkratka20 IS NOT NULL AND LOWER(LTRIM(RTRIM(a.Zkratka20))) = LOWER(?)";

      // Params order: 1) sklad, 2..N) IN (...) codes, then Vyrobce_Nazev, then Zkratka20 value
      $params = array_merge(
        [$SKLAD_ID_FILTER],
        $chunk,
        [
          $MS_NAME,
          $eshopOnly ? (mb_strtolower($FILTER_ZKRATKA20).'%') : mb_strtolower($FILTER_ZKRATKA20),
        ]
      );

      $sql = "
        SELECT a.{$MS_CODE_COL} AS MsCode,
               a.Nazev,
               a.Zkratka20,
               ISNULL(s.Dostupne, 0) AS DostupneMnozstvi
          FROM {$TBL_ARTIKLY} a WITH (NOLOCK)
          OUTER APPLY (
            SELECT SUM(z.DostupneMnozstvi) AS Dostupne
              FROM {$TBL_SKLADY} z WITH (NOLOCK)
             WHERE z.Artikl_ID = a.ID
               AND z.Sklad_ID  = CONVERT(uniqueidentifier, ?)
          ) s
         WHERE a.{$MS_CODE_COL} IN ({$placeholders})
           AND a.Vyrobce_Nazev IS NOT NULL
           AND LOWER(LTRIM(RTRIM(a.Vyrobce_Nazev))) = LOWER(?)
           {$zkrCond}
      ";
      $stm = qexec($sql, $params, null, $MSSQL_SERVER_KEY);
      while ($m = $stm->fetch(PDO::FETCH_ASSOC)) {
        $msCode = trim((string)($m['MsCode'] ?? ''));
        if ($msCode === '') continue;
        $msMap[$msCode] = [
          'Nazev'            => (string)($m['Nazev'] ?? ''),
          'Zkratka20'        => (string)($m['Zkratka20'] ?? ''),
          'DostupneMnozstvi' => (float)($m['DostupneMnozstvi'] ?? 0),
        ];
      }
    }

    $rows = [];
    foreach ($hsRows2 as $r) {
      $code = trim((string)$r['code']);
      if ($code === '' || !isset($msMap[$code])) continue;

      $rows[] = [
        'Code'             => $code,
        'Nazev'            => $msMap[$code]['Nazev'],  // MSSQL name
        'Zkratka20'        => $msMap[$code]['Zkratka20'],
        'DostupneMnozstvi' => (float)$msMap[$code]['DostupneMnozstvi'],
        'Stock'            => (string)($r['stock'] ?? ''),
        'ETA'              => (string)($r['eta']   ?? ''),
      ];
    }
  } else {
    $rows = [];
  }

} elseif ($activeTab === 'badean') {
  // Compare HS EAN (digits only) vs MSSQL CarovyKod for items present in MSSQL by MS_CODE_COL
  $hsRowsAll = qall("
    SELECT code AS code, ean AS ean, stock AS stock, eta AS eta
      FROM `{$HS_TABLE}`
     WHERE code IS NOT NULL AND code <> ''
  ");

  $hsMap = [];     // HS code => { ean, stock, eta }
  $codes = [];
  foreach ($hsRowsAll as $r) {
    $c = trim((string)$r['code']);
    if ($c === '') continue;
    $hsMap[$c] = [
      'ean'   => (string)($r['ean'] ?? ''),
      'stock' => (string)($r['stock'] ?? ''),
      'eta'   => (string)($r['eta']   ?? ''),
    ];
    $codes[] = $c;
  }
  $codes = array_values(array_unique($codes));

  $rows = [];
  $msCount = 0;

  if ($codes) {
    $chunkSize = 800;
    for ($i = 0; $i < count($codes); $i += $chunkSize) {
      $chunk = array_slice($codes, $i, $chunkSize);
      $placeholders = implode(',', array_fill(0, count($chunk), '?'));
      $params = array_merge([$MS_NAME], $chunk);

      $sql = "
        SELECT a.{$MS_CODE_COL} AS MsCode,
               a.Nazev,
               a.CarovyKod,
               a.Zkratka20
          FROM {$TBL_ARTIKLY} a WITH (NOLOCK)
         WHERE a.Vyrobce_Nazev IS NOT NULL
           AND LOWER(LTRIM(RTRIM(a.Vyrobce_Nazev))) = LOWER(?)
           AND a.{$MS_CODE_COL} IN ({$placeholders})
      ";
      $stm = qexec($sql, $params, null, $MSSQL_SERVER_KEY);

      while ($m = $stm->fetch(PDO::FETCH_ASSOC)) {
        $msCount++;
        $msCode    = trim((string)($m['MsCode'] ?? ''));
        if ($msCode === '' || !isset($hsMap[$msCode])) continue;

        $nazev     = (string)($m['Nazev'] ?? '');
        $carovykod = (string)($m['CarovyKod'] ?? '');
        $zk        = (string)($m['Zkratka20'] ?? '');

        $hsEan     = (string)$hsMap[$msCode]['ean'];
        $normHs    = hs_norm_ean($hsEan);
        $normMs    = hs_norm_ean($carovykod);

        if ($normHs !== '' && $normMs !== '' && $normHs !== $normMs) {
          $rows[] = [
            'Kod'       => $msCode,
            'Nazev'     => $nazev,
            'EAN'       => $hsEan,        // HS value
            'CarovyKod' => $carovykod,    // MSSQL value
            'Zkratka20' => $zk,
            'stock'     => (string)$hsMap[$msCode]['stock'],
            'eta'       => (string)$hsMap[$msCode]['eta'],
          ];
          if (count($rows) >= $limit) break 2; // page limit
        }
      }
    }
  }

  $totalCount = count($rows);
}


/* ---------------- Sort rows (PHP) ---------------- */
[$rowKey, $type] = $sortable[$activeTab][$sort] ?? [null, null];
if ($rowKey) {
  usort($rows, function ($aRow, $bRow) use ($rowKey, $type, $dir) {
    $a = $aRow[$rowKey] ?? null;
    $b = $bRow[$rowKey] ?? null;
    if ($type === 'num') {
      $a = ($a === '' || $a === null) ? null : (float)$a;
      $b = ($b === '' || $b === null) ? null : (float)$b;
    }
    return hs_cmp_mixed($a, $b, $type, $dir);
  });
}

/* ---------------- Sentinel summary ---------------- */
$sent->record('hs_manufacturer_tabs_summary', [
  'tab'              => $activeTab,
  'mfg_slug'         => $slug,
  'hs_table'         => $HS_TABLE,
  'manufacturer'     => $MS_NAME,
  'mssql_code_col'   => $MS_CODE_COL,
  'hs_codes_seen'    => $hsKeyCount,
  'mssql_scanned'    => $msCount,
  'result_count'     => count($rows),
  'sorted_by'        => $sort,
  'direction'        => $dir,
  'prefix_for_match' => $CODE_PREFIX,
  'filter_zkratka20' => $FILTER_ZKRATKA20,
  'sklad_id'         => $SKLAD_ID_FILTER,
  'eshop_only'       => $eshopOnly ? 1 : 0,
], code: 'hs_mfg_tabs');
$sent->persist();

/* ---------------- XLSX export (before any HTML) ---------------- */
if ($export) {
  $sheetName = strtoupper($activeTab);
  $fname = "hs_{$slug}_{$activeTab}" . ($eshopOnly ? '_eshop' : '') . ".xlsx";

  if (!class_exists(Spreadsheet::class)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "PhpSpreadsheet not found. Please install or fix autoload path.";
    exit;
  }

  $spread = new Spreadsheet();
  $sheet  = $spread->getActiveSheet();
  $sheet->setTitle(mb_substr($sheetName, 0, 31)); // Excel sheet name limit

  if ($activeTab === 'eol') {
    $hdr = [$MS_LABEL];
    if ($MAKE_ARTICLE_KEY && $MS_CODE_COL==='Kod' && $CODE_PREFIX!=='') {
      $hdr[] = "Article ({$MS_LABEL} w/o {$CODE_PREFIX})";
    }
    $hdr = array_merge($hdr, ['Nazev','Vyrobce_Nazev','Zkratka20','DostupneMnozstvi','ETA']);
    $sheet->fromArray($hdr, null, 'A1');
  } elseif ($activeTab === 'nostock') {
    $sheet->fromArray(['Code','Nazev','Zkratka20','DostupneMnozstvi','Stock','ETA'], null, 'A1');
  } else { // badean
    $sheet->fromArray([$MS_LABEL,'Nazev (MSSQL)','HS EAN','MSSQL Čiarový kód','Stock','ETA','Zkratka20'], null, 'A1');
  }

  $rowIdx = 2;
  foreach ($rows as $r) {
    if ($activeTab === 'eol') {
      $row = [ $r['kod'] ?? '' ];
      if ($MAKE_ARTICLE_KEY && $MS_CODE_COL==='Kod' && $CODE_PREFIX!=='') {
        $row[] = $r['article'] ?? '';
      }
      $row[] = $r['nazev'] ?? '';
      $row[] = $r['vyrobce'] ?? '';
      $row[] = $r['zkratka20'] ?? '';
      $row[] = isset($r['dostupne']) ? (float)$r['dostupne'] : null;
      $row[] = '';
      $sheet->fromArray([$row], null, "A{$rowIdx}");
    } elseif ($activeTab === 'nostock') {
      $sheet->fromArray([[
        $r['Code'] ?? '',
        $r['Nazev'] ?? '',
        $r['Zkratka20'] ?? '',
        (isset($r['DostupneMnozstvi']) ? (float)$r['DostupneMnozstvi'] : null),
        $r['Stock'] ?? '',
        $r['ETA'] ?? '',
      ]], null, "A{$rowIdx}");
    } else {
      $sheet->fromArray([[
        $r['Kod'] ?? '',
        $r['Nazev'] ?? '',
        $r['EAN'] ?? '',
        $r['CarovyKod'] ?? '',
        $r['stock'] ?? '',
        $r['eta'] ?? '',
        $r['Zkratka20'] ?? '',
      ]], null, "A{$rowIdx}");

      // Preserve leading zeros
      $colEAN = Coordinate::stringFromColumnIndex(3); // C
      $colCK  = Coordinate::stringFromColumnIndex(4); // D
      $sheet->setCellValueExplicit("{$colEAN}{$rowIdx}", (string)($r['EAN'] ?? ''), DataType::TYPE_STRING);
      $sheet->setCellValueExplicit("{$colCK}{$rowIdx}",  (string)($r['CarovyKod'] ?? ''), DataType::TYPE_STRING);
    }
    $rowIdx++;
  }

  // Auto-size
  $highestCol = $sheet->getHighestColumn();
  foreach (range('A', $highestCol) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
  }

  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $fname . '"');
  header('Cache-Control: max-age=0');

  $writer = new Xlsx($spread);
  $writer->save('php://output');
  exit;
}

/* ---------------- HTML ---------------- */
include __DIR__ . '/../../partials/header.php';
?>
<div class="container my-4">
  <h1 class="mb-3">
    <?= strtoupper(h($MS_NAME)) ?> — Health Overview (<?= htmlspecialchars(strtoupper($activeTab)) ?>)
    <small class="text-secondary d-block fs-6">
      EOL: Vyrobce_Nazev='<?= htmlspecialchars($MS_NAME) ?>', Zkratka20='<?= htmlspecialchars($FILTER_ZKRATKA20) ?>'<?= $eshopOnly ? ' (LIKE %)' : '' ?>, Sklad_ID=<?= htmlspecialchars($SKLAD_ID_FILTER) ?>. <br/>
      No Stock: <?= htmlspecialchars($HS_TABLE) ?>.stock ≤ 0; matched by <?= h($MS_LABEL) ?>; MSSQL Nazev shown; Vyrobce_Nazev='<?= htmlspecialchars($MS_NAME) ?>', Zkratka20='<?= htmlspecialchars($FILTER_ZKRATKA20) ?>'<?= $eshopOnly ? ' (LIKE %)' : '' ?>, Sklad_ID=<?= htmlspecialchars($SKLAD_ID_FILTER) ?>. <br/>
      Bad EAN: HS EAN ≠ MSSQL Čiarový kód, matched by <?= h($MS_LABEL) ?>. Click headers to sort.
    </small>
  </h1>

  <!-- Manufacturer selector (strictly from registry) -->
  <form class="mb-3 d-flex gap-2 align-items-center" method="get" action="">
    <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
    <label class="form-label mb-0 me-1">Manufacturer</label>
    <select class="form-select form-select-sm" name="mfg" style="max-width:16rem" onchange="this.form.submit()">
      <?php foreach ($allProfiles as $sl => $cfg): ?>
        <option value="<?= h($sl) ?>" <?= $sl===$slug?'selected':'' ?>>
          <?= h($cfg['ms_name'] ?? $sl) ?> (<?= h($sl) ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <label class="form-check form-check-inline ms-3">
      <input class="form-check-input" type="checkbox" name="eshop_only" value="1" <?= $eshopOnly?'checked':'' ?> onchange="this.form.submit()" />
      <span class="form-check-label">eshop only</span>
    </label>

    <noscript><button class="btn btn-sm btn-primary">Apply</button></noscript>
  </form>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item">
      <a class="nav-link <?= $activeTab==='eol'?'active':'' ?>" href="<?= htmlspecialchars(hs_build_url(['tab'=>'eol','sort'=>'kod','dir'=>'asc'])) ?>">EOL</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $activeTab==='nostock'?'active':'' ?>" href="<?= htmlspecialchars(hs_build_url(['tab'=>'nostock','sort'=>'code','dir'=>'asc'])) ?>">No Stock</a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $activeTab==='badean'?'active':'' ?>" href="<?= htmlspecialchars(hs_build_url(['tab'=>'badean','sort'=>'code','dir'=>'asc'])) ?>">Bad EAN</a>
    </li>
  </ul>

  <div class="d-flex gap-2 mb-3">
    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(hs_build_url(['tab'=>$activeTab,'export'=>1])) ?>">Export XLSX</a>
    <?php if ($activeTab==='eol'): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(hs_build_url(['tab'=>'eol','sort'=>'kod','dir'=>'asc'])) ?>">Reset filters</a>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header fw-bold">
      <?php if ($activeTab==='eol'): ?>
        MSSQL (Vyrobce_Nazev='<?= htmlspecialchars($MS_NAME) ?>', Zkratka20='<?= htmlspecialchars($FILTER_ZKRATKA20) ?>'<?= $eshopOnly ? ' LIKE %' : '' ?>, Sklad_ID=<?= htmlspecialchars($SKLAD_ID_FILTER) ?>) missing in <?= htmlspecialchars($HS_TABLE) ?> — match on <?= h($MS_LABEL) ?>
      <?php elseif ($activeTab==='nostock'): ?>
        <?= htmlspecialchars($HS_TABLE) ?> — Stock ≤ 0 (present in MSSQL; Vyrobce_Nazev='<?= htmlspecialchars($MS_NAME) ?>', Zkratka20='<?= htmlspecialchars($FILTER_ZKRATKA20) ?>'<?= $eshopOnly ? ' LIKE %' : '' ?>, Sklad_ID=<?= htmlspecialchars($SKLAD_ID_FILTER) ?>) — match on <?= h($MS_LABEL) ?>
      <?php else: ?>
        <?= htmlspecialchars($HS_TABLE) ?> — Bad EAN (Vyrobce=<?= htmlspecialchars($MS_NAME) ?>, match on <?= h($MS_LABEL) ?>)
      <?php endif; ?>
      <span class="badge bg-secondary ms-2"><?= number_format($totalCount) ?> rows</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-striped table-dark mb-0">
          <thead>
            <?php if ($activeTab==='eol'): ?>
              <tr>
                <th style="width: 20ch;"><?= hs_sort_link('eol','kod',h($MS_LABEL),$sort,$dir) ?></th>
                <?php if ($MAKE_ARTICLE_KEY && $MS_CODE_COL==='Kod' && $CODE_PREFIX!==''): ?>
                  <th><?= hs_sort_link('eol','article',"Article ({$MS_LABEL} w/o '".htmlspecialchars($CODE_PREFIX)."')",$sort,$dir) ?></th>
                <?php endif; ?>
                <th><?= hs_sort_link('eol','nazev','Nazev',$sort,$dir) ?></th>
                <th class="text-end"><?= hs_sort_link('eol','dostupne','DostupneMnozstvi',$sort,$dir) ?></th>
                <th><?= hs_sort_link('eol','zkratka20','Zkratka20',$sort,$dir) ?></th>
                <th><?= hs_sort_link('eol','vyrobce','Vyrobce_Nazev',$sort,$dir) ?></th>
                <th><?= hs_sort_link('eol','eta','ETA',$sort,$dir) ?></th>
              </tr>
            <?php elseif ($activeTab==='nostock'): ?>
              <tr>
                <th style="width: 20ch;"><?= hs_sort_link('nostock','code','Code',$sort,$dir) ?></th>
                <th><?= hs_sort_link('nostock','nazev','Nazev',$sort,$dir) ?></th>
                <th><?= hs_sort_link('nostock','zkratka20','Zkratka20',$sort,$dir) ?></th>
                <th class="text-end"><?= hs_sort_link('nostock','dostupnemnozstvi','DostupneMnozstvi',$sort,$dir) ?></th>
                <th class="text-end"><?= hs_sort_link('nostock','stock','Stock',$sort,$dir) ?></th>
                <th><?= hs_sort_link('nostock','eta','ETA',$sort,$dir) ?></th>
              </tr>
            <?php else: ?>
              <tr>
                <th style="width: 20ch;"><?= hs_sort_link('badean','code',h($MS_LABEL),$sort,$dir) ?></th>
                <th><?= hs_sort_link('badean','nazev','Nazev',$sort,$dir) ?></th>
                <th><?= hs_sort_link('badean','ean','HS EAN',$sort,$dir) ?></th>
                <th><?= hs_sort_link('badean','carovykod','MSSQL Čiarový kód',$sort,$dir) ?></th>
                <th class="text-end"><?= hs_sort_link('badean','stock','Stock',$sort,$dir) ?></th>
                <th><?= hs_sort_link('badean','eta','ETA',$sort,$dir) ?></th>
              </tr>
            <?php endif; ?>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr>
                <td colspan="<?= $activeTab==='eol' ? ( ($MAKE_ARTICLE_KEY && $MS_CODE_COL==='Kod' && $CODE_PREFIX!=='') ? 7 : 6 ) : 6 ?>" class="text-success">All good — nothing to show 🎉</td>
              </tr>
            <?php else: ?>
              <?php if ($activeTab==='eol'): foreach ($rows as $m): ?>
                <tr>
                  <td><?= htmlspecialchars($m['kod']) ?></td>
                  <?php if ($MAKE_ARTICLE_KEY && $MS_CODE_COL==='Kod' && $CODE_PREFIX!==''): ?>
                    <td><?= htmlspecialchars($m['article'] ?? '') ?></td>
                  <?php endif; ?>
                  <td class="text-truncate" style="max-width: 520px;"><?= htmlspecialchars($m['nazev']) ?></td>
                  <td class="text-end"><?= htmlspecialchars(number_format((float)$m['dostupne'], 2, '.', '')) ?></td>
                  <td><?= htmlspecialchars($m['zkratka20']) ?></td>
                  <td><?= htmlspecialchars($m['vyrobce']) ?></td>
                  <td>—</td>
                </tr>
              <?php endforeach; elseif ($activeTab==='nostock'): foreach ($rows as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['Code']) ?></td>
                  <td class="text-truncate" style="max-width: 520px;"><?= htmlspecialchars($r['Nazev']) ?></td>
                  <td><?= htmlspecialchars($r['Zkratka20']) ?></td>
                  <td class="text-end"><?= htmlspecialchars(number_format((float)$r['DostupneMnozstvi'], 2, '.', '')) ?></td>
                  <td class="text-end"><?= htmlspecialchars((string)$r['Stock']) ?></td>
                  <td><?= htmlspecialchars((string)$r['ETA']) ?></td>
                </tr>
              <?php endforeach; else: foreach ($rows as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['Kod']) ?></td>
                  <td class="text-truncate" style="max-width: 520px;"><?= htmlspecialchars($r['Nazev']) ?></td>
                  <td><?= htmlspecialchars((string)$r['EAN']) ?></td>
                  <td><?= htmlspecialchars((string)$r['CarovyKod']) ?></td>
                  <td class="text-end"><?= htmlspecialchars((string)($r['stock'] ?? '')) ?></td>
                  <td><?= htmlspecialchars((string)($r['eta'] ?? '')) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($rows) > $limit): ?>
        <div class="p-2 small text-secondary">Showing first <?= number_format($limit) ?> rows. Use XLSX export for the full list.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
