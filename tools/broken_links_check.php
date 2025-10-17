<?php
declare(strict_types=1);

/**
 * /tools/broken_links_check.php
 * Scans PHP files and reports broken include/require references and broken asset links.
 * - Excludes folders listed in tools/file_usage_excludes.ini (relative to project root)
 * - Robust: uses tokenizer for real PHP include/require; regex for HTML assets
 * - Understands: literal strings, __DIR__, __FILE__, dirname(__DIR__/__FILE__, N),
 *   realpath(<expr>), $projectDir defined in same file, simple path constants (define/const),
 *   simple variables defined in the same file, and globally defined constants (e.g., PROJECT_FS).
 */

// Bootstrap + core includes
if (!defined('PROJECT_FS')) {
  require_once __DIR__ . '/../partials/bootstrap.php';
}
require_once PROJECT_FS . '/appcfg.php';
require_once PROJECT_FS . '/db.php';
require_once PROJECT_FS . '/partials/header.php';

/* ---------- config ---------- */
$projectRoot = PROJECT_FS;                  // project root (canonical)
$globs       = ['php'];
$maxFileSize = 2_000_000;                   // 2 MB guard

// Which file extensions count as "asset files" for HTML tags
$assetExts = [
  'js','css','map','json','txt',
  'png','jpg','jpeg','gif','svg','webp','ico',
  'woff','woff2','ttf','otf','eot',
  'mp4','webm','ogg','mp3','wav','m4a'
];

// Load extra excludes from INI (relative paths, forward slashes)
$excludeFile = PROJECT_FS . '/tools/file_usage_excludes.ini';
$excludeDirs = [];
if (is_file($excludeFile)) {
  foreach (file($excludeFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, ';')) continue;
    $line = ltrim(str_replace('\\', '/', $line), './'); // normalize
    $line = rtrim($line, '/');
    if ($line !== '') $excludeDirs[] = $line;
  }
} else {
  $excludeDirs = ['vendor','node_modules','.git','storage','logs','assets/cache'];
}

/* ---------- helpers ---------- */
// 'e()' comes from web_helpers.php via header.php (do not redefine here)
function norm(string $p): string { return str_replace(['\\','//'], ['/', '/'], $p); }

/** Collapse dot-segments in a POSIX-like path (without hitting the FS). */
function collapseDots(string $path): string {
  $path = norm($path);
  $isAbs = preg_match('~^[a-zA-Z]:/|^/~', $path) === 1;
  $parts = explode('/', $path);
  $stack = [];
  foreach ($parts as $i => $seg) {
    if ($seg === '' || $seg === '.') {
      if ($i === 0 && $isAbs) { $stack[] = ''; } // keep drive or leading slash
      continue;
    }
    if ($seg === '..') {
      if (!empty($stack) && end($stack) !== '..' && end($stack) !== '') {
        array_pop($stack);
      } else {
        if (!$isAbs) $stack[] = '..';
      }
      continue;
    }
    $stack[] = $seg;
  }
  $collapsed = implode('/', $stack);
  if ($isAbs && $collapsed === '') $collapsed = '/';
  return $collapsed;
}

/** True when $relPath (normalized, project-relative) is inside any excluded dir. */
function is_excluded_rel(string $relPath, array $excludes): bool {
  $relPath = ltrim(norm($relPath), '/');
  foreach ($excludes as $ex) {
    $ex = ltrim(norm($ex), '/');
    if ($ex === '') continue;
    if ($relPath === $ex) return true;
    if (str_starts_with($relPath, $ex . '/')) return true;
    if (str_contains($relPath, '/' . $ex . '/')) return true;
  }
  return false;
}

// FIX 1: use the function param $maxSize (not $maxFileSize)
function listPhpFiles(string $root, array $exts, array $excludeDirs, int $maxSize): array {
  $out = [];
  $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
  foreach ($rii as $f) {
    /** @var SplFileInfo $f */
    if (!$f->isFile()) continue;
    if ($f->getSize() > $maxSize) continue; // <-- fixed here

    $abs = $f->getPathname();
    $rel = substr($abs, strlen($root) + 1);
    $relNorm = norm($rel);

    if (is_excluded_rel($relNorm, $excludeDirs)) continue;

    $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
    if (!in_array($ext, $exts, true)) continue;
    $out[] = $abs;
  }
  return $out;
}


/** Detect $projectDir base if declared in same file (common patterns). */
function detectProjectDirBase(string $code, string $fileDir): ?string {
  if (!preg_match('~\$projectDir\s*=\s*([^;]+);~', $code, $m)) return null;
  $rhs = trim($m[1]);
  if ($rhs === '__DIR__' || $rhs === '__FILE__') return $fileDir;
  if (preg_match('~dirname\(\s*(?:__DIR__|__FILE__)\s*(?:,\s*(\d+)\s*)?\)~', $rhs, $mm)) {
    $levels = isset($mm[1]) ? max(1, (int)$mm[1]) : 1;
    $base = $fileDir;
    for ($i = 0; $i < $levels; $i++) $base = dirname($base);
    return $base;
  }
  return null;
}

/** Split on dots that are NOT inside quotes or parentheses. */
function safeSplitConcat(string $expr): array {
  $tokens = [];
  $buf = '';
  $inQuote = false; // false | "'" | '"'
  $escape = false;
  $parenDepth = 0;
  $len = strlen($expr);

  for ($i = 0; $i < $len; $i++) {
    $ch = $expr[$i];

    if ($inQuote !== false) {
      if ($escape) { $buf .= $ch; $escape = false; continue; }
      if ($ch === '\\') { $buf .= $ch; $escape = true; continue; }
      if ($ch === $inQuote) { $buf .= $ch; $inQuote = false; continue; }
      $buf .= $ch; continue;
    }

    if ($ch === '\'' || $ch === '"') { $inQuote = $ch; $buf .= $ch; continue; }
    if ($ch === '(') { $parenDepth++; $buf .= $ch; continue; }
    if ($ch === ')') { if ($parenDepth > 0) $parenDepth--; $buf .= $ch; continue; }

    if ($ch === '.' && $parenDepth === 0) { $tokens[] = trim($buf); $buf = ''; continue; }
    $buf .= $ch;
  }
  if ($buf !== '') $tokens[] = trim($buf);

  // strip a single outer parentheses pair
  $tokens = array_map(function ($t) {
    $t = trim($t);
    if ($t !== '' && $t[0] === '(' && substr($t, -1) === ')') $t = trim(substr($t, 1, -1));
    return $t;
  }, $tokens);

  return $tokens;
}

/** detect simple path constants: define('NAME', EXPR) / const NAME = EXPR; */
function detectPathConstants(string $code, string $fileDir): array {
  $consts = [];

  if (preg_match_all('~\bdefine\(\s*([\'"])([A-Z_][A-Z0-9_]*)\1\s*,\s*(.+?)\s*\)\s*;~s', $code, $m, PREG_SET_ORDER)) {
    foreach ($m as $hit) {
      $name = $hit[2];
      $expr = trim($hit[3]);
      $consts[$name] = evaluatePathExpr($expr, $fileDir, null, [], []);
    }
  }

  if (preg_match_all('~\bconst\s+([A-Z_][A-Z0-9_]*)\s*=\s*(.+?)\s*;~s', $code, $m2, PREG_SET_ORDER)) {
    foreach ($m2 as $hit) {
      $name = $hit[1];
      $expr = trim($hit[2]);
      $consts[$name] = evaluatePathExpr($expr, $fileDir, null, [], []);
    }
  }

  return array_filter($consts, fn($v) => is_string($v) && $v !== '');
}

/** Collect globally defined path constants (from bootstrap). */
function globalPathConsts(): array {
  $out = [];
  foreach (['PROJECT_FS','DOCROOT_FS','DIRECTORY_SEPARATOR'] as $name) {
    if (defined($name)) {
      $val = constant($name);
      if (is_string($val)) $out[$name] = $val;
    }
  }
  return $out;
}

/**
 * Detect simple path variables: $NAME = EXPR;
 * We only keep variables whose EXPR we can evaluate with our evaluator.
 * Supports leading underscore in variable names (e.g., $__auto).
 */
function detectPathVars(string $code, string $fileDir, ?string $projectDirBase, array $pathConsts): array {
  $vars = [];
  if (preg_match_all('~\$\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.+?)\s*;~s', $code, $m, PREG_SET_ORDER)) {
    // resolve in up to 2 passes for simple dependencies between vars
    for ($pass = 0; $pass < 2; $pass++) {
      $changed = false;
      foreach ($m as $hit) {
        $name = $hit[1];
        if (isset($vars[$name])) continue; // already resolved
        $expr = trim($hit[2]);
        $val  = evaluatePathExpr($expr, $fileDir, $projectDirBase, $pathConsts, $vars);
        if ($val !== '') { $vars[$name] = $val; $changed = true; }
      }
      if (!$changed) break;
    }
  }
  return $vars;
}

/**
 * Evaluate a simple path expression to a string (best-effort).
 */
function evaluatePathExpr(
  string $expr,
  string $fileDir,
  ?string $projectDirBase,
  array $pathConsts,
  array $pathVars
): string {
  $expr = trim($expr);

  // unwrap realpath(...)
  if (preg_match('~^realpath\(\s*(.+)\s*\)$~s', $expr, $wr)) {
    $expr = trim($wr[1]);
  }

  // pure literal
  if (preg_match('~^([\'"])(.*)\1$~s', $expr, $m)) {
    $lit = $m[2];
    if (preg_match('~^[a-zA-Z]:[\\/]|^/~', $lit)) return norm($lit);
    $built = $fileDir . '/' . $lit;
    return collapseDots($built);
  }

  // concatenations by dot (safe split)
  $tokens = safeSplitConcat($expr);
  if (!$tokens) return '';

  $built = '';
  foreach ($tokens as $tokRaw) {
    $tok = trim($tokRaw);

    if ($tok === '__DIR__' || $tok === '__FILE__') { $built .= $fileDir; continue; }

    if (preg_match('~^dirname\(\s*(?:__DIR__|__FILE__)\s*(?:,\s*(\d+)\s*)?\)$~', $tok, $mm)) {
      $levels = isset($mm[1]) ? max(1, (int)$mm[1]) : 1;
      $d = $fileDir;
      for ($i = 0; $i < $levels; $i++) $d = dirname($d);
      $built .= $d; continue;
    }

    if ($tok === '$projectDir' && $projectDirBase) { $built .= $projectDirBase; continue; }

    if (preg_match('~^([\'"])(.*)\1$~s', $tok, $m2)) { $built .= $m2[2]; continue; }

    // CONST_NAME: prefer per-file map, else fall back to globally defined constants
    if (preg_match('~^[A-Z_][A-Z0-9_]*$~', $tok)) {
      if (isset($pathConsts[$tok]) && $pathConsts[$tok] !== '') {
        $built .= (string)$pathConsts[$tok];
        continue;
      }
      if (defined($tok)) {
        $cv = constant($tok);
        if (is_string($cv) || is_numeric($cv)) {
          $built .= (string)$cv;
          continue;
        }
      }
    }

    // $varName (allow leading underscore)
    if (preg_match('~^\$\s*([A-Za-z_][A-Za-z0-9_]*)$~', $tok, $mv) && isset($pathVars[$mv[1]])) {
      $built .= (string)$pathVars[$mv[1]];
      continue;
    }

    // unknown token => give up (dynamic/unsupported)
    return '';
  }

  if ($built !== '') {
    $collapsed = collapseDots($built);
    $rp = @realpath($collapsed);
    return $rp ?: $collapsed;
  }
  return '';
}

/** Does the expression obviously contain dynamic array indexing like $map[$x]? */
function isDynamicIndexExpr(string $expr): bool {
  if (!str_contains($expr, '[')) return false;
  return (bool)preg_match('~\$\w+\s*\[.+\]~s', $expr);
}

/**
 * Resolve include/require arg -> [path|null, note, status]
 * status: 'OK' | 'MISSING' | 'DYNAMIC'
 */
function resolveArgWithStatus(string $arg, string $filePath, ?string $projectDirBase, array $pathConsts, array $pathVars): array {
  $fileDir = dirname($filePath);
  $expr    = trim($arg);

  if (isDynamicIndexExpr($expr)) {
    return [null, 'dynamic-index:' . $expr, 'DYNAMIC'];
  }

  // If it's a single bare variable and we don't know its value, treat as runtime/dynamic.
  if (preg_match('~^\$\s*([A-Za-z_][A-Za-z0-9_]*)$~', $expr, $mv)) {
    $name = $mv[1];
    if (!isset($pathVars[$name])) {
      return [null, 'unknown-var:$' . $name, 'DYNAMIC'];
    }
  }

  $evaluated = evaluatePathExpr($expr, $fileDir, $projectDirBase, $pathConsts, $pathVars);
  if ($evaluated !== '') {
    $exists = is_file($evaluated);
    return [$evaluated, 'expr-eval', $exists ? 'OK' : 'MISSING'];
  }

  return [null, 'unresolved: ' . $expr, 'MISSING'];
}

/* ---------- HTML asset scanning ---------- */

function isExternalUrl(string $u): bool {
  $u = trim($u);
  if ($u === '') return false;
  if (str_starts_with($u, '//')) return true;
  return (bool)preg_match('~^[a-zA-Z][a-zA-Z0-9+\-.]*:~', $u); // scheme:
}

function urlPathOnly(string $u): string {
  $u = trim($u);
  $hashPos = strpos($u, '#');
  if ($hashPos !== false) $u = substr($u, 0, $hashPos);
  $qPos = strpos($u, '?');
  if ($qPos !== false) $u = substr($u, 0, $qPos);
  return $u;
}

function resolveAssetPath(string $rawUrl, string $fileDir, string $projectRoot): array {
  $orig = $rawUrl;
  $url = urlPathOnly($rawUrl);

  // dynamic inside path? (PHP tag or ${var} within the path part)
  if (str_contains($url, '<?') || str_contains($url, '?>') || preg_match('~\$\{?.+?\}?~', $url)) {
    return [null, 'asset-dynamic', 'DYNAMIC', $orig];
  }

  if ($url === '' || isExternalUrl($url)) {
    return [null, 'asset-external-or-empty', 'DYNAMIC', $orig];
  }

  // only validate typical asset filetypes to avoid false positives
  $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
  $assetExts = $GLOBALS['assetExts'] ?? [];
  if ($ext === '' || ($assetExts && !in_array($ext, $assetExts, true))) {
    return [null, 'asset-nonstatic', 'DYNAMIC', $orig];
  }

  if (str_starts_with($url, '/')) {
    $fs = collapseDots($projectRoot . $url);
  } else {
    $fs = collapseDots($fileDir . '/' . $url);
  }
  $rp = @realpath($fs) ?: $fs;
  $exists = is_file($rp);

  return [$rp, 'asset-html', $exists ? 'OK' : 'MISSING', $orig];
}

function scanFileAssets(string $filePath, string $projectRoot): array {
  $code = file_get_contents($filePath);
  if ($code === false) return [];

  $fileDir = dirname($filePath);
  $results = [];

  // Regex for <script src="..."> and <img src="...">
  $reSrc  = '~<(script|img)\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\2~i';
  // Regex for <link href="..."> (typically CSS)
  $reHref = '~<link\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~i';

  $scan = function(string $html, string $regex, string $kind) use ($filePath, $fileDir, $projectRoot): array {
    $out = [];
    if (preg_match_all($regex, $html, $m, PREG_OFFSET_CAPTURE)) {
      foreach ($m[0] as $i => $whole) {
        $pos = $whole[1];
        $url = ($kind === 'href') ? $m[2][$i][0] : $m[3][$i][0];
        $line = substr_count(substr($html, 0, $pos), "\n") + 1;

        [$resolved, $note, $status, $orig] = resolveAssetPath($url, $fileDir, $projectRoot);

        $out[] = [
          'file'     => $filePath,
          'line'     => $line,
          'kw'       => 'asset:' . $kind,
          'arg'      => $orig,
          'note'     => $note,
          'resolved' => $resolved,
          'exists'   => ($status === 'OK'),
          'status'   => $status,
        ];
      }
    }
    return $out;
  };

  $results = array_merge(
    $results,
    $scan($code, $reSrc,  'src'),
    $scan($code, $reHref, 'href')
  );

  return $results;
}

/* ---------- include/require scanning (tokenizer) ---------- */

function resolveArgWithStatusWrapper(string $arg, string $filePath, ?string $projectDirBase, array $pathConsts, array $pathVars): array {
  return resolveArgWithStatus($arg, $filePath, $projectDirBase, $pathConsts, $pathVars);
}

function scanFileIncludes(string $filePath): array {
  $code = file_get_contents($filePath);
  if ($code === false) return [];

  $fileDir         = dirname($filePath);
  $projectDirBase  = detectProjectDirBase($code, $fileDir);
  // Seed per-file constants with global ones (PROJECT_FS, DOCROOT_FS, …)
  $pathConsts      = array_merge(globalPathConsts(), detectPathConstants($code, $fileDir));
  $pathVars        = detectPathVars($code, $fileDir, $projectDirBase, $pathConsts);

  $results = [];
  $tokens = token_get_all($code);
  $N = count($tokens);

  $tokStr = static function($t): string { return is_array($t) ? $t[1] : $t; };

  for ($i = 0; $i < $N; $i++) {
    $t = $tokens[$i];
    if (!is_array($t)) continue;

    $id = $t[0];
    $isIncludeRequire =
      $id === T_REQUIRE || $id === T_REQUIRE_ONCE ||
      $id === T_INCLUDE || $id === T_INCLUDE_ONCE;

    if (!$isIncludeRequire) continue;

    $kw   = trim($t[1]);
    $line = (int)$t[2];

    // Move to first significant token after keyword
    $j = $i + 1;
    while ($j < $N && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $j++;

    $argBuf     = '';
    $parenDepth = 0;
    $brackDepth = 0;
    $braceDepth = 0;
    $inStr = false; $strDelim = '';
    $escape = false;

    $usedParens = ($j < $N && $tokens[$j] === '(');
    if ($usedParens) { $parenDepth = 1; $j++; }

    for (; $j < $N; $j++) {
      $tk = $tokens[$j];
      $ch = $tokStr($tk);

      if ($inStr) {
        $argBuf .= $ch;
        if ($escape) { $escape = false; continue; }
        if ($ch === '\\') { $argBuf .= ''; $escape = true; continue; }
        if ($ch === $strDelim) { $inStr = false; $strDelim = ''; }
        continue;
      }

      if ($ch === '\'' || $ch === '"') { $inStr = true; $strDelim = $ch; $argBuf .= $ch; continue; }

      if ($ch === '(') { $parenDepth++; $argBuf .= $ch; continue; }
	  if ($ch === ')') {
	    if ($parenDepth > 0) { $parenDepth--; $argBuf .= $ch; } // <-- . = (not +=)
   	    if ($usedParens && $parenDepth === 0) { $j++; break; }
	    continue;
	  }
      if ($ch === '[') { $brackDepth++; $argBuf .= $ch; continue; }
      if ($ch === ']') { if ($brackDepth > 0) $brackDepth--; $argBuf .= $ch; continue; }
      if ($ch === '{') { $braceDepth++; $argBuf .= $ch; continue; }
      if ($ch === '}') { if ($braceDepth > 0) $braceDepth--; $argBuf .= $ch; continue; }

      if (!$usedParens && $parenDepth === 0 && $brackDepth === 0 && $braceDepth === 0) {
        if ($ch === ';' || $ch === ':' || $ch === ',' || $ch === ')' || $ch === ']' || $ch === '}') {
          break;
        }
      }

      $argBuf .= $ch;
    }

    $arg = trim($argBuf);

    [$resolved, $note, $status] = resolveArgWithStatusWrapper($arg, $filePath, $projectDirBase, $pathConsts, $pathVars);

    $results[] = [
      'file'     => $filePath,
      'line'     => $line,
      'kw'       => $kw,
      'arg'      => $arg,
      'note'     => $note,
      'resolved' => $resolved,
      'exists'   => ($status === 'OK'),
      'status'   => $status,
    ];
  }

  return $results;
}

/* ---------- run ---------- */
$files = listPhpFiles($projectRoot, $globs, $excludeDirs, $maxFileSize);

$rows  = [];
foreach ($files as $f) {
  $rows = array_merge($rows, scanFileIncludes($f), scanFileAssets($f, $projectRoot));
}

usort($rows, fn($a, $b) =>
  [($a['status'] ?? 'MISSING') === 'OK' ? 1 : 0, $a['file'], $a['line']]
  <=>
  [($b['status'] ?? 'MISSING') === 'OK' ? 1 : 0, $b['file'], $b['line']]
);

$total         = count($rows);
$missing       = array_values(array_filter($rows, fn($r) => ($r['status'] ?? 'MISSING') === 'MISSING'));
$dynamic       = array_values(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'DYNAMIC'));
$missingCount  = count($missing);
$dynamicCount  = count($dynamic);

// build a short excludes string for the header
$exLabel = $excludeDirs
  ? implode(', ', array_map(fn($x) => '<code>' . e($x) . '</code>', $excludeDirs))
  : '<em>none</em>';
?>
<div class="container py-3">
  <h2 class="mb-2">Broken include/require & asset checker</h2>
  <p class="text-muted small mb-2">
    Root: <code><?= e($projectRoot) ?></code> • Files scanned: <?= count($files) ?> • References: <?= $total ?>
    • Excludes (from <code>tools/file_usage_excludes.ini</code>): <?= $exLabel ?>
    <?php if ($missingCount): ?>
      • <span class="badge bg-danger"><?= $missingCount ?> missing</span>
    <?php else: ?>
      • <span class="badge bg-success">No missing</span>
    <?php endif; ?>
    <?php if ($dynamicCount): ?>
      • <span class="badge bg-secondary"><?= $dynamicCount ?> dynamic</span>
    <?php endif; ?>
  </p>

  <?php if ($missingCount): ?>
    <h5 class="mt-3">Missing targets</h5>
    <div class="table-responsive mb-3">
      <table class="table table-dark table-striped table-sm align-middle">
        <thead>
          <tr><th>File</th><th>Line</th><th>Statement</th><th>Resolved</th><th>Status</th><th>Notes</th></tr>
        </thead>
        <tbody>
          <?php foreach ($missing as $r): ?>
            <tr>
              <td><?= e(str_replace($projectRoot . '/', '', norm($r['file']))) ?></td>
              <td><?= (int)$r['line'] ?></td>
              <td><code><?= e($r['kw']) ?>(<?= e($r['arg']) ?>)</code></td>
              <td><code><?= e((string)($r['resolved'] ?? '')) ?></code></td>
              <td><span class="badge bg-danger">MISSING</span></td>
              <td class="text-muted small"><?= e($r['note'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($dynamicCount): ?>
    <h5 class="mt-3">Dynamic / runtime-resolved targets</h5>
    <div class="table-responsive mb-3">
      <table class="table table-dark table-striped table-sm align-middle">
        <thead>
          <tr><th>File</th><th>Line</th><th>Statement</th><th>Resolved</th><th>Status</th><th>Notes</th></tr>
        </thead>
        <tbody>
          <?php foreach ($dynamic as $r): ?>
            <tr>
              <td><?= e(str_replace($projectRoot . '/', '', norm($r['file']))) ?></td>
              <td><?= (int)$r['line'] ?></td>
              <td><code><?= e($r['kw']) ?>(<?= e($r['arg']) ?>)</code></td>
              <td><code><?= e((string)($r['resolved'] ?? '')) ?></code></td>
              <td><span class="badge bg-secondary">DYNAMIC</span></td>
              <td class="text-muted small"><?= e($r['note'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <h5>All references</h5>
  <div class="table-responsive">
    <table class="table table-dark table-striped table-sm align-middle">
      <thead><tr><th>File</th><th>Line</th><th>Statement</th><th>Resolved</th><th>Status</th><th>Notes</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e(str_replace($projectRoot . '/', '', norm($r['file']))) ?></td>
            <td><?= (int)$r['line'] ?></td>
            <td><code><?= e($r['kw']) ?>(<?= e($r['arg']) ?>)</code></td>
            <td><code><?= e((string)($r['resolved'] ?? '')) ?></code></td>
            <td>
              <?php if (($r['status'] ?? '') === 'OK'): ?>
                <span class="badge bg-success">OK</span>
              <?php elseif (($r['status'] ?? '') === 'DYNAMIC'): ?>
                <span class="badge bg-secondary">DYNAMIC</span>
              <?php else: ?>
                <span class="badge bg-danger">MISSING</span>
              <?php endif; ?>
            </td>
            <td class="text-muted small"><?= e($r['note'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once PROJECT_FS . '/partials/footer.php'; ?>
