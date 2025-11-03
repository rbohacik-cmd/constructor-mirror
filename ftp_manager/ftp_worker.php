<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once __DIR__ . '/../appcfg.php';
current_server_key('blue'); // <<— ensure MySQL

require_once __DIR__ . '/../mysql.php';
require_once __DIR__ . '/../partials/crypto.php';
require_once __DIR__ . '/ftp_client.php';

/**
 * Usage: php ftp_worker.php <job_id>
 */
$jobId = (int)($argv[1] ?? 0);
if ($jobId <= 0) {
    fwrite(STDERR, "Usage: php ftp_worker.php <job_id>\n");
    exit(2);
}

/** 1) Load job + connection (enabled only) */
$job = qrow("
    SELECT
      j.*,
      c.id           AS c_id,
      c.protocol     AS conn_protocol,
      c.host         AS conn_host,
      c.port         AS conn_port,
      c.username     AS conn_user,
      c.password_enc AS conn_pass_enc,
      c.passive      AS conn_passive,
      c.root_path    AS conn_root
    FROM ftp_jobs j
    JOIN ftp_connections c ON c.id = j.connection_id
    WHERE j.id = ? AND j.enabled = 1
", [$jobId]);

if (!$job) {
    fwrite(STDERR, "Job not found or disabled: {$jobId}\n");
    exit(1);
}

$ftpPass = decrypt_pass((string)$job['conn_pass_enc']);

/** 2) Create run row */
qexec("
  INSERT INTO ftp_runs_log (job_id, started_at, status, runner_host, runner_user, group_id)
  VALUES (?, NOW(), 'ok', ?, ?, ?)
", [
  (int)$job['id'],
  php_uname('n'),
  get_current_user(),
  bin2hex(random_bytes(8))
]);

$runId = (int) qscalar("SELECT LAST_INSERT_ID()");

$files = 0;
$bytes = 0;
$errors = [];
$onlyNewer = !empty($job['only_newer_than']) ? new DateTime((string)$job['only_newer_than']) : null;

try {
    /** 3) Download + process files */
    ftp_download_iter(
        [
            'protocol' => (string)$job['conn_protocol'],
            'host'     => (string)$job['conn_host'],
            'port'     => (int)$job['conn_port'],
            'user'     => (string)$job['conn_user'],
            'pass'     => $ftpPass,
            'passive'  => (int)$job['conn_passive'] === 1,
            'root'     => (string)($job['conn_root'] ?? ''),
        ],
        (string)$job['remote_path'],
        (string)($job['filename_glob'] ?: '*'),
        (int)$job['is_recursive'] === 1,        // ← renamed column
        $onlyNewer,
        function (string $dir, string $name, int $size, ?string $mtime, $stream) use ($job, $runId, &$files, &$bytes, &$errors) {

            // Save to temp to compute checksum (and feed to parsers if needed)
            $tmp = tempnam(sys_get_temp_dir(), 'ftp_');
            $fp  = fopen($tmp, 'wb');
            stream_copy_to_stream($stream, $fp);
            fclose($fp);
            $md5 = md5_file($tmp) ?: null;

            // 3a) Manifest (idempotent)
            qexec("
              INSERT INTO ftp_file_manifest
                (job_id, remote_dir, filename, size_bytes, mtime, checksum_md5, downloaded_at, run_id)
              VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
              ON DUPLICATE KEY UPDATE
                checksum_md5 = VALUES(checksum_md5),
                downloaded_at = VALUES(downloaded_at),
                run_id = VALUES(run_id)
            ", [
              (int)$job['id'],
              $dir,
              $name,
              (int)$size,
              $mtime ?? '1970-01-01 00:00:00',
              $md5,
              $runId
            ]);

            $files++;
            $bytes += max(0, (int)$size);

            // 3b) Dispatch to your central pipeline(s)
            try {
                dispatch_to_pipeline(
                    (string)$job['target_pipeline'],
                    $job['parser_profile'] ? (string)$job['parser_profile'] : null,
                    $tmp, $dir, $name, $md5
                );
            } catch (Throwable $e) {
                $errors[] = "Pipeline error for {$name}: " . $e->getMessage();
            }

            @unlink($tmp);
        }
    );

    /** 4) Finalize run */
    $status = empty($errors) ? 'ok' : 'warn';
    $msg = empty($errors) ? null : implode("\n", array_slice($errors, 0, 50));

    qexec("
      UPDATE ftp_runs_log
         SET finished_at = NOW(),
             files_downloaded = ?,
             bytes_downloaded = ?,
             status = ?,
             message = ?
       WHERE id = ?
    ", [
      $files, $bytes, $status, $msg, $runId
    ]);

    echo "Run #{$runId} complete: files={$files}, bytes={$bytes}, status={$status}\n";

} catch (Throwable $e) {
    qexec("
      UPDATE ftp_runs_log
         SET finished_at = NOW(),
             status = 'error',
             message = ?
       WHERE id = ?
    ", [ substr($e->getMessage(), 0, 5000), $runId ]);

    fwrite(STDERR, "ERROR: ".$e->getMessage()."\n");
    exit(1);
}

/**
 * Stub — plug your central inserts here.
 * Example:
 *  - PRICELIST: parse CSV/XLSX and UPSERT into item_prices staging.
 *  - SCRAPER: normalize and insert into product staging for rename/scraper flow.
 */
function dispatch_to_pipeline(string $pipeline, ?string $profile, string $tmpPath, string $dir, string $name, ?string $md5): void {
    switch ($pipeline) {
        case 'PRICELIST':
            // TODO: parse $tmpPath and qexec() UPSERTs into your central staging tables
            break;
        case 'SCRAPER':
            // TODO: parse/normalize and qexec() into your product staging tables
            break;
        default: // RAW_STAGE
            // keep only manifest in v1
            break;
    }
}
