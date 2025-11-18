<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors','1');

/**
 * Local Constructor — Structure Browser (folders-first + search)
 * UI:     /tools/local_constructor_structure.php
 * Export: ?export=csv | ?export=txt
 *
 * Notes:
 *  - Boots via /partials/bootstrap.php to get PROJECT_FS/BASE_URL.
 *  - Excludes can be extended via tools/file_usage_excludes.ini
 */

// Ensure bootstrap (defines PROJECT_FS/BASE_URL/helpers)
if (!defined('PROJECT_FS')) {
  require_once __DIR__ . '/../partials/bootstrap.php';
}

$projectRoot = PROJECT_FS; // canonical project root
$rootDir     = $projectRoot; // scan from project root even when tool lives in /tools
$maxDepth    = 20;

// base excludes + optional INI
$exclude = ['.git','vendor','node_modules','storage','logs','.idea','.vscode'];
$excludeIni = PROJECT_FS . '/tools/file_usage_excludes.ini';
if (is_file($excludeIni)) {
  $lines = @file($excludeIni, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === ';' || $line[0] === '#') continue;
    $line = str_replace('\\','/',$line);
    $part = basename($line); // treat each entry as a top-level name to skip
    if ($part !== '' && !in_array($part, $exclude, true)) $exclude[] = $part;
  }
}

/* ----------- helpers ----------- */
function collectFiles(string $dir, array $exclude, int $depth = 0, int $maxDepth = 20): array {
  $files = [];
  if ($depth > $maxDepth) return $files;
  $items = @scandir($dir);
  if ($items === false) return $files;
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    if (in_array($item, $exclude, true)) continue;
    $path = $dir . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path)) {
      $files = array_merge($files, collectFiles($path, $exclude, $depth + 1, $maxDepth));
    } else {
      $files[] = $path;
    }
  }
  return $files;
}

function scanDirTree(string $dir, array $exclude, int $depth = 0, int $maxDepth = 20): array {
  if ($depth > $maxDepth) return [];
  $dirs = [];
  $files = [];

  $items = @scandir($dir);
  if ($items === false) return [];

  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    if (in_array($item, $exclude, true)) continue;
    $path = $dir . DIRECTORY_SEPARATOR . $item;
    if (is_dir($path)) {
      $dirs[] = $item;
    } else {
      $files[] = $item;
    }
  }

  natcasesort($dirs);
  natcasesort($files);

  $out = [];

  foreach ($dirs as $d) {
    $path = $dir . DIRECTORY_SEPARATOR . $d;
    $out[] = [
      'type' => 'dir',
      'name' => $d,
      'path' => $path,
      'children' => scanDirTree($path, $exclude, $depth + 1, $maxDepth),
    ];
  }
  foreach ($files as $f) {
    $path = $dir . DIRECTORY_SEPARATOR . $f;
    $out[] = [
      'type' => 'file',
      'name' => $f,
      'path' => $path,
    ];
  }

  return $out;
}

/* ----------- export endpoints ----------- */
if (isset($_GET['export'])) {
  $fmt = strtolower((string)$_GET['export']);
  $all = collectFiles($rootDir, $exclude, 0, $maxDepth);
  $rows = [];
  foreach ($all as $abs) {
    $rel = ltrim(str_replace('\\','/', str_replace($rootDir, '', $abs)), '/');
    // Recommend includes using PROJECT_FS (canonical for this codebase)
    $rows[] = [$rel, "PROJECT_FS . '/".$rel."'"];
  }
  if ($fmt === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename=local_constructor_structure.txt');
    foreach ($rows as [, $p]) echo $p.PHP_EOL;
    exit;
  }
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=local_constructor_structure.csv');
  $out = fopen('php://output','w');
  fputcsv($out, ['Relative Path','PHP Include Path (PROJECT_FS)']);
  foreach ($rows as $r) fputcsv($out, $r);
  fclose($out);
  exit;
}

/* ----------- data ----------- */
$tree = scanDirTree($rootDir, $exclude, 0, $maxDepth);

/* ----------- view helper ----------- */
function renderTree(array $tree, string $rootDir): void {
  foreach ($tree as $node) {
    $isDir = $node['type'] === 'dir';
    $abs   = $node['path'];
    $rel   = ltrim(str_replace('\\','/', str_replace($rootDir, '', $abs)), '/');
    $key   = strtolower(($node['name'] ?? '') . ' ' . $rel); // for search
    $safeName = htmlspecialchars($node['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    if ($isDir) {
      echo '<details class="mb-1 dir" data-type="dir" data-key="'.htmlspecialchars($key,ENT_QUOTES).'">';
      echo '  <summary class="d-flex align-items-center gap-2">';
      echo '    <span class="caret text-muted">▶</span>';
      echo '    <span class="text-warning-emphasis">📁</span>';
      echo '    <span class="fw-semibold">'.$safeName.'</span>';
      echo '    <small class="text-muted">(' . htmlspecialchars($rel ?: '.', ENT_QUOTES) . ')</small>';
      echo '  </summary>';
      if (!empty($node['children'])) {
        echo '  <div class="ms-4 mt-1">';
        renderTree($node['children'], $rootDir);
        echo '  </div>';
      }
      echo '</details>';
    } else {
      // Quick-copy: include-from-project-root (PROJECT_FS) and absolute FS path
      $phpIncludeFromRoot = "PROJECT_FS . '/".$rel."'";
      echo '<div class="d-flex align-items-center gap-2 py-1 file" data-type="file" data-key="'.htmlspecialchars($key,ENT_QUOTES).'">';
      echo '  <span>📄</span>';
      echo '  <span>'.$safeName.'</span>';
      echo '  <small class="text-muted">(' . htmlspecialchars($rel, ENT_QUOTES) . ')</small>';
      echo '  <span role="button" class="badge text-bg-secondary" data-copy="'.htmlspecialchars($phpIncludeFromRoot,ENT_QUOTES).'" title="Copy PHP include path (PROJECT_FS)">PROJECT_FS</span>';
      echo '  <span role="button" class="badge text-bg-secondary" data-copy="'.htmlspecialchars($abs,ENT_QUOTES).'" title="Copy absolute path">FS</span>';
      echo '</div>';
    }
  }
}

/* ----------- header ----------- */
require_once PROJECT_FS . '/partials/header.php';
?>
<style>
  details > summary::-webkit-details-marker { display:none; }
  .caret { display:inline-block; width:12px; transform: translateY(-1px); }
  details[open] > summary .caret { transform: rotate(90deg) translateX(-1px); }
</style>

<div class="container-fluid py-3">
  <div class="d-flex align-items-center gap-2 mb-2">
    <h2 class="h5 m-0">📂 Local Constructor Structure</h2>
    <small class="text-muted">Root: <?= htmlspecialchars($rootDir, ENT_QUOTES) ?></small>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-sm btn-primary" href="?export=csv" download>Export CSV</a>
      <a class="btn btn-sm btn-outline-secondary" href="?export=txt" download>Export TXT</a>
    </div>
  </div>

  <div class="mb-3">
    <input id="search" type="search" class="form-control form-control-sm" placeholder="Search files or folders (name or relative path)…">
  </div>

  <div id="tree" class="mt-2">
    <?php renderTree($tree, $rootDir); ?>
  </div>
</div>

<script>
  // Copy badges
  document.getElementById('tree').addEventListener('click', async (e) => {
    const badge = e.target.closest('.badge[data-copy]');
    if (!badge) return;
    const text = badge.getAttribute('data-copy') || '';
    try {
      await navigator.clipboard.writeText(text);
      const old = badge.textContent;
      badge.textContent = '✓';
      badge.classList.remove('text-bg-secondary');
      badge.classList.add('text-bg-success');
      setTimeout(() => {
        badge.textContent = old;
        badge.classList.remove('text-bg-success');
        badge.classList.add('text-bg-secondary');
      }, 800);
    } catch (err) {
      alert('Copy failed: ' + (err?.message || err));
    }
  });

  // Search (folders auto-expand when matching)
  const searchInput = document.getElementById('search');
  const treeRoot = document.getElementById('tree');

  const debounce = (fn, ms=150) => {
    let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
  };

  const applyFilter = () => {
    const q = (searchInput.value || '').trim().toLowerCase();

    const files = Array.from(treeRoot.querySelectorAll('.file'));
    const dirs  = Array.from(treeRoot.querySelectorAll('details.dir'));

    if (!q) {
      files.forEach(f => { f.style.display = ''; f.dataset.m = ''; });
      dirs.forEach(d => { d.style.display = ''; d.open = false; d.dataset.m=''; });
      return;
    }

    files.forEach(f => {
      const key = f.dataset.key || '';
      const match = key.includes(q);
      f.dataset.m = match ? '1' : '0';
      f.style.display = match ? '' : 'none';
    });

    dirs.slice().reverse().forEach(d => {
      const own = (d.dataset.key || '').includes(q);
      const childHasMatch = d.querySelector('.file[data-m="1"], details.dir[data-m="1"]') !== null;
      const match = own || childHasMatch;
      d.dataset.m = match ? '1' : '0';
      d.style.display = match ? '' : 'none';
      d.open = match;
    });
  };

  searchInput.addEventListener('input', debounce(applyFilter, 150));
</script>

<?php require_once PROJECT_FS . '/partials/footer.php'; ?>
