<?php
declare(strict_types=1);

// keep output clean for JSON responses
@ini_set('output_buffering','off');
@ini_set('zlib.output_compression','0');
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip','1'); }

// default response headers (some actions may override)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../bootstrap_hs.php';
require_once __DIR__ . '/AdminTools.php';

$pdo      = hs_pdo();
$sentinel = new debug_sentinel('health_system', $pdo);

/* -------------------- helpers -------------------- */
function hs_read_input(): array {
  $in = $_POST ?? [];
  $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
  if (stripos($ctype, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $js  = json_decode($raw ?: '[]', true);
    if (is_array($js)) $in = $js;
  }
  return $in ?: [];
}
function hs_int($v, int $def=0): int { return is_numeric($v) ? (int)$v : $def; }
function hs_json($ok, $dataOrErr, int $code=null): void {
  if ($code !== null) http_response_code($code);
  if ($ok) {
    echo json_encode(['ok'=>true, 'data'=>$dataOrErr], JSON_UNESCAPED_UNICODE);
  } else {
    echo json_encode(['ok'=>false, 'error'=> (string)$dataOrErr], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

/**
 * Minimal, consistent Sentinel logger for HS.
 * Uses debug_sentinel->record($label, $valueJson, $code, $level, $overrideContext)
 * and falls back to ->log() with correct ordering if needed.
 */
function hs_slog(debug_sentinel $s, string $level, string $phase, string $message, array $ctx = [], ?string $code = null): void {
  $label = $phase !== '' ? ($phase . ': ' . $message) : $message;
  $val   = $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;

  try {
    // Prefer record(): ($label, $value, $code, $level, $override_context)
    $s->record($label, $val, $code, $level, 'health_system');
  } catch (\Throwable $e) {
    // Back-compat fallback to log(): ($context, $label, $value, $code, $level)
    try {
      $s->log('health_system', $label, $val, $code, $level);
    } catch (\Throwable $e2) {
      // Last ditch: ignore logging failure, but do not break the request
      error_log('[Sentinel] hs_slog failed: ' . $e2->getMessage());
    }
  }
}

/**
 * Try very hard to find a working PHP CLI binary.
 * Returns absolute path (string) or null. Logs resolution attempts.
 */
function hs_resolve_php_cli(debug_sentinel $s): ?string {
  $attempts = [];

  $add = function(?string $p) use (&$attempts) {
    if ($p && !in_array($p, $attempts, true)) $attempts[] = $p;
  };

  // 1) If we’re already CLI, trust PHP_BINARY
  if (PHP_SAPI === 'cli') $add(PHP_BINARY);

  // 2) Common Windows/XAMPP locations + env override
  if (stripos(PHP_OS, 'WIN') === 0) {
    $add(getenv('PHP_CLI') ?: null);                          // allow override via env
    $add('C:\\xampp\\php\\php.exe');                          // default XAMPP
    $add(realpath(dirname(PHP_BINARY) . '\\..\\..\\php\\php.exe')); // from Apache bin to php
  }

  // 3) Generic fallbacks (Unix + PATH)
  $add(PHP_BINARY);                    // whatever PHP thinks it is
  $add(PHP_BINDIR . DIRECTORY_SEPARATOR . 'php');
  $add('/usr/bin/php');
  $add('/usr/local/bin/php');
  $add('php');                         // PATH

  // Try them
  foreach ($attempts as $cand) {
    if (!$cand) continue;
    $cmd = '"'.$cand.'" -v';
    $out = []; $rc = null;
    @exec($cmd . ' 2>&1', $out, $rc);
    $ok = ($rc === 0) && preg_grep('~^PHP\s+\d+\.\d+~i', $out);
    hs_slog($s, $ok ? 'info' : 'warn', 'phpcli.resolve', $ok ? 'candidate ok' : 'candidate fail', [
      'candidate' => $cand, 'rc' => $rc, 'first' => $out[0] ?? null, 'sapi' => PHP_SAPI,
    ]);
    if ($ok) return $cand;
  }

  hs_slog($s, 'error', 'phpcli.resolve', 'no working PHP CLI found', [
    'php_binary' => PHP_BINARY, 'sapi' => PHP_SAPI,
  ]);
  return null;
}


/**
 * Spawn detached worker: bin/hs_worker.php <runId> <uploadId>
 * Returns true if we launched a PHP CLI process successfully.
 */
function hs_spawn_worker(debug_sentinel $s, int $runId, int $uploadId): bool {
  $php = hs_resolve_php_cli($s);
  $script = realpath(__DIR__ . '/../bin/hs_worker.php');

  hs_slog($s, 'info', 'spawn', 'precheck', [
    'resolved_php'   => $php,
    'worker_script'  => $script,
    'worker_exists'  => (bool)($script && is_file($script)),
    'worker_read'    => (bool)($script && is_readable($script)),
    'run_id'         => $runId,
    'upload_id'      => $uploadId,
  ]);

  if (!$php || !$script || !is_file($script) || !is_readable($script)) {
    hs_slog($s, 'error', 'spawn', 'precheck failed', []);
    return false;
  }

  $cmd = '"'.$php.'" "'.$script.'" '.$runId.' '.$uploadId;

  if (stripos(PHP_OS, 'WIN') === 0) {
    // On Windows, use START to detach; but still sanity-check that php -v worked above.
    $full = 'cmd /c start "" /B ' . $cmd . ' > NUL 2>&1';
    hs_slog($s, 'info', 'spawn', 'exec.win', ['cmd' => $full]);
    @pclose(@popen($full, 'r'));
    // We can’t get the child’s exit code; we optimistically return true.
    return true;
  } else {
    // Unix: we *can* ask exec() for rc because the shell returns instantly with '&'
    $full = $cmd . ' > /dev/null 2>&1 & echo $!';
    hs_slog($s, 'info', 'spawn', 'exec.unix', ['cmd' => $full]);
    $out = []; $rc = 0;
    $pid = @exec($full, $out, $rc);
    $ok  = ($rc === 0) && $pid;
    hs_slog($s, $ok ? 'info' : 'error', 'spawn', $ok ? 'started' : 'start failed', ['rc' => $rc, 'pid' => $pid, 'out0' => $out[0] ?? null]);
    return $ok;
  }
}

/* Accept action from GET or POST (POST takes precedence) */
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

try {
  switch ($action) {

    /* -------------------- Jobs -------------------- */
    case 'jobs.list': {
      require __DIR__ . '/JobsController.php';
      hs_json(true, HS\JobsController::list($pdo));
    }

    case 'jobs.get': {
      require __DIR__ . '/JobsController.php';
      $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
      hs_json(true, HS\JobsController::get($pdo, $id));
    }

    case 'jobs.save': {
      require __DIR__ . '/JobsController.php';
      hs_json(true, HS\JobsController::save($pdo, $_POST));
    }

    case 'jobs.delete': {
      require __DIR__ . '/JobsController.php';
      HS\JobsController::delete($pdo, (int)($_POST['id'] ?? 0));
      hs_json(true, ['deleted'=>true]);
    }

    case 'jobs.run': {
      $jobId = (int)($_POST['id'] ?? 0);
      if ($jobId <= 0) hs_json(false, 'Missing job id', 400);

      require __DIR__ . '/JobsController.php';
      require __DIR__ . '/UploadsController.php';
      require_once __DIR__ . '/Log.php';

      hs_slog($sentinel, 'info', 'jobs.run', 'invoked', [
        'job_id' => $jobId,
        'post_keys' => array_keys($_POST),
      ]);

      // 1) Prepare: create run + upload snapshot
      $meta = HS\JobsController::run($pdo, $jobId, $sentinel);
      $runId    = (int)($meta['run_id'] ?? 0);
      $uploadId = (int)($meta['upload_id'] ?? 0);

      hs_slog($sentinel, ($runId > 0 && $uploadId > 0) ? 'info' : 'error', 'jobs.run', 'meta created', [
        'run_id'    => $runId,
        'upload_id' => $uploadId,
        'meta_keys' => array_keys((array)$meta),
      ]);

      if ($runId <= 0 || $uploadId <= 0) {
        // mirror into hs_logs table
        HS\Log::add($pdo, $jobId, $runId ?: null, 'error', 'Failed to initialize run/upload', 'error');
        hs_json(false, 'Failed to initialize run/upload', 500);
      }

      // 2) Detach PHP session so the request can end immediately
      @session_write_close();
      ignore_user_abort(true);

      // 3) Extra diagnostics about the worker script before we spawn
      $phpBin   = PHP_BINARY ?: 'php';
      $worker   = realpath(__DIR__ . '/../bin/hs_worker.php');
      $exists   = ($worker && is_file($worker));
      $canExec  = $exists && is_readable($worker);
      hs_slog($sentinel, 'info', 'jobs.run', 'pre-spawn check', [
        'php_binary'    => $phpBin,
        'worker_script' => $worker,
        'worker_exists' => $exists,
        'worker_read'   => $canExec,
        'run_id'        => $runId,
        'upload_id'     => $uploadId,
      ]);

      // 4) Spawn the background worker
      $spawnOk = hs_spawn_worker($sentinel, $runId, $uploadId);

      hs_slog($sentinel, $spawnOk ? 'info' : 'error', 'jobs.run', 'spawn result', [
        'spawn_ok'  => $spawnOk,
        'run_id'    => $runId,
        'upload_id' => $uploadId,
      ]);

      if (!$spawnOk) {
        HS\Log::add($pdo, $jobId, $runId, 'error', 'Worker spawn failed', 'error');
        \qexec(
          "UPDATE hs_runs SET status='failed', finished_at=NOW(), error_message=? WHERE id=?",
          ['Worker spawn failed', $runId]
        );
        hs_json(false, 'Failed to start background worker', 500);
      }

      // 5) *** Send JSON BODY RELIABLY ***
      while (ob_get_level() > 0) { @ob_end_clean(); }

      $payload = json_encode(['ok'=>true, 'data'=>['run_id'=>$runId, 'upload_id'=>$uploadId]], JSON_UNESCAPED_UNICODE);
      $len     = strlen($payload);

      header_remove('Transfer-Encoding');
      header('Content-Type: application/json; charset=utf-8');
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
      header('Pragma: no-cache');
      header('Content-Length: ' . $len);

      hs_slog($sentinel, 'info', 'jobs.run', 'response ready', ['bytes' => $len]);

      echo $payload;

      if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
      } else {
        @flush();
        @ob_flush();
      }
      exit;
    }

    case 'runs.latest_for_job': {
      $jobId = hs_int($_GET['job_id'] ?? $_POST['job_id'] ?? 0);
      if ($jobId <= 0) { hs_json(false, 'Missing job_id', 400); }
      $row = qrow("SELECT id FROM hs_runs WHERE job_id=? ORDER BY id DESC LIMIT 1", [$jobId]);
      hs_json(true, ['run_id' => (int)($row['id'] ?? 0)]);
    }

    /* -------------------- Runs / Status -------------------- */
    case 'runs.status': {
      $in = hs_read_input();
      $runId = hs_int($in['run_id'] ?? 0);

      // If caller sent only upload_id, map to run_id for convenience
      if ($runId <= 0 && !empty($in['upload_id'])) {
        $up = qrow('SELECT run_id FROM hs_uploads WHERE id=? LIMIT 1', [hs_int($in['upload_id'])]);
        if ($up) $runId = (int)$up['run_id'];
      }

      if ($runId <= 0) {
        hs_slog($sentinel, 'error', 'runs.status', 'missing run_id', ['in_keys' => array_keys((array)$in)]);
        hs_json(false, 'Missing run_id', 400);
      }

      $out = \HS\ImportWorker::status($pdo, $runId);

      $run      = $out['run'] ?? null;
      $progress = $out['progress'] ?? null;
      $status   = $run['status'] ?? 'pending';
      $errMsg   = $run['error_message'] ?? null;

      hs_slog($sentinel, 'info', 'runs.status', 'read', [
        'run_id'    => $runId,
        'status'    => $status,
        'has_prog'  => (bool)$progress,
        'err_msg'   => $errMsg ? mb_substr((string)$errMsg, 0, 160) : null,
      ]);

      $payload = [
        'run'           => $run,
        'progress'      => $progress,
        'status'        => $status,
        'error_message' => $errMsg,
      ];
      hs_json(true, $payload);
    }

    /* -------------------- Uploads / Picker -------------------- */
    case 'picker.list': {
      require __DIR__ . '/UploadsController.php';
      hs_json(true, HS\UploadsController::pickerList($_POST));
    }

    case 'upload.register': {
      require __DIR__ . '/UploadsController.php';
      hs_json(true, HS\UploadsController::register($pdo, $_POST, $sentinel));
    }

    /* -------------------- Global stop / resume -------------------- */
    case 'imports.stop_all': {
      qexec('INSERT INTO hs_control (k, v, updated_at)
             VALUES ("stop_all_at", NOW(), NOW())
             ON DUPLICATE KEY UPDATE v=NOW(), updated_at=NOW()', []);

      $open = ['pending','started','running','reading','inserting','cancelling'];
      $in   = implode(',', array_fill(0, count($open), '?'));
      $stmt = $pdo->prepare("
          UPDATE hs_runs
             SET status='cancelled', finished_at=NOW(), error_message=COALESCE(error_message,'Stopped by admin')
           WHERE status IN ($in)
      ");
      $stmt->execute($open);
      $runsCancelled = $stmt->rowCount();

      qexec("
          DELETE p FROM hs_progress p
          JOIN hs_uploads u ON u.id = p.upload_id
          JOIN hs_runs    r ON r.id = u.run_id
          WHERE r.status = 'cancelled'
      ", []);

      qexec("
          UPDATE hs_import_jobs j
          JOIN (
            SELECT DISTINCT job_id
            FROM hs_runs
            WHERE status='cancelled' AND finished_at >= NOW() - INTERVAL 5 MINUTE
          ) x ON x.job_id = j.id
          SET j.last_status = 'cancelled', j.last_run_at = NOW()
      ", []);

      require_once __DIR__ . '/AdminTools.php';
      \HS\AdminTools::normalizeJobStatuses($pdo);

      $pulse = qval('SELECT v FROM hs_control WHERE k="stop_all_at"');
      hs_json(true, ['stop_all_at'=>$pulse, 'runs_cancelled'=>$runsCancelled]);
    }

    case 'imports.clear_stop': {
      qexec('DELETE FROM hs_control WHERE k="stop_all_at"', []);
      qexec('DELETE FROM hs_control WHERE k="stop_all"', []);
      hs_json(true, ['stop_all'=>false]);
    }

    case 'admin_reset': {
      if (function_exists('require_role')) { require_role('admin'); }
      $payload = ['dry' => (int)($_POST['dry'] ?? 0), 'scope' => (string)($_POST['scope'] ?? 'all')];
      try {
        $out = \HS\AdminTools::reset($pdo, $payload, $sentinel);
        hs_json(true, $out);
      } catch (\Throwable $e) {
        hs_json(false, $e->getMessage(), 500);
      }
    }

    case 'control.status': {
      $row = qrow('SELECT v FROM hs_control WHERE k="stop_all_at" LIMIT 1', []);
      hs_json(true, ['stop_all_at'=>$row['v'] ?? null]);
    }

    case 'runs.logs': {
      $in = hs_read_input();
      $runId  = hs_int($in['run_id'] ?? 0);
      $jobId  = hs_int($in['job_id'] ?? 0);
      $since  = hs_int($in['since_id'] ?? 0);
      $limit  = min(1000, max(50, hs_int($in['limit'] ?? 250)));

      if ($runId <= 0 && $jobId <= 0) {
        hs_json(false, 'Missing run_id or job_id', 400);
      }

      $where = []; $args = [];
      if ($runId > 0) { $where[] = 'run_id = ?'; $args[] = $runId; }
      if ($jobId > 0) { $where[] = 'job_id = ?'; $args[] = $jobId; }
      if ($since > 0) { $where[] = 'id > ?';     $args[] = $since; }

      $sql = 'SELECT id, ts, job_id, run_id, level, phase, message, meta_json
                FROM hs_logs
               WHERE ' . implode(' AND ', $where) . '
               ORDER BY id ASC
               LIMIT ?';
      $args[] = $limit;

      $rows = qall($sql, $args);
      hs_json(true, ['items'=>$rows, 'last_id'=>($rows ? end($rows)['id'] : $since)]);
    }

    /* -------------------- Import lifecycle -------------------- */
    case 'import.start': {
      $in = hs_read_input();

      // Accept multiple shapes: job_id | jobId | job | id; or resolve from run_id/upload_id
      $jobId = hs_int($in['job_id'] ?? $in['jobId'] ?? $in['job'] ?? $in['id'] ?? 0);
      if ($jobId <= 0 && !empty($in['run_id'])) {
        $r = qrow('SELECT job_id FROM hs_runs WHERE id=? LIMIT 1', [hs_int($in['run_id'])]);
        if ($r) $jobId = (int)$r['job_id'];
      }
      if ($jobId <= 0 && !empty($in['upload_id'])) {
        $u = qrow('SELECT run_id FROM hs_uploads WHERE id=? LIMIT 1', [hs_int($in['upload_id'])]);
        if ($u) {
          $r = qrow('SELECT job_id FROM hs_runs WHERE id=? LIMIT 1', [(int)$u['run_id']]);
          if ($r) $jobId = (int)$r['job_id'];
        }
      }

      hs_slog($sentinel, 'info', 'import.start', 'invoked', [
        'raw_keys' => array_keys((array)$in),
        'job_id'   => $jobId,
      ]);

      if ($jobId <= 0) {
        hs_slog($sentinel, 'error', 'import.start', 'missing job_id');
        hs_json(false, 'Missing job_id', 400);
      }

      $uploadId   = isset($in['upload_id'])   ? hs_int($in['upload_id'])       : null;
      $storedPath = isset($in['stored_path']) ? (string)$in['stored_path']     : null;

      $payload = ['job_id' => $jobId];
      if ($uploadId)   $payload['upload_id']   = $uploadId;
      if ($storedPath) $payload['stored_path'] = $storedPath;

      $out = \HS\ImportWorker::start($pdo, $payload, $sentinel);

      hs_slog($sentinel, ($out['run_id'] ?? 0) > 0 ? 'info' : 'error', 'import.start', 'ImportWorker.start finished', [
        'returned' => [
          'run_id'    => (int)($out['run_id'] ?? 0),
          'upload_id' => (int)($out['upload_id'] ?? 0),
          'status'    => (string)($out['status'] ?? ''),
          'error'     => (string)($out['error'] ?? ''),
        ],
      ]);

      hs_json(true, $out);
    }

    // tiny probe for UI to gray out "Run" button if something active
    case 'import.status': {
      $jobId = hs_int($_GET['job_id'] ?? $_POST['job_id'] ?? 0);
      if ($jobId <= 0) {
        hs_json(true, ['active'=>false]);
      }

      try {
        $row = qrow(
          "SELECT id, status
             FROM hs_runs
            WHERE job_id = ?
              AND status IN ('started','running','reading','inserting')
            ORDER BY id DESC
            LIMIT 1",
          [$jobId]
        );
        hs_json(true, $row ? ['active'=>true, 'run'=>$row] : ['active'=>false]);
      } catch (\Throwable $e) {
        // Never break the UI on probe errors
        hs_json(true, ['active'=>false]);
      }
    }

    /* -------------------- Default -------------------- */
    default: {
      hs_json(false, 'Unknown action', 400);
    }
  }
} catch (\Throwable $e) {
  hs_json(false, $e->getMessage(), 500);
}
