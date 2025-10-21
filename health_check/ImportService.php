<?php
declare(strict_types=1);

namespace HealthCheck;

use HealthCheck\Parsers\CsvParser;
use HealthCheck\Parsers\XlsxParser;

final class ImportService {
  public function __construct(private StatusStore $store) {}

  /**
   * Processes the uploaded file, updates progress continuously, and writes a final state.
   * @param array $manufacturer ['id'=>..., 'name'=>..., 'table'=>...]
   */
  public function process(string $uploadId, string $file, array $manufacturer): void {
    $start = microtime(true);
    $status = $this->store->read($uploadId) ?? [];
    $status['status'] = 'processing';
    $status['note']   = 'Parsing…';
    $this->store->write($uploadId, $status);

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $iter = match ($ext) {
      'csv','txt' => new CsvParser($file, $this->guessDelimiter($file)),
      'xlsx','xls'=> new XlsxParser($file),
      default     => throw new \RuntimeException("Unsupported ext: .$ext"),
    };

    $processed = 0;
    $lastTick  = microtime(true);
    $bytes     = filesize($file) ?: 0;

    // TODO: prepare target table from $manufacturer['table'] if needed

    foreach ($iter as $row) {
      // Minimal demo: count & pretend insert
      // Replace with real insert using your DB layer + column mapping.
      $processed++;

      if (($processed % 100) === 0) {
        $now = microtime(true);
        $elapsed = $now - $start;
        $rate = $elapsed > 0 ? (int)round($processed / $elapsed) : 0;
        $kbps = $elapsed > 0 ? (int)round(($bytes/1024) / $elapsed) : 0;

        $status['status'] = 'processing';
        $status['processed'] = $processed;
        $status['elapsed_sec'] = (int)round($elapsed);
        $status['rate_rows_per_sec'] = $rate;
        $status['rate_kb_per_sec'] = $kbps;
        $status['eta_sec'] = null; // could estimate if you can detect total rows
        $status['note'] = 'Streaming…';
        $this->store->write($uploadId, $status);

        $lastTick = $now;
      }
    }

    // Finalize
    $elapsed = microtime(true) - $start;
    $status['status'] = 'imported';
    $status['processed'] = $processed;
    $status['elapsed_sec'] = (int)round($elapsed);
    $status['rate_rows_per_sec'] = $elapsed > 0 ? (int)round($processed / $elapsed) : $processed;
    $status['rate_kb_per_sec'] = $elapsed > 0 ? (int)round(($bytes/1024) / $elapsed) : 0;
    $status['eta_sec'] = 0;
    $status['note'] = 'Done';
    $this->store->write($uploadId, $status);
  }

  private function guessDelimiter(string $file): string {
    $sample = (string)file_get_contents($file, false, null, 0, 4096);
    $cands = [",",";","\t","|"];
    $best = ",";
    $max = -1;
    foreach ($cands as $d) {
      $n = substr_count($sample, $d);
      if ($n > $max) { $max = $n; $best = $d; }
    }
    return $best;
  }
}
