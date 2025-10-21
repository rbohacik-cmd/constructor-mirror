<?php
declare(strict_types=1);

final class JobsController {
  public function __construct(private PDO $pdo, private debug_sentinel $sentinel) {}

  public function list(): void {
    hc_jobs_ensure_tables($this->pdo);
    $rows = qall("
      SELECT id, manufacturer, file_path, enabled, last_status, last_import_at
      FROM hc_import_jobs
      ORDER BY enabled DESC, manufacturer ASC, id DESC
    ");
    hc_json_ok(['rows' => $rows]);
  }

  public function get(int $id): void {
    if (!$id) hc_json_error('Missing id');
    $row = qrow("SELECT * FROM hc_import_jobs WHERE id=?", [$id]);
    if (!$row) hc_json_error('Not found', 404);
    hc_json_ok(['row' => $row]);
  }

  public function save(array $in): void {
    hc_jobs_ensure_tables($this->pdo);
    $id = (int)($in['id'] ?? 0);

    // Partial toggle update
    $enabled_only = $id && isset($in['enabled']) && !isset($in['manufacturer']) && !isset($in['file_path']);
    if ($enabled_only) {
      qexec("UPDATE hc_import_jobs SET enabled=?, updated_at=NOW() WHERE id=?", [(int)$in['enabled'], $id]);
      hc_json_ok(['id'=>$id]);
    }

    $manufacturer = trim((string)($in['manufacturer'] ?? ''));
    $file_path    = trim((string)($in['file_path'] ?? ''));
    $enabled      = (int)($in['enabled'] ?? 1);
    $notes        = (string)($in['notes'] ?? null);

    if ($manufacturer==='' || $file_path==='') hc_json_error('Manufacturer and file path are required.');

    if ($id) {
      qexec("UPDATE hc_import_jobs
                SET manufacturer=?, file_path=?, enabled=?, notes=?, updated_at=NOW()
              WHERE id=?",
            [$manufacturer, $file_path, $enabled, $notes, $id]);
      hc_json_ok(['id'=>$id]);
    } else {
      qi("INSERT INTO hc_import_jobs (manufacturer, file_path, enabled, notes, last_status)
          VALUES (?, ?, ?, ?, 'never')", [$manufacturer, $file_path, $enabled, $notes]);
      hc_json_ok(['id' => (int)qlastid()]);
    }
  }

  public function delete(int $id): void {
    if (!$id) hc_json_error('Missing id');
    qexec("DELETE FROM hc_import_jobs WHERE id=?", [$id]);
    hc_json_ok();
  }

  public function run(int $id): void {
    if (!$id) hc_json_error('Missing id');

    hc_jobs_ensure_tables($this->pdo);
    $job = qrow("SELECT * FROM hc_import_jobs WHERE id=?", [$id]);
    if (!$job) hc_json_error('Job not found', 404);
    if ((int)$job['enabled'] !== 1) hc_json_error('Job is disabled');

    // Resolve input path early (fail fast)
    [$full, $perr] = hc_resolve_local_path((string)$job['file_path']);
    if ($perr) hc_json_error($perr);
    if (!$full || !is_file($full)) hc_json_error("File not found or permission denied: $full");

    // --- CONCURRENCY GUARD (same-manufacturer) ---
    // Ensure (or load) manufacturer to get its id; do NOT create if that's undesirable in your flow.
    // Using hc_ensure_manufacturer() is fine here because jobs use known manufacturers.
    $mfg = hc_ensure_manufacturer($this->pdo, (string)$job['manufacturer']);
    $mfgId = (int)$mfg['id'];

    // If another upload for the same manufacturer is currently running, block this run.
    if (function_exists('hc_manufacturer_busy') && hc_manufacturer_busy($this->pdo, $mfgId)) {
      $runningUploadId = function_exists('hc_running_upload_for_manufacturer')
        ? hc_running_upload_for_manufacturer($this->pdo, $mfgId)
        : null;

      http_response_code(409); // Conflict
      hc_json_error(
        'Another import is already running for this manufacturer.',
        409,
        ['running_upload_id' => $runningUploadId]
      );
    }

    // Mark job "running" and create a run log
    qexec("UPDATE hc_import_jobs SET last_status='running' WHERE id=?", [$id]);
    qi("INSERT INTO hc_import_runs_log (job_id, status) VALUES (?, 'started')", [$id]);
    $runId = (int)qlastid();

    if (method_exists($this->sentinel, 'setGroup')) {
      $this->sentinel->setGroup("job:$id:run:$runId");
    }
    $this->sentinel->info('job_run_start', [
      'job_id'=>$id, 'run_id'=>$runId,
      'manufacturer'=>$job['manufacturer'],
      'file'=>$job['file_path']
    ]);

    // Register local path as upload (copy to storage + seed progress=running)
    $uploadId = hc_register_local_upload($this->pdo, (string)$job['manufacturer'], $full, $this->sentinel);

    // Return IDs so the frontend can kick the PROCESS stage and poll progress
    hc_json_ok(['job_id'=>$id,'run_id'=>$runId,'upload_id'=>$uploadId]);
  }

  public function runStatus(int $runId): void {
    if (!$runId) hc_json_error('Missing run_id');
    $row = qrow("SELECT id, job_id, started_at, finished_at, status, message, stats_json
                 FROM hc_import_runs_log WHERE id=?", [$runId]);
    if (!$row) hc_json_error('Run not found', 404);
    $job = qrow("SELECT id, last_status, last_import_at FROM hc_import_jobs WHERE id=?", [(int)$row['job_id']]);
    hc_json_ok(['run'=>$row,'job'=>$job]);
  }
}
