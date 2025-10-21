<?php
declare(strict_types=1);

/**
 * LINDY Health — MSSQL (Zkratka20='eshop', Kod LIKE 'L%') missing in MySQL hc_lindy
 * Location: /health_check/lindy_health.php
 */

require_once __DIR__ . '/bootstrap.php'; // db(), db_for(), hc_* helpers, debug_sentinel

/** CONFIG (overridable via hc_cfg) */
$MSSQL_SERVER_KEY = (string)hc_cfg('mssql_server_key', 'ts'); // key used by db_for()
$TBL_ARTIKLY      = (string)hc_cfg('mssql_tbl_artikly', 'S4_Agenda_PCB.dbo.Artikly_Artikl');
$TBL_SKLADY       = (string)hc_cfg('mssql_tbl_sklady',  'S4_Agenda_PCB.dbo.Sklady_Zasoba');

$LINDY_TABLE      = hc_table_name('lindy');                     // -> hc_lindy
$LINDY_PREFIX     = (string)hc_cfg('lindy_prefix', 'L');        // MSSQL.Kod prefix
$FILTER_ZKRATKA20 = (string)hc_cfg('filter_zkratka20', 'eshop');
$GROUP_ID_FILTER  = (string)hc_cfg('group_id_filter',  '139C441A-DAF2-4717-9A10-7CA8C2BFAA2E');

$pdoMy  = db();
$pdoMs  = db_for($MSSQL_SERVER_KEY);
$sent   = new debug_sentinel('hc_lindy_health', $pdoMy);

$limit  = max(100, (int)($_GET['limit'] ?? 50000));
$export = (int)($_GET['export'] ?? 0);

/** 1) Build set of LINDY article numbers present in MySQL */
$myRows = qall("
    SELECT DISTINCT TRIM(COALESCE(NULLIF(article_number,''), NULLIF(code,''))) AS an
    FROM `{$LINDY_TABLE}`
    WHERE COALESCE(NULLIF(article_number,''), NULLIF(code,'')) IS NOT NULL
");
$lindySet = [];
foreach ($myRows as $r) {
    $k = mb_strtolower(trim((string)$r['an']));
    if ($k !== '') $lindySet[$k] = true;
}
$lindyCount = count($lindySet);

/** 2) MSSQL candidates:
 *    - Zkratka20 = 'eshop' (trimmed, case-insensitive)
 *    - Kod LIKE 'L%'
 *    - OUTER APPLY SUM(DostupneMnozstvi) from Sklady_Zasoba for Group_ID
 */
$sqlMs = "
    SELECT a.Kod,
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
    WHERE a.Kod LIKE ?
      AND a.Zkratka20 IS NOT NULL
      AND LOWER(LTRIM(RTRIM(a.Zkratka20))) = ?
";
$stMs = qexec(
    $sqlMs,
    [$GROUP_ID_FILTER, $LINDY_PREFIX . '%', mb_strtolower($FILTER_ZKRATKA20)],
    null,
    $MSSQL_SERVER_KEY
);

$missing = [];
$msCount = 0;

while ($row = $stMs->fetch()) {
    $msCount++;
    $kod   = (string)($row['Kod'] ?? '');
    $zk    = (string)($row['Zkratka20'] ?? '');
    $nazev = (string)($row['Nazev'] ?? '');
    $dost  = (float)($row['DostupneMnozstvi'] ?? 0);

    // strip prefix (case-insensitive)
    $prefixLen = mb_strlen($LINDY_PREFIX);
    if (mb_strtolower(mb_substr($kod, 0, $prefixLen)) !== mb_strtolower($LINDY_PREFIX)) continue;
    $article = trim(mb_substr($kod, $prefixLen));
    if ($article === '') continue;

    if (!isset($lindySet[mb_strtolower($article)])) {
        $missing[] = [
            'kod'       => $kod,
            'article'   => $article,  // Kod w/o prefix
            'zkratka20' => $zk,       // should be 'eshop'
            'nazev'     => $nazev,
            'dostupne'  => $dost,
        ];
        if (!$export && count($missing) >= $limit) break;
    }
}

$missingCount = count($missing);

/** Log summary */
$sent->record('lindy_health_summary_eshop_only', [
    'lindy_table'     => $LINDY_TABLE,
    'lindy_in_mysql'  => $lindyCount,
    'mssql_scanned'   => $msCount,
    'missing_count'   => $missingCount,
    'prefix'          => $LINDY_PREFIX,
    'filter_zkratka20'=> $FILTER_ZKRATKA20,
    'group_id'        => $GROUP_ID_FILTER,
], code: 'lindy_health');
$sent->persist();

/** CSV export (must be before any HTML output) */
if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=lindy_missing_eshop_in_mysql.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Kod', 'Article (Kod w/o prefix)', 'Nazev', 'DostupneMnozstvi', 'Zkratka20']);
    foreach ($missing as $m) {
        fputcsv($out, [$m['kod'], $m['article'], $m['nazev'], $m['dostupne'], $m['zkratka20']]);
    }
    fclose($out);
    exit;
}

// ---------------- HTML ----------------
include dirname(__DIR__) . '/partials/header.php';
?>
<div class="container my-4">
  <h1 class="mb-3">
    LINDY Health — MSSQL (Zkratka20 = “<?= htmlspecialchars($FILTER_ZKRATKA20) ?>”) missing in MySQL
    <small class="text-secondary d-block fs-6">
      Filters: Kod LIKE '<?= htmlspecialchars($LINDY_PREFIX) ?>%', Group_ID = <?= htmlspecialchars($GROUP_ID_FILTER) ?>
    </small>
  </h1>

  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <div class="small text-secondary">LINDY MySQL table</div>
          <div class="fw-bold"><?= htmlspecialchars($LINDY_TABLE) ?></div>
        </div>
        <div class="col-md-3">
          <div class="small text-secondary">LINDY articles in MySQL (COALESCE(article_number, code))</div>
          <div class="fw-bold"><?= number_format($lindyCount) ?></div>
        </div>
        <div class="col-md-3">
          <div class="small text-secondary">
            MSSQL scanned (Kod LIKE '<?= htmlspecialchars($LINDY_PREFIX) ?>%', Zkratka20='<?= htmlspecialchars($FILTER_ZKRATKA20) ?>')
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
      MSSQL products with Zkratka20 = “<?= htmlspecialchars($FILTER_ZKRATKA20) ?>” missing in LINDY MySQL
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-striped table-dark mb-0">
          <thead>
            <tr>
              <th style="width: 22ch;">Kod</th>
              <th>Article (Kod w/o prefix)</th>
              <th>Nazev</th>
              <th class="text-end">DostupneMnozstvi</th>
              <th>Zkratka20</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$missingCount): ?>
              <tr><td colspan="5" class="text-success">All good — nothing missing 🎉</td></tr>
            <?php else: foreach ($missing as $m): ?>
              <tr>
                <td><?= htmlspecialchars($m['kod']) ?></td>
                <td><?= htmlspecialchars($m['article']) ?></td>
                <td class="text-truncate" style="max-width: 520px;"><?= htmlspecialchars($m['nazev']) ?></td>
                <td class="text-end"><?= htmlspecialchars(number_format((float)$m['dostupne'], 2, '.', '')) ?></td>
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
<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
