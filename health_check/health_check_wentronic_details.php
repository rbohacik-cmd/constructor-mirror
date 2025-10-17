<?php
declare(strict_types=1);

/**
 * Wentronic Health — EXACT match: MSSQL.Kod vs MySQL hc_wentronic.article_number
 * Location: /health_check/wentronic_health.php
 */

require_once __DIR__ . '/bootstrap.php'; // db(), db_for(), hc_* helpers, debug_sentinel

/** CONFIG (can be overridden in config/config.php via hc_cfg) */
$MSSQL_SERVER_KEY = (string)hc_cfg('mssql_server_key', 'ts');
$TBL_ARTIKLY      = (string)hc_cfg('mssql_tbl_artikly', 'S4_Agenda_PCB.dbo.Artikly_Artikl');
$TBL_SKLADY       = (string)hc_cfg('mssql_tbl_sklady',  'S4_Agenda_PCB.dbo.Sklady_Zasoba');

$WENT_TABLE       = hc_table_name('wentronic');   // -> 'hc_wentronic'
$FILTER_ZKRATKA20 = (string)hc_cfg('filter_zkratka20', 'eshop');
$FILTER_ZKRATKA12 = (string)hc_cfg('filter_zkratka12_went', 'WEN');
$GROUP_ID_FILTER  = (string)hc_cfg('group_id_filter',  '139C441A-DAF2-4717-9A10-7CA8C2BFAA2E');

$pdoMy  = db();
$pdoMs  = db_for($MSSQL_SERVER_KEY);
$sent   = new debug_sentinel('hc_wentronic_health', $pdoMy);

$limit  = max(100, (int)($_GET['limit'] ?? 50000));
$export = (int)($_GET['export'] ?? 0);

/** 1) Exact-match set from MySQL: ONLY article_number (keep case/spacing as-is) */
$myRows = qall("
    SELECT DISTINCT article_number AS an
    FROM `{$WENT_TABLE}`
    WHERE article_number IS NOT NULL AND article_number <> ''
");
$wentSet = [];
foreach ($myRows as $r) {
    $k = (string)$r['an']; // exact match
    if ($k !== '') $wentSet[$k] = true;
}
$wentCount = count($wentSet);

/** 2) MSSQL candidates (Zkratka20 + Zkratka12 filters; no prefix filter) */
$sqlMs = "
    SELECT a.Kod,
           a.Zkratka12,
           a.Zkratka20,
           a.Nazev,
           ISNULL(s.Dostupne, 0) AS DostupneMnozstvi
    FROM {$TBL_ARTIKLY} a WITH (NOLOCK)
    OUTER APPLY (
        SELECT SUM(z.DostupneMnozstvi) AS Dostupne
        FROM {$TBL_SKLADY} z WITH (NOLOCK)
        WHERE z.Artikl_ID = a.ID
          AND z.Sklad_ID = CONVERT(uniqueidentifier, ?)
    ) s
    WHERE a.Zkratka20 IS NOT NULL
      AND LOWER(LTRIM(RTRIM(a.Zkratka20))) = ?
      AND a.Zkratka12 IS NOT NULL
      AND UPPER(LTRIM(RTRIM(a.Zkratka12))) = ?
";
$stMs = qexec(
    $sqlMs,
    [$GROUP_ID_FILTER, mb_strtolower($FILTER_ZKRATKA20), mb_strtoupper($FILTER_ZKRATKA12)],
    null,
    $MSSQL_SERVER_KEY
);

$missing = [];
$msCount = 0;

while ($row = $stMs->fetch()) {
    $msCount++;
    $kod   = (string)($row['Kod'] ?? '');
    $zk12  = (string)($row['Zkratka12'] ?? '');
    $zk20  = (string)($row['Zkratka20'] ?? '');
    $nazev = (string)($row['Nazev'] ?? '');
    $dost  = (float)($row['DostupneMnozstvi'] ?? 0);
    if ($kod === '') continue;

    // EXACT: MSSQL.Kod must exist as hc_wentronic.article_number
    if (!isset($wentSet[$kod])) {
        $missing[] = [
            'kod'            => $kod,
            'expected_field' => 'article_number',
            'zkratka12'      => $zk12,
            'zkratka20'      => $zk20,
            'nazev'          => $nazev,
            'dostupne'       => $dost,
        ];
        if (!$export && count($missing) >= $limit) break;
    }
}

$missingCount = count($missing);

/** Log summary */
$sent->record('wentronic_health_exact_match', [
    'wentronic_table'    => $WENT_TABLE,
    'wentronic_in_mysql' => $wentCount,
    'mssql_scanned'      => $msCount,
    'missing_count'      => $missingCount,
    'match_mode'         => 'EXACT: MSSQL.Kod === MySQL.article_number',
    'filter_zkratka20'   => $FILTER_ZKRATKA20,
    'filter_zkratka12'   => $FILTER_ZKRATKA12,
    'group_id'           => $GROUP_ID_FILTER,
], code: 'wentronic_health');
$sent->persist();

/** CSV export (must happen before any HTML output) */
if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=wentronic_missing_exact_WEN_eshop.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['MSSQL.Kod', 'Expected in MySQL (column)', 'Nazev', 'DostupneMnozstvi', 'Zkratka12', 'Zkratka20']);
    foreach ($missing as $m) {
        fputcsv($out, [$m['kod'], $m['expected_field'], $m['nazev'], $m['dostupne'], $m['zkratka12'], $m['zkratka20']]);
    }
    fclose($out);
    exit;
}

// ---------------- HTML ----------------
include __DIR__ . '/../partials/header.php';
?>
<div class="container my-4">
  <h1 class="mb-3">
    Wentronic Health — EXACT match: MSSQL <code>Kod</code> vs MySQL <code><?= htmlspecialchars($WENT_TABLE) ?>.article_number</code>
    <small class="text-secondary d-block fs-6">
      Filters: Zkratka20='<?= htmlspecialchars($FILTER_ZKRATKA20) ?>', Zkratka12='<?= htmlspecialchars($FILTER_ZKRATKA12) ?>', Group_ID=<?= htmlspecialchars($GROUP_ID_FILTER) ?> (case-sensitive compare in PHP)
    </small>
  </h1>

  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <div class="small text-secondary">Wentronic MySQL table</div>
          <div class="fw-bold"><?= htmlspecialchars($WENT_TABLE) ?></div>
        </div>
        <div class="col-md-3">
          <div class="small text-secondary">Distinct article_numbers in MySQL</div>
          <div class="fw-bold"><?= number_format($wentCount) ?></div>
        </div>
        <div class="col-md-3">
          <div class="small text-secondary">
            MSSQL scanned (Z20='<?= htmlspecialchars($FILTER_ZKRATKA20) ?>', Z12='<?= htmlspecialchars($FILTER_ZKRATKA12) ?>')
          </div>
          <div class="fw-bold"><?= number_format($msCount) ?></div>
        </div>
        <div class="col-md-3">
          <div class="small text-secondary">Missing (showing up to <?= number_format($limit) ?>)</div>
          <div class="fw-bold <?= $missingCount ? 'text-warning' : 'text-success' ?>"><?= number_format($missingCount) ?></div>
        </div>
      </div>

      <div class="mt-3">
        <a class="btn btn-sm btn-outline-primary" href="?export=1">Export CSV</a>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header fw-bold">MSSQL products missing in MySQL (exact Kod → article_number)</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-striped table-dark mb-0">
          <thead>
            <tr>
              <th style="width: 24ch;">MSSQL.Kod</th>
              <th>Expected in MySQL (column)</th>
              <th>Nazev</th>
              <th class="text-end">DostupneMnozstvi</th>
              <th>Zkratka12</th>
              <th>Zkratka20</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$missingCount): ?>
              <tr><td colspan="6" class="text-success">All good — nothing missing 🎉</td></tr>
            <?php else: foreach ($missing as $m): ?>
              <tr>
                <td><?= htmlspecialchars($m['kod']) ?></td>
                <td><code><?= htmlspecialchars($m['expected_field']) ?></code></td>
                <td class="text-truncate" style="max-width: 520px;"><?= htmlspecialchars($m['nazev']) ?></td>
                <td class="text-end"><?= htmlspecialchars(number_format((float)$m['dostupne'], 2, '.', '')) ?></td>
                <td><?= htmlspecialchars($m['zkratka12']) ?></td>
                <td><?= htmlspecialchars($m['zkratka20']) ?></td>
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
<?php include __DIR__ . '/../partials/footer.php'; ?>
