<?php
declare(strict_types=1);

/**
 * ROLINE Health — MSSQL rows missing in MySQL (hc_roline)
 * Location: /health_check/roline_health.php  (base dir)
 */

require_once __DIR__ . '/bootstrap.php'; // centralizes db(), mysql, hc_* helpers, sentinel, etc.

/** CONFIG (override via hc_cfg if you like) */
$MSSQL_SERVER_KEY = (string)hc_cfg('mssql_server_key', 'ts'); // from appcfg.php if provided
$TBL_ARTIKLY      = (string)hc_cfg('mssql_tbl_artikly', 'S4_Agenda_PCB.dbo.Artikly_Artikl');
$TBL_SKLADY       = (string)hc_cfg('mssql_tbl_sklady',  'S4_Agenda_PCB.dbo.Sklady_Zasoba');
$ROLINE_TABLE     = hc_table_name('roline');                 // -> 'hc_roline'
$FILTER_ZKRATKA20 = (string)hc_cfg('filter_zkratka20', 'eshop');
$FILTER_ZKRATKA12 = (string)hc_cfg('filter_zkratka12', 'ROL');
$GROUP_ID_FILTER  = (string)hc_cfg('group_id_filter', '139C441A-DAF2-4717-9A10-7CA8C2BFAA2E');

$pdoMy  = db();
$pdoMs  = db_for($MSSQL_SERVER_KEY);
$sent   = new debug_sentinel('hc_roline_health', $pdoMy);

$limit  = max(100, (int)($_GET['limit'] ?? 50000));
$export = (int)($_GET['export'] ?? 0);

/** Normalize to base "NN.NN.NNNN" and drop any "-SUFFIX" */
function roline_base_art(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;

    // cut at first '-'
    $dashPos = strpos($s, '-');
    if ($dashPos !== false) $s = substr($s, 0, $dashPos);

    // remove spaces
    $s = preg_replace('~\s+~', '', $s);

    // already dotted? capture base
    if (preg_match('~(\d{2}\.\d{2}\.\d{4})~', $s, $m)) return $m[1];

    // 8 contiguous digits -> dotted
    if (preg_match('~^(\d{2})(\d{2})(\d{4})$~', $s, $m)) {
        return "{$m[1]}.{$m[2]}.{$m[3]}";
    }
    return null;
}

/** 1) Build set of ROLINE base article numbers present in MySQL */
$myRows = qall("
    SELECT COALESCE(NULLIF(article_number,''), NULLIF(code,'')) AS an
    FROM `{$ROLINE_TABLE}`
    WHERE COALESCE(NULLIF(article_number,''), NULLIF(code,'')) IS NOT NULL
");
$rolineSet = [];
foreach ($myRows as $r) {
    $base = roline_base_art((string)$r['an']);
    if ($base !== null) $rolineSet[mb_strtolower($base)] = true;
}
$rolineCount = count($rolineSet);

/** 2) MSSQL candidates (filtered) + stock via OUTER APPLY */
$sqlMs = "
    SELECT a.Katalog, a.Zkratka20, a.Zkratka12, a.Nazev,
           ISNULL(s.Dostupne, 0) AS DostupneMnozstvi
    FROM {$TBL_ARTIKLY} a WITH (NOLOCK)
    OUTER APPLY (
        SELECT SUM(z.DostupneMnozstvi) AS Dostupne
        FROM {$TBL_SKLADY} z WITH (NOLOCK)
        WHERE z.Artikl_ID = a.ID
          AND z.Sklad_ID = CONVERT(uniqueidentifier, ?)
    ) s
    WHERE a.Katalog IS NOT NULL AND LTRIM(RTRIM(a.Katalog)) <> ''
      AND a.Zkratka20 IS NOT NULL
      AND LOWER(LTRIM(RTRIM(a.Zkratka20))) = ?
      AND a.Zkratka12 IS NOT NULL
      AND LOWER(LTRIM(RTRIM(a.Zkratka12))) = ?
";
$stMs = qexec(
    $sqlMs,
    [$GROUP_ID_FILTER, mb_strtolower($FILTER_ZKRATKA20), mb_strtolower($FILTER_ZKRATKA12)],
    null,
    $MSSQL_SERVER_KEY
);

$missing = [];
$msCount = 0;

while ($row = $stMs->fetch()) {
    $msCount++;
    $katalog = (string)($row['Katalog'] ?? '');
    $zk20    = (string)($row['Zkratka20'] ?? '');
    $zk12    = (string)($row['Zkratka12'] ?? '');
    $nazev   = (string)($row['Nazev'] ?? '');
    $dost    = (float)($row['DostupneMnozstvi'] ?? 0);

    $base = roline_base_art($katalog);
    if ($base === null) continue;

    if (!isset($rolineSet[mb_strtolower($base)])) {
        $missing[] = [
            'katalog'   => $katalog,
            'base'      => $base,
            'nazev'     => $nazev,
            'dost'      => $dost,
            'zkratka20' => $zk20,
            'zkratka12' => $zk12,
        ];
        if (!$export && count($missing) >= $limit) break;
    }
}

$missingCount = count($missing);

$sent->record('roline_health_summary_eshop_rol_only', [
    'roline_table'      => $ROLINE_TABLE,
    'roline_in_mysql'   => $rolineCount,
    'mssql_scanned'     => $msCount,
    'missing_count'     => $missingCount,
    'filter_zkratka20'  => $FILTER_ZKRATKA20,
    'filter_zkratka12'  => $FILTER_ZKRATKA12,
    'group_id'          => $GROUP_ID_FILTER,
], code: 'roline_health');
$sent->persist();

/** CSV export (exit before HTML header/footer) */
if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=roline_missing_eshop_rol_in_mysql.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Katalog (MSSQL)', 'Base (NN.NN.NNNN)', 'Nazev', 'DostupneMnozstvi', 'Zkratka20', 'Zkratka12']);
    foreach ($missing as $m) {
        fputcsv($out, [$m['katalog'], $m['base'], $m['nazev'], $m['dost'], $m['zkratka20'], $m['zkratka12']]);
    }
    fclose($out);
    exit;
}

// ---------- HTML ----------
include dirname(__DIR__) . '/partials/header.php';
?>
<div class="container my-4">
  <h1 class="mb-3">
    ROLINE Health — MSSQL missing in MySQL
    <small class="text-secondary d-block fs-6">
      Filters: Zkratka20 = “<?= htmlspecialchars($FILTER_ZKRATKA20) ?>”, Zkratka12 = “<?= htmlspecialchars($FILTER_ZKRATKA12) ?>”, Group_ID = <?= htmlspecialchars($GROUP_ID_FILTER) ?>
    </small>
  </h1>

  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <div class="small text-secondary">ROLINE MySQL table</div>
          <div class="fw-bold"><?= htmlspecialchars($ROLINE_TABLE) ?></div>
        </div>
        <div class="col-md-3">
          <div class="small text-secondary">ROLINE articles in MySQL (normalized base)</div>
          <div class="fw-bold"><?= number_format($rolineCount) ?></div>
        </div>
        <div class="col-md-3">
          <div class="small text-secondary">
            MSSQL scanned (Zkratka20='<?= htmlspecialchars($FILTER_ZKRATKA20) ?>', Zkratka12='<?= htmlspecialchars($FILTER_ZKRATKA12) ?>')
          </div>
          <div class="fw-bold"><?= number_format($msCount) ?></div>
        </div>
        <div class="col-md-3">
          <div class="small text-secondary">Missing in MySQL (showing up to <?= number_format($limit) ?>)</div>
          <div class="fw-bold <?= $missingCount ? 'text-warning' : 'text-success' ?>">
            <?= number_format($missingCount) ?>
          </div>
        </div>
      </div>

      <div class="mt-3">
        <a class="btn btn-sm btn-outline-primary" href="?export=1">Export CSV</a>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header fw-bold">
      MSSQL products (Nazev & DostupneMnozstvi) missing in <code><?= htmlspecialchars($ROLINE_TABLE) ?></code>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-striped table-dark mb-0 align-middle">
          <thead>
            <tr>
              <th style="width: 22ch;">Katalog (MSSQL)</th>
              <th>Base (NN.NN.NNNN)</th>
              <th>Nazev</th>
              <th class="text-end">DostupneMnozstvi</th>
              <th>Zkratka20</th>
              <th>Zkratka12</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$missingCount): ?>
              <tr><td colspan="6" class="text-success">All good — nothing missing 🎉</td></tr>
            <?php else: foreach ($missing as $m): ?>
              <tr>
                <td><?= htmlspecialchars($m['katalog']) ?></td>
                <td><?= htmlspecialchars($m['base']) ?></td>
                <td class="text-truncate" style="max-width: 480px;"><?= htmlspecialchars($m['nazev']) ?></td>
                <td class="text-end"><?= htmlspecialchars(number_format((float)$m['dost'], 2, '.', '')) ?></td>
                <td><?= htmlspecialchars($m['zkratka20']) ?></td>
                <td><?= htmlspecialchars($m['zkratka12']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($missingCount > $limit): ?>
        <div class="p-2 small text-secondary">Showing first <?= number_format($limit) ?> rows. Use CSV export for the full list.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
