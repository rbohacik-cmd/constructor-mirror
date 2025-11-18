<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', '1');

// Ensure bootstrap (PROJECT_FS/BASE_URL/helpers)
if (!defined('PROJECT_FS')) {
  require_once __DIR__ . '/../partials/bootstrap.php';
}

$IS_CLI = (PHP_SAPI === 'cli');
parse_str($_SERVER['QUERY_STRING'] ?? '', $QS);

/* ------------------------- CONFIG ------------------------- */
$PROJECT_DIR = PROJECT_FS; // canonical project root
$SOURCE_EXT  = explode(',', strtolower($QS['src'] ?? 'php,js,ts,jsx,tsx,html,htm,css,scss,json'));
$TARGET_EXT  = explode(',', strtolower($QS['ext'] ?? 'php,js,css,html,htm,scss'));
$EXCLUDE_DIRS = [];
$DEFAULT_EXCLUDES = ['vendor','node_modules','.git','storage','logs','assets/cache','data/downloads'];

$excludeIni = PROJECT_FS . '/tools/file_usage_excludes.ini';
if (is_file($excludeIni)) {
  $lines = @file($excludeIni, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === ';' || $line[0] === '#') continue;
    $EXCLUDE_DIRS[] = str_replace('\\','/',$line);
  }
}
foreach ($DEFAULT_EXCLUDES as $d) if (!in_array($d, $EXCLUDE_DIRS, true)) $EXCLUDE_DIRS[] = $d;

$MAX_FILE_SIZE = (int)($QS['max_kb'] ?? 1500) * 1024;
$NEEDLE      = trim((string)($QS['q'] ?? ''));
$ONLY_UNUSED = (bool)($QS['unused'] ?? false);
$FORMAT      = strtolower((string)($QS['format'] ?? 'html'));

/* ---- loose precision controls ---- */
$DO_LOOSE    = (($QS['loose'] ?? '') === '1');
$LOOSE_MODE  = strtolower((string)($QS['loose_mode'] ?? 'off')); // off|unique|near|all
if (!in_array($LOOSE_MODE, ['off','unique','near','all'], true)) $LOOSE_MODE = 'off';

/* --------------------------- Helpers ---------------------------------- */
function is_excluded_dir(string $path, array $exclude, string $root): bool {
  $rel = ltrim(str_replace('\\','/', substr($path, strlen($root))), '/');
  foreach ($exclude as $ex) {
    if ($rel === $ex || str_starts_with($rel, rtrim($ex,'/').'/')) return true;
  }
  return false;
}

function iter_files(string $root, array $exts, array $exclude, int $maxFileSize): Generator {
  $rii = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
      new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
      function (SplFileInfo $cur) use ($exclude, $root) {
        return !($cur->isDir() && is_excluded_dir($cur->getPathname(), $exclude, $root));
      }
    ),
    RecursiveIteratorIterator::SELF_FIRST
  );
  $allow = array_flip(array_map('strtolower', array_map('trim', $exts)));
  foreach ($rii as $f) {
    /** @var SplFileInfo $f */
    if (!$f->isFile()) continue;
    $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
    if (!isset($allow[$ext])) continue;
    if ($maxFileSize > 0 && $f->getSize() > $maxFileSize) continue;
    yield $f->getPathname();
  }
}

function rel(string $path, string $root): string {
  $p = str_replace('\\','/',$path);
  $r = str_replace('\\','/',$root);
  return ltrim(substr($p, strlen($r)), '/');
}

function load_text(string $file): string {
  $s = @file_get_contents($file);
  return is_string($s) ? $s : '';
}

/* ---------- critical: smarter resolution (strips ?query/#hash) ---------- */
function normalize_ref(string $found, string $sourceDir, string $projectRoot): array {
  $found = trim($found);
  if ($found === '' || $found[0] === '#' ||
      str_starts_with($found,'mailto:') ||
      str_starts_with($found,'data:') ||
      preg_match('~^[a-z]+://~i', $found)) {
    return ['kind'=>'ignore','path'=>$found];
  }

  // strip query/hash to resolve FS path
  $clean = preg_replace('/[?#].*$/', '', $found) ?? $found;

  // 1) relative to the source file directory
  $cand1 = realpath($sourceDir . DIRECTORY_SEPARATOR . $clean);
  if ($cand1 && is_file($cand1)) return ['kind'=>'relative','path'=>$cand1];

  // 2) project-root absolute (/partials/header.php)
  if (isset($clean[0]) && $clean[0] === '/') {
    $cand2 = realpath($projectRoot . $clean);
    if ($cand2 && is_file($cand2)) return ['kind'=>'abs-url','path'=>$cand2];
  }

  // 3) fallback: basename-only; resolve later via filename map
  return ['kind'=>'filename','path'=>$clean];
}

function reference_patterns(): array {
  $q = '[\'"]';
  $p = '(?P<path>[^\'"]+)';

  return [
    // PHP include/require with quotes or parentheses
    ['type'=>'php-include', 're'=>"/\\b(include|require)(_once)?\\s*\\(\\s*$q$p$q\\s*\\)/i"],
    ['type'=>'php-include', 're'=>"/\\b(include|require)(_once)?\\s*$q$p$q\\s*;/i"],

    // __DIR__ / dirname(__DIR__) concatenations — with or without parentheses
    ['type'=>'php-include', 're'=>"/\\b(include|require)(_once)?\\s*\\(\\s*__DIR__\\s*\\.\\s*$q$p$q\\s*\\)\\s*;/i"],
    ['type'=>'php-include', 're'=>"/\\b(include|require)(_once)?\\s+__DIR__\\s*\\.\\s*$q$p$q\\s*;/i"],
    ['type'=>'php-include', 're'=>"/\\b(include|require)(_once)?\\s*\\(\\s*dirname\\(\\s*__DIR__\\s*\\)\\s*\\.\\s*$q$p$q\\s*\\)\\s*;/i"],
    ['type'=>'php-include', 're'=>"/\\b(include|require)(_once)?\\s+dirname\\(\\s*__DIR__\\s*\\)\\s*\\.\\s*$q$p$q\\s*;/i"],

    // HTML/JS attributes
    ['type'=>'html-href',   're'=>"/\\bhref\\s*=\\s*$q$p$q/i"],
    ['type'=>'html-src',    're'=>"/\\bsrc\\s*=\\s*$q$p$q/i"],
    ['type'=>'html-action', 're'=>"/\\baction\\s*=\\s*$q$p$q/i"],

    // CSS @import
    ['type'=>'css-import',  're'=>"/@import\\s*$q$p$q\\s*;/i"],

    // JS/TS imports
    ['type'=>'js-import',   're'=>"/\\bimport\\s+[^;]*?\\s*from\\s*$q$p$q/i"],
    ['type'=>'js-import',   're'=>"/\\bimport\\s*$q$p$q/i"],

    // fetch/axios/jQuery
    ['type'=>'ajax-fetch',  're'=>"/\\bfetch\\s*\\(\\s*$q$p$q/i"],
    ['type'=>'ajax-axios',  're'=>"/\\baxios\\.(get|post|put|delete|patch)\\s*\\(\\s*$q$p$q/i"],
    ['type'=>'ajax-jquery', 're'=>"/\\$\\.(get|post|ajax)\\s*\\(\\s*$q$p$q/i"],

    // window navigation
    ['type'=>'js-nav',      're'=>"/\\b(location\\.href|window\\.open)\\s*=?\\s*\\(?\\s*$q$p$q/i"],

    // Conservative PHP string paths (e.g., 'api/ping_mssql.php?server=ts')
    ['type'=>'php-string',  're'=>"/$q(?P<path>(?![a-z]+:\\/\\/)[^\\s'\"\\(\\)<>]+\\.(?:php|js|css|html|htm|scss)(?:\\?[^'\"\\s]*)?)$q/i"],

    // Optional loose fallback (off unless requested)
    ['type'=>'loose-mention','re'=>"/\\b(?P<path>[\\w\\-\\/\\.]+\\.(php|js|css|html|htm|scss))\\b/i"],
  ];
}

/* ---------- choose best candidate for duplicate basenames ---------- */
function choose_best_candidates(array $candidates, string $srcDir, string $foundPath): array {
  if (count($candidates) <= 1) return $candidates;

  $srcDir = str_replace('\\','/',$srcDir);
  $found  = str_replace('\\','/',$foundPath);
  $foundTail = strtolower($found);

  $scores = [];
  foreach ($candidates as $abs) {
    $absN = str_replace('\\','/',$abs);
    $dirN = dirname($absN);

    // Score 1: common path with source dir (prefer nearby)
    $common = 0;
    $a = explode('/', trim($srcDir,'/'));
    $b = explode('/', trim($dirN,'/'));
    $m = min(count($a), count($b));
    for ($i=0; $i<$m; $i++) { if ($a[$i] !== $b[$i]) break; $common++; }
    $score = $common * 10;

    // Score 2: suffix match with written path
    $suffixLen = 0;
    if ($foundTail !== '') {
      $absTail = strtolower($absN);
      for ($k=min(strlen($foundTail), strlen($absTail)); $k>=1; $k--) {
        if (substr($absTail, -$k) === substr($foundTail, -$k)) { $suffixLen = $k; break; }
      }
      $score += $suffixLen / 10;
    }

    // Score 3: minor tiebreaker for shorter “distance”
    $relUp = substr_count(trim(str_replace($srcDir,'',$dirN),'/'), '/');
    $score += max(0, 5 - $relUp);

    $scores[$abs] = $score;
  }

  $max = max($scores);
  $best = array_keys(array_filter($scores, fn($v)=>$v===$max));
  return $best;
}

/* ------------------------- Collect target files ------------------------ */
$allTargets = iterator_to_array(iter_files($PROJECT_DIR, $TARGET_EXT, $EXCLUDE_DIRS, 0));
if ($NEEDLE !== '') {
  $allTargets = array_values(array_filter($allTargets, fn($f) =>
    stripos(basename($f), $NEEDLE) !== false || stripos(str_replace('\\','/', $f), $NEEDLE) !== false
  ));
}

$byFilename = [];
foreach ($allTargets as $t) {
  $bn = strtolower(basename($t));
  $byFilename[$bn][] = $t;
}

/* ------------------------- Scan source files --------------------------- */
$patterns = reference_patterns();
$usage    = [];
$initRec  = fn() => ['count'=>0, 'types'=>[], 'by'=>[]];

$sourceFiles = iterator_to_array(iter_files($PROJECT_DIR, $SOURCE_EXT, $EXCLUDE_DIRS, $MAX_FILE_SIZE));

foreach ($sourceFiles as $src) {
  $txt = load_text($src);
  if ($txt === '') continue;
  $srcDir = dirname($src);

  // prebuild line index
  $lineBreaks = [-1];
  $len = strlen($txt);
  for ($i=0; $i<$len; $i++) if ($txt[$i] === "\n") $lineBreaks[] = $i;
  $lineBreaks[] = $len;
  $findLine = function (int $pos) use ($lineBreaks): int {
    $lo=0; $hi=count($lineBreaks)-1;
    while ($lo < $hi) {
      $mid = intdiv($lo+$hi, 2)+1;
      if ($lineBreaks[$mid] >= $pos) $hi = $mid-1; else $lo = $mid;
    }
    return $lo + 1;
  };

  foreach ($patterns as $pat) {
    if (!preg_match_all($pat['re'], $txt, $m, PREG_OFFSET_CAPTURE)) continue;

    foreach ($m['path'] as $idx => $cap) {
      [$foundPath, $offset] = $cap;
      $type = $pat['type'];
      $line = $findLine($offset);

      // Skip loose here; handle in separate pass
      if ($type === 'loose-mention') continue;

      // Normalize / resolve (now strips query + tries srcDir first)
      $norm = normalize_ref($foundPath, $srcDir, $PROJECT_DIR);
      if ($norm['kind'] === 'ignore') continue;

      $resolvedTargets = [];
      if ($norm['kind'] === 'abs-url' || $norm['kind'] === 'relative') {
        $abs = $norm['path'];
        if (is_file($abs)) {
          $resolvedTargets[] = realpath($abs);
        } else {
          // fallback by basename, but disambiguate
          $bn = strtolower(basename(preg_replace('/[?#].*$/','',$foundPath)));
          if (!empty($byFilename[$bn])) {
            $resolvedTargets = choose_best_candidates($byFilename[$bn], $srcDir, $foundPath);
          }
        }
      } else { // filename-only
        $bn = strtolower(basename(preg_replace('/[?#].*$/','',$norm['path'])));
        if (!empty($byFilename[$bn])) {
          $resolvedTargets = choose_best_candidates($byFilename[$bn], $srcDir, $foundPath);
        }
      }

      foreach ($resolvedTargets as $tgt) {
        if (!isset($usage[$tgt])) $usage[$tgt] = $initRec();
        $usage[$tgt]['count']++;
        $usage[$tgt]['types'][$type] = ($usage[$tgt]['types'][$type] ?? 0) + 1;
        $usage[$tgt]['by'][] = [
          'file'  => $src,
          'line'  => $line,
          'type'  => $type,
          'match' => $foundPath,
        ];
      }
    }
  }

  /* ---------- Optional precise loose mentions ---------- */
  if ($DO_LOOSE && $LOOSE_MODE !== 'off') {
    foreach ($byFilename as $bn => $targets) {
      if (stripos($txt, $bn) === false) continue;

      if ($LOOSE_MODE === 'unique' && count($targets) !== 1) {
        continue; // skip ambiguous basenames
      }

      $chosen = [];
      if ($LOOSE_MODE === 'all') {
        $chosen = $targets; // legacy
      } elseif ($LOOSE_MODE === 'unique') {
        $chosen = $targets; // exactly 1
      } else { // near: pick best; if tie, skip
        $best = choose_best_candidates($targets, $srcDir, $bn);
        if (count($best) === 1) $chosen = $best;
      }

      foreach ($chosen as $tgt) {
        if (!isset($usage[$tgt])) $usage[$tgt] = $initRec();
        $usage[$tgt]['count']++;
        $usage[$tgt]['types']['loose-mention'] = ($usage[$tgt]['types']['loose-mention'] ?? 0) + 1;
        $usage[$tgt]['by'][] = [
          'file'  => $src,
          'line'  => 0,
          'type'  => 'loose-mention',
          'match' => $bn,
        ];
      }
    }
  }
}

/* ------------------------- Build results table ------------------------- */
$rows = [];
foreach ($allTargets as $t) {
  $relPath = rel($t, $PROJECT_DIR);
  $stat    = @stat($t) ?: [];
  $refs    = $usage[$t]['count'] ?? 0;
  $types   = $usage[$t]['types'] ?? [];
  ksort($types);
  $by      = $usage[$t]['by'] ?? [];

  if ($ONLY_UNUSED && $refs > 0) continue;

  $rows[] = [
    'file'      => $relPath,
    'abs'       => $t,
    'size'      => (int)($stat['size'] ?? 0),
    'mtime'     => (int)($stat['mtime'] ?? 0),
    'refs'      => $refs,
    'types'     => $types,
    'examples'  => array_slice($by, 0, 5),
  ];
}

// Sort: unused first, then by path
usort($rows, function($a,$b){
  if ($a['refs'] === $b['refs']) return strcmp($a['file'], $b['file']);
  return $a['refs'] <=> $b['refs'];
});

/* ------------------------------ Output -------------------------------- */
if ($FORMAT === 'json') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok'   => true,
    'root' => $PROJECT_DIR,
    'count'=> count($rows),
    'data' => $rows,
  ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

if ($FORMAT === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="file_usage.csv"');
  $out = fopen('php://output','w');
  fputcsv($out, ['File','Size','Modified','Refs','Types']);
  foreach ($rows as $r) {
    $types = [];
    foreach ($r['types'] as $k=>$v) $types[] = "$k:$v";
    fputcsv($out, [
      $r['file'],
      (string)$r['size'],
      date('Y-m-d H:i:s', $r['mtime']),
      (string)$r['refs'],
      implode(' | ', $types),
    ]);
  }
  fclose($out);
  exit;
}

// ---------- HTML output ----------
require_once PROJECT_FS . '/partials/header.php'; // e(), project_url() available
?>
<?php if (!empty($EXCLUDE_DIRS)): ?>
  <div class="mb-2 small text-secondary">
    <span class="me-2">Excluded folders:</span>
    <?php foreach ($EXCLUDE_DIRS as $ex): ?>
      <span class="badge bg-secondary me-1"><?= e($ex) ?></span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h4 mb-0">File Usage Check</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="?format=json<?= $ONLY_UNUSED ? '&unused=1':'' ?><?= $NEEDLE ? '&q='.rawurlencode($NEEDLE):'' ?>">JSON</a>
    <a class="btn btn-sm btn-outline-secondary" href="?format=csv<?= $ONLY_UNUSED ? '&unused=1':'' ?><?= $NEEDLE ? '&q='.rawurlencode($NEEDLE):'' ?>">CSV</a>
  </div>
</div>

<form method="get" class="row gy-2 gx-2 align-items-end mb-3">
  <div class="col-auto">
    <label class="form-label mb-1">Target extensions</label>
    <input class="form-control form-control-sm" name="ext" value="<?= e(implode(',', $TARGET_EXT)) ?>">
  </div>
  <div class="col-auto">
    <label class="form-label mb-1">Source extensions</label>
    <input class="form-control form-control-sm" name="src" value="<?= e(implode(',', $SOURCE_EXT)) ?>">
  </div>
  <div class="col-auto">
    <label class="form-label mb-1">Find filename</label>
    <input class="form-control form-control-sm" name="q" value="<?= e($NEEDLE) ?>" placeholder="config.php">
  </div>
  <div class="col-auto form-check mt-4 pt-2">
    <input class="form-check-input" type="checkbox" name="unused" value="1" id="chkUnused" <?= $ONLY_UNUSED ? 'checked':'' ?>>
    <label class="form-check-label" for="chkUnused">Only unused</label>
  </div>
  <div class="col-auto">
    <label class="form-label mb-1">Max file size (KB)</label>
    <input class="form-control form-control-sm" type="number" min="0" name="max_kb" value="<?= e((string)($QS['max_kb'] ?? 1500)) ?>">
  </div>
  <div class="col-auto">
    <label class="form-label mb-1">Loose filename mentions</label>
    <select class="form-select form-select-sm" name="loose">
      <option value="0" <?= (($QS['loose'] ?? '') !== '1') ? 'selected':'' ?>>No</option>
      <option value="1" <?= (($QS['loose'] ?? '') === '1') ? 'selected':'' ?>>Yes</option>
    </select>
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-primary">Scan</button>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-dark table-striped align-middle">
    <thead>
      <tr>
        <th style="width:45%">File</th>
        <th>Size</th>
        <th>Modified</th>
        <th>Refs</th>
        <th>Types</th>
        <th>Examples</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-center text-secondary">No files matched your filters.</td></tr>
      <?php else: foreach ($rows as $r):
        $isUnused = ($r['refs'] === 0);
        $badge = $isUnused ? '<span class="badge bg-danger">unused</span>' : '<span class="badge bg-success">used</span>';
        $typesStr = [];
        foreach ($r['types'] as $k=>$v) $typesStr[] = e("$k:$v");
        $typesStr = $typesStr ? implode('<br>', $typesStr) : '<span class="text-secondary">—</span>';
      ?>
      <tr>
        <td>
          <div class="d-flex flex-column">
            <div>
              <code><?= e($r['file']) ?></code>
              <span class="ms-2"><?= $badge ?></span>
            </div>
            <div class="small text-secondary">
              <a class="link-secondary" target="_blank" href="<?= e(project_url($r['file'])) ?>">open</a>
            </div>
          </div>
        </td>
        <td><?= number_format($r['size']) ?> B</td>
        <td><?= $r['mtime'] ? e(date('Y-m-d H:i:s', $r['mtime'])) : '—' ?></td>
        <td><span class="badge bg-info"><?= (int)$r['refs'] ?></span></td>
        <td><?= $typesStr ?></td>
        <td class="small">
          <?php if (!$r['examples']): ?>
            <span class="text-secondary">—</span>
          <?php else: ?>
            <ol class="mb-0 ps-3">
            <?php foreach ($r['examples'] as $ex): ?>
              <li>
                <code><?= e(rel($ex['file'], $PROJECT_DIR)) ?></code>
                <?= $ex['line'] ? ' line '.(int)$ex['line'] : '' ?>
                <span class="ms-1 badge bg-secondary"><?= e($ex['type']) ?></span>
              </li>
            <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="small text-secondary mt-3">
  <p class="mb-1">Tips:</p>
  <ul class="small">
    <li>Use <code>?unused=1</code> to list only zero-reference files (suspects).</li>
    <li>Add <code>&q=viewer</code> to narrow by filename substring.</li>
    <li>If you need extra sensitivity for dynamic paths, try <code>&loose=1</code> plus <code>&loose_mode=near</code>.</li>
  </ul>
</div>

<?php @require_once PROJECT_FS . '/partials/footer.php'; ?>
