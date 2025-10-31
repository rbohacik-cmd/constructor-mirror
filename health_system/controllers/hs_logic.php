<?php
declare(strict_types=1);

// keep output clean for JSON responses
@ini_set('output_buffering','off');
@ini_set('zlib.output_compression','0');
@ini_set('error_log', __DIR__ . '/../var/hs_php_error.log'); // adjust path if you want
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip','1'); }

// default response headers (some actions may override)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../bootstrap_hs.php';
require_once __DIR__ . '/AdminTools.php';
require_once __DIR__ . '/ImportWorker.php';
require_once __DIR__ . '/../lib/hs_lib.php'; // ← central helpers (slug, ensure mfg, tables, etc.)

$pdo      = hs_pdo();
$sentinel = new debug_sentinel('health_system', $pdo);
$GLOBALS['sentinel'] = $sentinel;

/* -------------------- helpers -------------------- */

/** Consistent JSON response helper */
function hs_json($ok, $dataOrErr, int $code=null): void {
  if ($code !== null) http_response_code($code);

  $payload = $ok
    ? ['ok'=>true, 'data'=>$dataOrErr]
    : (is_array($dataOrErr) ? array_merge(['ok'=>false], $dataOrErr) : ['ok'=>false, 'error'=>(string)$dataOrErr]);

  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) {
    $json = '{"ok":false,"error":"JSON encoding failed"}';
  }

  while (ob_get_level() > 0) { @ob_end_clean(); }
  header_remove('Transfer-Encoding');
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  echo $json;
  exit;
}

/** Read input from JSON or form POST (JSON wins if present). */
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

/** Accept array or JSON string; always return array */
function hs_json_to_array($val): array {
  if (is_array($val)) return $val;
  if ($val === null || $val === '') return [];
  if (is_string($val)) {
    $tmp = json_decode($val, true);
    if (is_array($tmp)) return $tmp;
  }
  return [];
}

/** Minimal, consistent Sentinel logger for HS. */
function hs_slog(debug_sentinel $s, string $level, string $phase, string $message, array $ctx = [], ?string $code = null): void {
  $label = $phase !== '' ? ($phase . ': ' . $message) : $message;
  $val   = $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
  try { $s->record($label, $val, $code, $level, 'health_system'); }
  catch (\Throwable $e) { try { $s->log('health_system', $label, $val, $code, $level); } catch (\Throwable $e2) { error_log('[Sentinel] hs_slog failed: ' . $e2->getMessage()); } }
}

/* ---------- Manufacturer resolver (no local slug helpers) ---------- */
if (!function_exists('hs_resolve_manufacturer')) {
  function hs_resolve_manufacturer(\PDO $pdo, $manufacturer_id, $manufacturer_name): int {
    $mid  = is_numeric($manufacturer_id) ? (int)$manufacturer_id : 0;
    $name = trim((string)$manufacturer_name);

    if ($mid > 0) {
      $row = qrow('SELECT id FROM hs_manufacturers WHERE id=? LIMIT 1', [$mid]);
      if ($row) return (int)$row['id'];
      hs_json(false, ['message'=>'Manufacturer not found', 'fields'=>['manufacturer_id'=>'unknown']], 400);
    }

    if ($name === '') {
      hs_json(false, ['message'=>'Manufacturer required', 'fields'=>['manufacturer'=>'required']], 400);
    }

    // Try by name first
    $row = qrow('SELECT id FROM hs_manufacturers WHERE name=? LIMIT 1', [$name]);
    if ($row) return (int)$row['id'];

    // Ensure row + data table via shared helper
    $m = hs_ensure_manufacturer($pdo, $name);
    return (int)($m['id'] ?? 0);
  }
}

/** Find a PHP CLI */
function hs_resolve_php_cli(debug_sentinel $s): ?string {
  $attempts = [];
  $add = function(?string $p) use (&$attempts) { if ($p && !in_array($p, $attempts, true)) $attempts[] = $p; };
  if (PHP_SAPI === 'cli') $add(PHP_BINARY);
  if (stripos(PHP_OS, 'WIN') === 0) {
    $add(getenv('PHP_CLI') ?: null);
    $add('C:\\xampp\\php\\php.exe');
    $add(realpath(dirname(PHP_BINARY) . '\\..\\..\\php\\php.exe'));
  }
  $add(PHP_BINARY);
  $add(PHP_BINDIR . DIRECTORY_SEPARATOR . 'php');
  $add('/usr/bin/php');
  $add('/usr/local/bin/php');
  $add('php');
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
  hs_slog($s, 'error', 'phpcli.resolve', 'no working PHP CLI found', ['php_binary' => PHP_BINARY, 'sapi' => PHP_SAPI]);
  return null;
}

/** Spawn detached worker: bin/hs_worker.php <runId> <uploadId> */
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
    $full = 'cmd /c start "" /B ' . $cmd . ' > NUL 2>&1';
    hs_slog($s, 'info', 'spawn', 'exec.win', ['cmd' => $full]);
    @pclose(@popen($full, 'r'));
    return true;
  } else {
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
      $in = hs_read_input();
      $id = hs_int($in['id'] ?? ($_GET['id'] ?? 0));
      hs_json(true, HS\JobsController::get($pdo, $id));
    }

    case 'jobs.save': {
      require __DIR__ . '/JobsController.php';

      $in = hs_read_input();

      // Normalize + defaults
      $in['title']     = trim((string)($in['title']     ?? ''));
      $in['file_path'] = trim((string)($in['file_path'] ?? ''));
      $in['mode']      = (string)($in['mode'] ?? 'replace'); // replace|merge
      $in['enabled']   = (int)!!($in['enabled'] ?? 1);

      // Validate early
      $fieldErrors = [];
      if ($in['title'] === '')     $fieldErrors['title'] = 'required';
      if ($in['file_path'] === '') $fieldErrors['file_path'] = 'required';
      if (!in_array($in['mode'], ['replace','merge'], true)) $fieldErrors['mode'] = 'invalid';
      if ($fieldErrors) {
        hs_json(false, ['message'=>'Validation failed', 'fields'=>$fieldErrors], 400);
      }

      // Resolve manufacturer_id from either id or name (create if needed)
      $manufacturer_id = $in['manufacturer_id'] ?? null;
      $manufacturer    = $in['manufacturer']    ?? null;
      $mfg_id = hs_resolve_manufacturer($pdo, $manufacturer_id, $manufacturer);
      $in['manufacturer_id'] = $mfg_id;
      unset($in['manufacturer']);

      // JSON columns: ensure arrays (controller will encode)
      $in['columns_map'] = hs_json_to_array($in['columns_map'] ?? []);
      $in['transforms']  = hs_json_to_array($in['transforms']  ?? []);

      try {
        $out = HS\JobsController::save($pdo, $in);
        hs_json(true, $out);
      } catch (\Throwable $e) {
        hs_slog($sentinel, 'error', 'jobs.save', 'exception', ['msg' => $e->getMessage()]);
        hs_json(false, $e->getMessage(), 500);
      }
    }

    case 'jobs.delete': {
      require __DIR__ . '/JobsController.php';
      $in = hs_read_input();
      $id = hs_int($in['id'] ?? 0);
      HS\JobsController::delete($pdo, $id);
      hs_json(true, ['deleted'=>true]);
    }

    case 'jobs.run': {
      $in = hs_read_input();
      $jobId = hs_int($in['id'] ?? 0);
      if ($jobId <= 0) hs_json(false, 'Missing job id', 400);

      require __DIR__ . '/JobsController.php';
      require __DIR__ . '/UploadsController.php';
      require_once __DIR__ . '/Log.php';

      hs_slog($sentinel, 'info', 'jobs.run', 'invoked', [
        'job_id'  => $jobId,
        'in_keys' => array_keys((array)$in),
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
        HS\Log::add($pdo, $jobId, $runId ?: null, 'error', 'Failed to initialize run/upload', 'error');
        \qexec("UPDATE hs_runs SET status='failed', finished_at=NOW(), error_message=? WHERE id=?", ['Worker spawn failed', $runId]);
        hs_json(false, 'Failed to initialize run/upload', 500);
      }

      // 2) Detach session
      @session_write_close();
      ignore_user_abort(true);

      // 3) Pre-spawn diagnostics
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

      // 4) Spawn worker
      $spawnOk = hs_spawn_worker($sentinel, $runId, $uploadId);

      hs_slog($sentinel, $spawnOk ? 'info' : 'error', 'jobs.run', 'spawn result', [
        'spawn_ok'  => $spawnOk,
        'run_id'    => $runId,
        'upload_id' => $uploadId,
      ]);

      if (!$spawnOk) {
        HS\Log::add($pdo, $jobId, $runId, 'error', 'Worker spawn failed', 'error');
        \qexec("UPDATE hs_runs SET status='failed', finished_at=NOW(), error_message=? WHERE id=?", ['Worker spawn failed', $runId]);
        hs_json(false, 'Failed to start background worker', 500);
      }

      // 5) Return JSON
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

      if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
      else { @flush(); @ob_flush(); }
      exit;
    }

    case 'runs.latest_for_job': {
      $in = hs_read_input();
      $jobId = hs_int($in['job_id'] ?? ($_GET['job_id'] ?? 0));
      if ($jobId <= 0) { hs_json(false, 'Missing job_id', 400); }
      $row = qrow("SELECT id FROM hs_runs WHERE job_id=? ORDER BY id DESC LIMIT 1", [$jobId]);
      hs_json(true, ['run_id' => (int)($row['id'] ?? 0)]);
    }

    /* -------------------- Runs / Status -------------------- */
    case 'runs.status': {
      $in = hs_read_input();
      $runId = hs_int($in['run_id'] ?? 0);

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

    /* ---------- UI compatibility: import.status (accept job_id) ---------- */
    case 'import.status': {
      $in = hs_read_input();

      // Try run_id directly
      $runId = hs_int($in['run_id'] ?? ($_GET['run_id'] ?? 0));

      // Resolve by job_id -> latest run
      if ($runId <= 0) {
        $jobId = hs_int($in['job_id'] ?? ($_GET['job_id'] ?? 0));
        if ($jobId > 0) {
          $row = qrow("SELECT id FROM hs_runs WHERE job_id=? ORDER BY id DESC LIMIT 1", [$jobId]);
          if ($row) $runId = (int)$row['id'];
        }
      }

      // Or via upload_id
      if ($runId <= 0 && !empty($in['upload_id'])) {
        $up = qrow('SELECT run_id FROM hs_uploads WHERE id=? LIMIT 1', [hs_int($in['upload_id'])]);
        if ($up) $runId = (int)$up['run_id'];
      }

      // If still nothing, return neutral pending payload (no 400)
      if ($runId <= 0) {
        $pending = ['run'=>null, 'progress'=>['status'=>'pending','rows_total'=>0,'rows_done'=>0,'percent'=>0.0]];
        hs_json(true, $pending);
      }

      $out = \HS\ImportWorker::status($pdo, $runId);
      $run      = $out['run'] ?? null;
      $progress = $out['progress'] ?? null;
      $status   = $run['status'] ?? 'pending';
      $errMsg   = $run['error_message'] ?? null;

      hs_slog($sentinel, 'info', 'import.status', 'read', [
        'run_id'    => $runId,
        'status'    => $status,
        'has_prog'  => (bool)$progress,
        'err_msg'   => $errMsg ? mb_substr((string)$errMsg, 0, 160) : null,
      ]);

      hs_json(true, [
        'run'           => $run,
        'progress'      => $progress,
        'status'        => $status,
        'error_message' => $errMsg,
      ]);
    }

    /* -------------------- Uploads / Picker -------------------- */
    case 'picker.list': {
      require __DIR__ . '/UploadsController.php';
      $in = hs_read_input();
      hs_json(true, HS\UploadsController::pickerList($in));
    }

    case 'upload.register': {
      require __DIR__ . '/UploadsController.php';
      $in = hs_read_input();
      hs_json(true, HS\UploadsController::register($pdo, $in, $sentinel));
    }

    /* -------------------- Global stop / resume -------------------- */
    case 'imports.stop_all': {
      qexec('INSERT INTO hs_control (k, v, updated_at)
             VALUES ("stop_all_at", NOW(), NOW())
             ON DUPLICATE KEY UPDATE v=NOW(), updated_at=NOW()', []);
      $open = ['pending','started','running','reading','inserting','cancelling'];
      $in   = implode(',', array_fill(0, count($open), '?'));
      $stmt = $pdo->prepare("UPDATE hs_runs SET status='cancelled', finished_at=NOW(), error_message=COALESCE(error_message,'Stopped by admin') WHERE status IN ($in)");
      $stmt->execute($open);
      $runsCancelled = $stmt->rowCount();

      qexec("DELETE p FROM hs_progress p JOIN hs_uploads u ON u.id = p.upload_id JOIN hs_runs r ON r.id = u.run_id WHERE r.status = 'cancelled'", []);

      qexec("UPDATE hs_import_jobs j
             JOIN (SELECT DISTINCT job_id FROM hs_runs WHERE status='cancelled' AND finished_at >= NOW() - INTERVAL 5 MINUTE) x
             ON x.job_id = j.id
             SET j.last_status = 'cancelled', j.last_run_at = NOW()", []);

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
      $in = hs_read_input();
      $payload = ['dry' => (int)($in['dry'] ?? 0), 'scope' => (string)($in['scope'] ?? 'all')];
      try { $out = \HS\AdminTools::reset($pdo, $payload, $sentinel); hs_json(true, $out); }
      catch (\Throwable $e) { hs_json(false, $e->getMessage(), 500); }
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

      if ($runId <= 0 && $jobId <= 0) hs_json(false, 'Missing run_id or job_id', 400);

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
	
	// controllers/hs_logic.php
	case 'runs.logs.clear': {
		$in = hs_read_input();
		$runId = hs_int($in['run_id'] ?? 0);
		$jobId = hs_int($in['job_id'] ?? 0);

		if ($runId <= 0 && $jobId <= 0) {
			hs_json(false, 'Missing run_id or job_id', 422);
		}

		try {
			$deleted = 0;

			// table existence helper
			$has = static function (string $table): bool {
				$sql = "SELECT COUNT(*) FROM information_schema.tables
						WHERE table_schema = DATABASE() AND table_name = ?";
				return (int)qcell($sql, [$table]) > 0;
			};

			// Use the same PDO used elsewhere
			/** @var PDO $pdo */
			$pdo = hs_pdo();

			if ($has('hs_logs')) {
				if ($runId > 0) {
					$stmt = $pdo->prepare("DELETE FROM `hs_logs` WHERE `run_id` = ?");
					$stmt->execute([$runId]);
					$deleted += (int)$stmt->rowCount();
				}
				if ($jobId > 0) {
					$stmt = $pdo->prepare("DELETE FROM `hs_logs` WHERE `job_id` = ?");
					$stmt->execute([$jobId]);
					$deleted += (int)$stmt->rowCount();
				}
			}

			// Optional: also clear debug_sentinel for this HS context
			if ($has('debug_sentinel')) {
				if ($runId > 0) {
					$stmt = $pdo->prepare("DELETE FROM `debug_sentinel` WHERE `context`='health_system' AND `group_id` = ?");
					$stmt->execute([$runId]);
					$deleted += (int)$stmt->rowCount();
				}
				if ($jobId > 0) {
					$stmt = $pdo->prepare("DELETE FROM `debug_sentinel` WHERE `context`='health_system' AND `group_id` = ?");
					$stmt->execute([$jobId]);
					$deleted += (int)$stmt->rowCount();
				}
			}

			hs_json(true, [
				'deleted' => $deleted,
				'scope'   => $runId ? 'run' : 'job',
				'target'  => $runId ?: $jobId,
			]);
		} catch (Throwable $e) {
			hs_json(false, 'Clear failed: ' . $e->getMessage(), 500);
		}
	}




    /* -------------------- Import lifecycle -------------------- */
    case 'imports.start':
    case 'imports.start_preview':
    case 'import.preview': {
      hs_slog($sentinel, 'warn', 'import.legacy', 'legacy import path called', ['action'=>$action]);
      hs_json(false, 'This endpoint is deprecated. Use action=import.start', 410);
    }

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

      hs_slog($sentinel, 'info', 'import.start', 'invoked', ['raw_keys' => array_keys((array)$in), 'job_id' => $jobId]);
      if ($jobId <= 0) { hs_slog($sentinel, 'error', 'import.start', 'missing job_id'); hs_json(false, 'Missing job_id', 400); }

      $payload = ['job_id' => $jobId];
      if (isset($in['upload_id']))   $payload['upload_id']   = hs_int($in['upload_id']);
      if (isset($in['stored_path'])) $payload['stored_path'] = (string)$in['stored_path'];

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

    /* -------------------- Default -------------------- */
    default: {
      hs_json(false, 'Unknown action', 400);
    }
  }
} catch (\Throwable $e) {
  hs_json(false, $e->getMessage(), 500);
}
