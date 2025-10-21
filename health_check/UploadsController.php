<?php
declare(strict_types=1);

final class UploadsController {
  public function __construct(private PDO $pdo, private debug_sentinel $sentinel) {}

  public function upload(array $files, array $in): void {
    $manufacturer = trim((string)($in['manufacturer'] ?? ''));
    if ($manufacturer === '') hc_json_error('Missing manufacturer name.');
    if (empty($files['file']['tmp_name'])) hc_json_error('No file uploaded.');

    $mfg   = hc_ensure_manufacturer($this->pdo, $manufacturer);
    $table = (string)$mfg['table_name'];
    hc_ensure_data_table($this->pdo, $table); // will add `stock` if missing

    $storage = realpath(dirname(__DIR__, 2)) . '/storage/health_check';
    if (!is_dir($storage)) @mkdir($storage, 0775, true);

    $orig = $files['file']['name'] ?? 'upload.bin';
    $tmp  = $files['file']['tmp_name'];
    $mime = $files['file']['type'] ?? null;
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $safe = preg_replace('~[^a-zA-Z0-9_.-]+~', '_', pathinfo($orig, PATHINFO_FILENAME));
    $dest = $storage . '/' . date('Ymd_His') . '_' . $safe . '.' . $ext;

    if (!move_uploaded_file($tmp, $dest)) hc_json_error('Failed to move uploaded file.');

    qi("INSERT INTO `hc_uploads`(`manufacturer_id`,`filename`,`stored_path`,`mime`,`status`)
        VALUES (?,?,?,?,?)",
       [$mfg['id'], $orig, $dest, $mime, 'queued']);
    $uploadId = (int)qlastid();

    qi("INSERT INTO `hc_progress` (`upload_id`,`status`,`processed`,`bytes_written`,`started_at`)
        VALUES (?,?,0,0,NOW())
        ON DUPLICATE KEY UPDATE
          `status`=VALUES(`status`), `processed`=0, `bytes_written`=0,
          `started_at`=NOW(), `updated_at`=NOW()",
       [$uploadId, 'running']);

    // best-effort total_rows
    try {
      if (in_array($ext, ['csv','txt'], true)) {
        $lines = 0; if ($fh = fopen($dest, 'rb')) { while (!feof($fh)) { fgets($fh); $lines++; } fclose($fh); }
        if ($lines > 1) qi("UPDATE `hc_progress` SET `total_rows`=? WHERE `upload_id`=?", [$lines - 1, $uploadId]);
      } elseif (in_array($ext, ['xlsx','xls'], true)) {
        $rows = hc_read_xlsx($dest);
        qi("UPDATE `hc_progress` SET `total_rows`=? WHERE `upload_id`=?", [count($rows), $uploadId]);
        unset($rows);
      }
    } catch (Throwable $ignore) {}

    $this->sentinel->info('Upload staged', ['upload_id'=>$uploadId, 'file'=>$dest, 'slug'=>$mfg['slug']]);

    hc_json_ok([
      'action' => 'upload',
      'upload_id' => $uploadId,
      'manufacturer' => ['id'=>$mfg['id'], 'slug'=>$mfg['slug'], 'table'=>$table, 'name'=>$mfg['name']],
    ]);
  }

  public function import(int $uploadId, int $runId = 0): void {
    if ($uploadId <= 0) hc_json_error('Missing upload_id.');

    try {
      $res = $this->processUpload($uploadId);

      if ($runId) {
        $run  = qrow("SELECT job_id FROM hc_import_runs_log WHERE id=?", [$runId]);
        $jobId = (int)($run['job_id'] ?? 0);

        $u   = qrow("SELECT stored_path FROM hc_uploads WHERE id=?", [$uploadId]) ?: [];
        $st  = qrow("SELECT total_rows, processed FROM hc_progress WHERE upload_id=?", [$uploadId]) ?: [];
        $ext = strtolower(pathinfo((string)($u['stored_path'] ?? ''), PATHINFO_EXTENSION));

        $stats = [
          'parser'      => $ext,
          'rows_total'  => (int)($st['total_rows'] ?? $res['rows']),
          'rows_ok'     => (int)$res['rows'],
          'rows_failed' => 0,
          'progress'    => 100,
        ];

        qexec("UPDATE hc_import_runs_log SET finished_at = NOW(), status='ok', stats_json=? WHERE id=?",
              [json_encode($stats, JSON_UNESCAPED_UNICODE), $runId]);
        if ($jobId) qexec("UPDATE hc_import_jobs SET last_import_at = NOW(), last_status='ok' WHERE id=?", [$jobId]);

        $this->sentinel->info('job_run_ok', ['job_id'=>$jobId,'run_id'=>$runId,'upload_id'=>$uploadId,'stats'=>$stats]);
      }

      hc_json_ok([
        'action' => 'import',
        'upload_id' => $uploadId,
        'manufacturer' => [
          'name'  => $res['name'],
          'slug'  => $res['slug'],
          'table' => $res['table'],
        ],
        'rows' => $res['rows'],
        'mode' => ($res['slug'] === 'inline' ? 'inline-generic' : 'generic')
      ]);

    } catch (Throwable $e) {
      if ($runId) {
        qexec("UPDATE hc_import_runs_log SET finished_at = NOW(), status='error', message=? WHERE id=?", [$e->getMessage(), $runId]);
      }
      throw $e;
    }
  }

  public function status(int $uploadId): void {
    if ($uploadId <= 0) hc_json_error('Missing upload_id');
    $p = qrow("SELECT upload_id, status, total_rows, processed, bytes_written, started_at, updated_at, note
               FROM hc_progress WHERE upload_id=?", [$uploadId]) ?: null;
    hc_json_ok(['progress' => $p]);
  }

  /** === the old hc_process_upload(), localized here === */
  private function processUpload(int $uploadId): array {
    $u = qrow("
      SELECT u.*, m.id AS mid, m.slug AS mslug, m.table_name AS mtable, m.name AS mname
      FROM `hc_uploads` u
      JOIN `hc_manufacturers` m ON m.id = u.manufacturer_id
      WHERE u.id = ?
    ", [$uploadId]);
    if (!$u) throw new RuntimeException('Upload not found.');

    $path  = (string)$u['stored_path'];
    $slug  = (string)$u['mslug'];
    $table = (string)$u['mtable'];
    $mname = (string)$u['mname'];
    $ext   = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    $this->sentinel->info('Processing started', ['upload_id'=>$uploadId, 'slug'=>$slug, 'ext'=>$ext]);

    $rows = in_array($ext, ['xlsx','xls'], true) ? hc_read_xlsx($path) : hc_read_csv($path);
    $ri = 2; foreach ($rows as &$r) { $r['_row_index'] = $ri++; } unset($r);

    if (!$rows) {
      qexec("UPDATE `hc_uploads` SET `status`='failed', `error_message`=? WHERE `id`=?", ['No rows parsed (check header/format).', $uploadId]);
      qexec("UPDATE `hc_progress` SET `status`='failed', `note`=? WHERE `upload_id`=?", ['No rows parsed', $uploadId]);
      throw new RuntimeException('No rows parsed.');
    }

    qi("UPDATE `hc_progress` SET `total_rows`=?, `note`='replacing data (truncate existing rows)' WHERE `upload_id`=?", [count($rows), $uploadId]);

    // keep current replace logic
    try { qexec("TRUNCATE TABLE `{$table}`"); }
    catch (Throwable $e) { qexec("DELETE FROM `{$table}`"); }

    $headers = array_keys($rows[0]);

    // --- helpers for header normalisation + picking a stock-like column ---
    $normKey = static function (string $label): string {
      $s = mb_strtolower(trim($label));
      $s = preg_replace('/[^a-z0-9]+/u','_', $s);
      return trim($s, '_');
    };
    $findBestHeader = function(array $headers, array $candidates) use ($normKey): ?string {
      // exact normalized match first, then "contains" fallback
      $normMap = [];
      foreach ($headers as $h) { $normMap[$normKey($h)] = $h; }
      foreach ($candidates as $cand) {
        $nk = $normKey($cand);
        if (isset($normMap[$nk])) return $normMap[$nk];
      }
      $joined = array_map(fn($h) => [$h, $normKey($h)], $headers);
      foreach ($candidates as $cand) {
        $nk = $normKey($cand);
        foreach ($joined as [$orig,$nh]) {
          if (str_contains($nh, $nk)) return $orig;
        }
      }
      return null;
    };

    $map = hc_guess_mapping($headers); // now includes 'stock' support in lib

    // Inline: provide resilient defaults (without hardcoding "stock")
    if ($slug === 'inline') {
      $map = array_merge($map, [
        'code'   => $map['code']   ?? 'Artikelnummer',
        'ean'    => $map['ean']    ?? 'ean',
        'name'   => $map['name']   ?? 'Kurzbeschreibung_en',
        'price'  => $map['price']  ?? '_KundenPreis',
      ]);

      // candidates that appeared in your samples
      $stockCandidates = [
        'stock', 'stock_available_de', 'available pieces', 'available_pieces',
        'available qty','available quantity','qty','quantity','lager','lagerbestand',
        'verfügbar','verfuegbar','sklad','skladom','stav skladu','stav_skladu'
      ];
      $stockHeader = $map['stock'] ?? $findBestHeader($headers, $stockCandidates);

      if ($stockHeader) {
        // ensure we store numeric stock
        if (empty($map['stock'])) $map['stock'] = $stockHeader;
        // if availability isn’t mapped, mirror to keep your previous semantics
        if (empty($map['availability'])) $map['availability'] = $stockHeader;
      } else {
        // last resort: if availability was mapped to a stock-like text, reuse for stock too
        if (!empty($map['availability'])) $map['stock'] = $map['availability'];
      }
    } else {
      // Non-inline: if we didn’t get stock but have a strong availability, reuse it
      if (empty($map['stock']) && !empty($map['availability'])) {
        $map['stock'] = $map['availability'];
      }
    }

    $count = hc_insert_rows($this->pdo, $table, $rows, $map, $this->sentinel, $uploadId);

    qexec("UPDATE `hc_uploads` SET `status`='imported', `rows_imported`=? WHERE `id`=?", [$count, $uploadId]);
    qexec("UPDATE `hc_progress` SET `status`='imported', `processed`=?, `updated_at`=NOW() WHERE `upload_id`=?", [$count, $uploadId]);
    qexec("UPDATE `hc_progress` SET `status`='imported', `updated_at`=NOW()
           WHERE `upload_id`=? AND `status` <> 'imported'
             AND (`total_rows` IS NULL OR `processed` >= `total_rows`)", [$uploadId]);

    return ['rows'=>$count, 'map'=>$map, 'slug'=>$slug, 'table'=>$table, 'name'=>$mname];
  }
}
