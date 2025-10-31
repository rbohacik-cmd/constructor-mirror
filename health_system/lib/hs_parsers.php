<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

function hs_detect_format(string $absPath): string {
  $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
  return ($ext === 'xlsx') ? 'xlsx' : 'csv';
}

/**
 * Returns [headers, rowIterator]
 * - headers: array of header strings (from first row) or generated A,B,C,... if missing
 * - rowIterator: Generator yielding sequential row arrays (0-based index)
 */
function hs_open_sheet(string $absPath): array {
  $fmt = hs_detect_format($absPath);

  if ($fmt === 'xlsx') {
    // Faster: values only, skip empty cells
    $reader = new XlsxReader();
    $reader->setReadDataOnly(true);
    $reader->setReadEmptyCells(false);
    $ss = $reader->load($absPath);

    $ws = $ss->getSheet(0);
    $highestCol = $ws->getHighestDataColumn();
    $highestRow = $ws->getHighestDataRow();

    // headers
    $headers = [];
    foreach ($ws->rangeToArray('A1:' . $highestCol . '1', null, true, true, true) as $row) {
      foreach ($row as $cell) { $headers[] = trim((string)$cell); }
      break;
    }
    if (empty(array_filter($headers))) {
      // generate A..Z..AA if first row empty
      $headers = [];
      $colCount = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
      for ($i = 1; $i <= $colCount; $i++) {
        $headers[] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
      }
    }

    // rows iterator (start from row 2)
    $iter = (function() use ($ws, $highestCol, $highestRow) {
      for ($r = 2; $r <= $highestRow; $r++) {
        $row = $ws->rangeToArray('A'.$r.':'.$highestCol.$r, null, true, true, true);
        $cells = reset($row);
        yield array_values(array_map(fn($v) => is_scalar($v) ? (string)$v : '', $cells));
      }
    })();

    return [$headers, $iter];
  }

  // CSV
  $fh = fopen($absPath, 'rb');
  if (!$fh) throw new RuntimeException('Cannot open CSV');

  $headers = fgetcsv($fh) ?: [];
  if (!$headers) { $headers = ['A','B','C','D','E','F']; }

  $iter = (function() use ($fh) {
    while (($row = fgetcsv($fh)) !== false) {
      yield array_map(fn($v) => (string)$v, $row);
    }
    fclose($fh);
  })();

  return [$headers, $iter];
}
