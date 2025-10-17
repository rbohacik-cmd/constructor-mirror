<?php
declare(strict_types=1);

// Transfer storage (MySQL). Tables:
// - xfer_jobs
// - xfer_runs_log (expects: id, job_id, started_at, finished_at, status, rows_read, rows_written, message, last_heartbeat)
require_once __DIR__ . '/../../db.php';

// ---------------- Jobs ----------------

function xfer_job_all(): array {
    return qall("SELECT * FROM xfer_jobs ORDER BY id DESC");
}

function xfer_job_get(int $id): ?array {
    $row = qrow("SELECT * FROM xfer_jobs WHERE id = ?", [$id]);
    return $row ?: null;
}

/**
 * Save job (adjust $cols to match your schema if needed).
 */
function xfer_job_save(?int $id, array $data): int {
    $cols = [
        'title','src_type','src_server_key','src_db','src_table','src_cols_json','where_clause',
        'dest_type','dest_server_key','dest_db','dest_table','column_map_json','mode','batch_size'
    ];
    $vals = [];
    foreach ($cols as $c) { $vals[$c] = $data[$c] ?? null; }
    $vals['src_cols_json']   = $vals['src_cols_json']   ?: '[]';
    $vals['column_map_json'] = $vals['column_map_json'] ?: '{}';
    $vals['batch_size']      = max(1, (int)($vals['batch_size'] ?? 1000));

    if ($id) {
        $set = implode(', ', array_map(fn($c)=>"$c = :$c", $cols));
        qexec("UPDATE xfer_jobs SET $set, updated_at = NOW() WHERE id = :id", $vals + ['id'=>$id]);
        return $id;
    } else {
        $names = implode(',', $cols);
        $binds = implode(',', array_map(fn($c)=>":$c", $cols));
        qexec("INSERT INTO xfer_jobs ($names) VALUES ($binds)", $vals);
        return (int)qcell("SELECT LAST_INSERT_ID()");
    }
}

function xfer_job_delete(int $id): void {
    qexec("DELETE FROM xfer_jobs WHERE id = ?", [$id]);
}

// ---------------- Runs (xfer_runs_log) ----------------

/** Create a new run row for a job and return run_id. */
function xfer_run_start(int $job_id, string $msg = ''): int {
    qexec("
        INSERT INTO xfer_runs_log (job_id, started_at, last_heartbeat, status, rows_read, rows_written, message)
        VALUES (?, NOW(), NOW(), 'running', 0, 0, ?)
    ", [$job_id, $msg]);
    return (int)qcell("SELECT LAST_INSERT_ID()");
}

/** Finish a run (status = 'ok' | 'error' | 'done' etc.). */
function xfer_run_finish(int $run_id, string $status = 'ok', ?int $rows_read = null, ?int $rows_written = null, string $msg = ''): void {
    $set = ["finished_at = NOW()", "status = ?"];
    $args = [$status];

    if ($rows_read !== null)    { $set[] = "rows_read = ?";     $args[] = $rows_read; }
    if ($rows_written !== null) { $set[] = "rows_written = ?";  $args[] = $rows_written; }
    if ($msg !== '')            { $set[] = "message = ?";       $args[] = $msg; }
    $set[] = "last_heartbeat = NOW()"; // final heartbeat

    $args[] = $run_id;
    qexec("UPDATE xfer_runs_log SET ".implode(', ', $set)." WHERE id = ?", $args);
}

/** Update counters by delta (useful while processing). */
function xfer_run_bump(int $run_id, int $readDelta = 0, int $writeDelta = 0): void {
    if ($readDelta === 0 && $writeDelta === 0) return;
    qexec("
        UPDATE xfer_runs_log
        SET rows_read = rows_read + ?, rows_written = rows_written + ?, last_heartbeat = NOW()
        WHERE id = ?
    ", [$readDelta, $writeDelta, $run_id]);
}

/** Append/replace message (choose mode). Default replaces. */
function xfer_run_message(int $run_id, string $msg, bool $append = false): void {
    if ($append) {
        qexec("
            UPDATE xfer_runs_log
            SET message = TRIM(CONCAT(COALESCE(message,''), CASE WHEN message='' OR message IS NULL THEN '' ELSE '\n' END, ?)),
                last_heartbeat = NOW()
            WHERE id = ?
        ", [$msg, $run_id]);
    } else {
        qexec("
            UPDATE xfer_runs_log
            SET message = ?, last_heartbeat = NOW()
            WHERE id = ?
        ", [$msg, $run_id]);
    }
}

/** List runs for a job. */
function xfer_run_list(int $job_id, int $limit = 200): array {
    return qall("
        SELECT r.*, j.title
        FROM xfer_runs_log r
        LEFT JOIN xfer_jobs j ON j.id = r.job_id
        WHERE r.job_id = ?
        ORDER BY r.id DESC
        LIMIT ?
    ", [$job_id, $limit]);
}

// ---------------- Reliability helpers ----------------

/**
 * Install fail-safe handlers so a crash can't leave the run in 'running'.
 * Call right after xfer_run_start($job_id, ...).
 */
function xfer_run_guard_init(int $run_id): void {
    // initial heartbeat
    qexec("UPDATE xfer_runs_log SET last_heartbeat = NOW() WHERE id = ?", [$run_id]);

    register_shutdown_function(function() use ($run_id) {
        $st = (string)qcell("SELECT status FROM xfer_runs_log WHERE id = ?", [$run_id]);
        if ($st === 'running') {
            $err = error_get_last();
            $msg = $err ? ("SHUTDOWN: {$err['message']} @ {$err['file']}:{$err['line']}") : 'SHUTDOWN: terminated unexpectedly';
            xfer_run_finish($run_id, 'error', null, null, $msg);
        }
    });

    set_exception_handler(function(Throwable $e) use ($run_id) {
        $st = (string)qcell("SELECT status FROM xfer_runs_log WHERE id = ?", [$run_id]);
        if ($st === 'running') {
            xfer_run_finish($run_id, 'error', null, null, 'EXCEPTION: '.$e->getMessage());
        }
        // rethrow to keep upstream logging
        throw $e;
    });

    set_error_handler(function($severity, $message, $file, $line) {
        // escalate to exception so set_exception_handler above handles it
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}

/**
 * Heartbeat + optional counter deltas + optional note.
 * Call periodically inside long loops (e.g., every 100 items).
 */
function xfer_run_heartbeat(int $run_id, int $readDelta = 0, int $writeDelta = 0, ?string $note = null): void {
    $parts = ["last_heartbeat = NOW()"];
    $args  = [];

    if ($readDelta)  { $parts[] = "rows_read = rows_read + ?";     $args[] = $readDelta; }
    if ($writeDelta) { $parts[] = "rows_written = rows_written + ?";$args[] = $writeDelta; }
    if ($note !== null && $note !== '') {
        $parts[] = "message = TRIM(CONCAT(COALESCE(message,''), CASE WHEN message='' OR message IS NULL THEN '' ELSE '\n' END, ?))";
        $args[] = $note;
    }
    $args[] = $run_id;

    qexec("UPDATE xfer_runs_log SET ".implode(', ', $parts)." WHERE id = ?", $args);
}

/**
 * Mark running runs with stale heartbeat as 'error'.
 * Use from cron (e.g., every 5–10 minutes).
 */
function xfer_runs_mark_stale(int $maxAgeSeconds = 900): int {
    qexec("
      UPDATE xfer_runs_log
      SET status = 'error',
          finished_at = NOW(),
          message = TRIM(CONCAT(COALESCE(message,''), CASE WHEN message='' OR message IS NULL THEN '' ELSE '\n' END,
                 CONCAT('STALE: no heartbeat > ', ?, 's'))),
          last_heartbeat = NOW()
      WHERE status = 'running'
        AND (last_heartbeat IS NULL OR last_heartbeat < (NOW() - INTERVAL ? SECOND))
    ", [$maxAgeSeconds, $maxAgeSeconds]);

    return (int)qcell("SELECT ROW_COUNT()");
}
// --- Run status helpers ---

/** Return current status of a run (or null). */
function xfer_run_status(int $run_id): ?string {
    $st = qcell("SELECT status FROM xfer_runs_log WHERE id = ?", [$run_id]);
    return $st !== null ? (string)$st : null;
}

/** Ask the runner to stop gracefully (sets status = 'stopping' if still running). */
function xfer_run_request_stop(int $run_id, string $note = 'Stop requested'): bool {
    qexec("
        UPDATE xfer_runs_log
        SET status = 'stopping',
            message = TRIM(CONCAT(COALESCE(message,''), CASE WHEN message='' OR message IS NULL THEN '' ELSE '\n' END, ?)),
            last_heartbeat = NOW()
        WHERE id = ? AND status = 'running'
    ", [$note, $run_id]);
    return ((int)qcell("SELECT ROW_COUNT()")) > 0;
}

/** Should the runner abort its loop? */
function xfer_run_should_stop(int $run_id): bool {
    $st = xfer_run_status($run_id);
    return ($st === 'stopping');  // only this value triggers a graceful stop
}
