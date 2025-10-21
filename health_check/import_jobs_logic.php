<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/bootstrap.php';

/* Load function modules (functions are not autoloaded) */
require_once __DIR__ . '/http.php';      // hc_require_post(), hc_action(), hc_json_ok(), hc_json_error()
require_once __DIR__ . '/helpers.php';   // hc_jobs_ensure_tables(), hc_import_root(), etc.

/* Autoload HC API classes from controllers/api */
$__HC_API_DIR = __DIR__;
spl_autoload_register(static function (string $class) use ($__HC_API_DIR) {
  $map = [
    'HcRouter'           => $__HC_API_DIR . '/Router.php',
    'JobsController'     => $__HC_API_DIR . '/JobsController.php',
    'UploadsController'  => $__HC_API_DIR . '/UploadsController.php',
  ];
  if (isset($map[$class]) && is_file($map[$class])) {
    require_once $map[$class];
  }
});

/* Sanity: classes must be loadable */
foreach (['HcRouter','JobsController','UploadsController'] as $c) {
  if (!class_exists($c, true)) {
    http_response_code(500);
    echo json_encode([
      'ok'    => false,
      'error' => "$c module could not be loaded",
      'hint'  => "Expected file: " . $__HC_API_DIR . '/' . ($c === 'HcRouter' ? 'Router' : $c) . '.php'
    ]);
    exit;
  }
}

/* Parse input and action */
$in     = hc_require_post();
$action = hc_action($in);

/* Legacy alias support */
if ($action === 'job_list') $action = 'jobs_list';

/* ---------- Server-side file picker (lists files under import root) ---------- */
if ($action === 'file_picker') {
  try {
    // Config for roots (Windows/Unix) — config.php must return an array
    $cfg  = require dirname(__DIR__) . '/config.php';
    $root = hc_import_root($cfg); // e.g., C:\xampp\htdocs\imports or /var/xampp/htdocs/imports

    $rel    = trim((string)($in['dir'] ?? ''), "/\\");
    $q      = trim((string)($in['q'] ?? ''));           // optional name filter
    $limit  = max(1, min(500, (int)($in['limit'] ?? 200)));
    $hints  = !empty($in['hints']);                     // optional: include header hints

    $absRoot = realpath($root);
    if (!$absRoot || !is_dir($absRoot)) {
      throw new RuntimeException('Import root not found');
    }

    $absDir = $absRoot . ($rel ? DIRECTORY_SEPARATOR . $rel : '');
    $absDir = realpath($absDir) ?: $absDir;

    // stay inside root
    if (strpos($absDir, $absRoot) !== 0) {
      throw new RuntimeException('Invalid directory');
    }

    // allow a few more formats (non-breaking)
    $allowed = ['csv','xlsx','xls','xlsm','tsv','txt'];

    // ----- helpers for optional header hints -----
    $normKey = static function (string $label): string {
      $s = strtolower(trim($label));
      $s = preg_replace('/[^a-z0-9]+/','_', $s);
      return trim($s, '_');
    };
    $stockAliases = static function (): array {
      return [
        // EN
        'stock','stock_level','stock_available','available','available_pieces','available_piece',
        'available_qty','available_quantity','qty','quantity','in_stock','instock','onhand','on_hand',
        'inventory','inventory_qty','pieces_available','stock_qty','stock_quantity',
        // DE
        'stock_available_de','stock_de','lager','lager_de','lagerbestand','verfuegbar','verfügbar',
        // CZ/SK common
        'sklad','skladom','stav_skladu'
      ];
    };
    $guessDelimiter = static function (string $sample): string {
      $candidates = ["," => 0, ";" => 0, "\t" => 0, "|" => 0];
      foreach ($candidates as $d => $_) {
        $candidates[$d] = substr_count($sample, $d);
      }
      arsort($candidates);
      $top = array_key_first($candidates);
      return $top ?: ',';
    };

    // Peek headers for CSV/TSV/TXT quickly
    $peekTextHeaders = static function (string $path, string $ext) use ($guessDelimiter): array {
      if (!in_array($ext, ['csv','tsv','txt'], true)) return [];
      $fh = @fopen($path, 'rb');
      if (!$fh) return [];
      $buf = @fread($fh, 4096) ?: '';
      @fclose($fh);
      if ($buf === '') return [];
      $delim = $ext === 'tsv' ? "\t" : $guessDelimiter($buf);
      $line  = strtok($buf, "\r\n");
      if ($line === false) return [];
      // Use PHP's CSV parser to respect quotes
      $headers = str_getcsv($line, $delim);
      return is_array($headers) ? $headers : [];
    };

    // Peek headers for Excel using PhpSpreadsheet if available
    $peekExcelHeaders = static function (string $path, string $ext): array {
      if (!in_array($ext, ['xlsx','xls','xlsm'], true)) return [];
      // only if PhpSpreadsheet is available
      if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) return [];
      try {
        // Keep it cheap: load first sheet, read only the first row
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) $reader->setReadDataOnly(true);
        if (method_exists($reader, 'setReadFilter')) {
          // read filter for row 1 only (optional micro-opt)
          $reader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            public function readCell($columnAddress, $row, $worksheetName = ''): bool {
              return $row === 1; // only first row
            }
          });
        }
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheet(0);
        $highestCol = $sheet->getHighestColumn();
        $highestIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
        $headers = [];
        for ($i = 1; $i <= $highestIndex; $i++) {
          $val = $sheet->getCellByColumnAndRow($i, 1)->getValue();
          // normalize to string labels
          if (is_null($val)) $val = '';
          if (is_object($val)) $val = (string)$val;
          $headers[] = (string)$val;
        }
        return $headers;
      } catch (\Throwable $e) {
        return [];
      }
    };

    $files = [];

    foreach (scandir($absDir) ?: [] as $f) {
      if ($f === '.' || $f === '..') continue;
      $full = $absDir . DIRECTORY_SEPARATOR . $f;
      if (!is_file($full)) continue;

      $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
      if (!in_array($ext, $allowed, true)) continue;

      if ($q !== '' && stripos($f, $q) === false) continue;

      $row = [
        'name'  => $f,
        'rel'   => ltrim(($rel ? $rel.'/' : '') . $f, '/'),
        'size'  => @filesize($full) ?: 0,
        'mtime' => @filemtime($full) ?: 0,
      ];

      // optional: header hints (non-breaking; only added when requested)
      if ($hints) {
        $headers = [];
        if (in_array($ext, ['csv','tsv','txt'], true)) {
          $headers = $peekTextHeaders($full, $ext);
        } else {
          $headers = $peekExcelHeaders($full, $ext);
        }

        $hasStock = false;
        $stockHeader = null;

        if ($headers) {
          $norm = array_map($normKey, $headers);
          $aliases = $stockAliases();
          foreach ($norm as $i => $nk) {
            if (in_array($nk, $aliases, true)) {
              $hasStock = true;
              $stockHeader = $headers[$i];
              break;
            }
          }
        }

        $row['hints'] = [
          'has_stock'    => $hasStock,
          'stock_header' => $stockHeader,
          // 'headers'    => $headers, // uncomment for debugging
        ];
      }

      $files[] = $row;
    }

    usort($files, fn($a,$b) => $b['mtime'] <=> $a['mtime']);
    $files = array_slice($files, 0, $limit);

    echo json_encode([
      'ok'    => true,
      'root'  => $absRoot,
      'dir'   => $rel,
      'files' => $files
    ], JSON_UNESCAPED_UNICODE);
    exit;

  } catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    exit;
  }
}

/* ---------- Router dispatch for other actions ---------- */
$pdo      = db();
$sentinel = new debug_sentinel('hc_api', $pdo);

try {
  (new HcRouter($pdo, $sentinel))->handle($action, $in, $_FILES ?? []);
} catch (Throwable $e) {
  $sentinel->error('hc_api_error', ['err' => $e->getMessage()]);
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
