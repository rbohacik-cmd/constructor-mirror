<?php
declare(strict_types=1);

require_once __DIR__ . '/../../appcfg.php';
require_once __DIR__ . '/../transfer/storage.php';
require_once __DIR__ . '/connection_mssql.php';
require_once __DIR__ . '/query_mssql.php';
require_once __DIR__ . '/connection_mysql.php';
require_once __DIR__ . '/query_mysql.php';

/** Connect to a configured server and return PDO (mssql) or mysqli (mysql). */
function xfer_connect(string $type, string $serverKey) {
  if ($type === 'mssql') return mssql_connect_server($serverKey); // PDO
  if ($type === 'mysql') return mysql_connect_server($serverKey); // mysqli
  throw new RuntimeException("Unknown type: $type");
}

/** Truncate destination table once (used for mode 'truncate_insert'). */
function xfer_truncate_table($destConn, string $type, string $db, string $table): void {
  if ($type === 'mssql') {
    /** @var PDO $destConn */
    $destConn->query("USE [$db]");
    $destConn->exec("TRUNCATE TABLE [$table]");
  } else {
    /** @var mysqli $destConn */
    mysqli_select_db($destConn, $db);
    $destConn->query("TRUNCATE TABLE `$table`");
  }
}

/** Fetch one page of rows from source. */
function xfer_fetch_chunk($srcConn, string $type, string $db, string $table, array $cols, ?string $where, int $limit, int $offset): array {
  if ($type === 'mssql') {
    /** @var PDO $srcConn */
    $srcConn->query("USE [$db]");

    $off = max(0, (int)$offset);
    $per = max(1, (int)$limit);

    // NOTE: ORDER BY 1 is simple but only deterministic if 1st selected col is stable.
    // Consider changing to a specific PK for large tables.
    $colList = $cols ? '[' . implode('],[', $cols) . ']' : '*';
    $sql = "SELECT $colList FROM [$table]" . ($where ? " WHERE $where" : "") . "
            ORDER BY 1
            OFFSET {$off} ROWS FETCH NEXT {$per} ROWS ONLY";

    return mssql_all($srcConn, $sql, []); // no bound params for OFFSET/FETCH
  }

  /** @var mysqli $srcConn */
  mysqli_select_db($srcConn, $db);
  $colList = $cols ? ('`' . implode('`,`', $cols) . '`') : '*';
  $sql = "SELECT $colList FROM `$table`" . ($where ? " WHERE $where" : "") . " LIMIT ? OFFSET ?";
  return mysql_all($srcConn, $sql, [ (int)$limit, (int)$offset ]);
}

/** COUNT(*) helper. */
function xfer_count_rows($srcConn, string $type, string $db, string $table, ?string $where): int {
  if ($type === 'mssql') {
    /** @var PDO $srcConn */
    $srcConn->query("USE [$db]");
    $sql = "SELECT COUNT(*) AS c FROM [$table]" . ($where ? " WHERE $where" : "");
    $r   = mssql_one($srcConn, $sql);
    return (int)($r['c'] ?? 0);
  }

  /** @var mysqli $srcConn */
  mysqli_select_db($srcConn, $db);
  $sql = "SELECT COUNT(*) AS c FROM `$table`" . ($where ? " WHERE $where" : "");
  $res = $srcConn->query($sql);
  $row = $res ? $res->fetch_assoc() : null;
  return (int)($row['c'] ?? 0);
}

/** Bulk insert rows into destination using a source->dest column map. */
function xfer_write_rows($destConn, string $type, string $db, string $table, array $rows, array $colMap, string $mode='insert'): int {
  if (!$rows) return 0;

  // Normalize column map to assoc: source => dest
  if (array_is_list($colMap)) {
    $m = [];
    foreach ($colMap as $pair) {
      $m[(string)$pair['from']] = (string)$pair['to'];
    }
    $colMap = $m;
  }

  $destCols = array_values($colMap);
  $values   = [];
  foreach ($rows as $r) {
    $rowOut = [];
    foreach ($colMap as $from => $to) {
      $rowOut[] = $r[$from] ?? null;
    }
    $values[] = $rowOut;
  }

  if ($type === 'mssql') {
    /** @var PDO $destConn */
    $destConn->query("USE [$db]");

    // IMPORTANT: truncate is done ONCE before the loop in xfer_run_job()
    $colsSql = '[' . implode('],[', $destCols) . ']';
    $place   = '(' . implode(',', array_fill(0, count($destCols), '?')) . ')';
    $stmt    = $destConn->prepare("INSERT INTO [$table] ($colsSql) VALUES $place");

    $written = 0;
    $destConn->beginTransaction();
    try {
      foreach ($values as $rowVals) {
        $stmt->execute(array_values($rowVals));
        $written++;
      }
      $destConn->commit();
    } catch (Throwable $e) {
      $destConn->rollBack();
      throw $e;
    }
    return $written;
  }

  /** @var mysqli $destConn */
  mysqli_select_db($destConn, $db);

  // IMPORTANT: truncate is done ONCE before the loop in xfer_run_job()
  $colsSql = '`' . implode('`,`', $destCols) . '`';
  $place   = '(' . implode(',', array_fill(0, count($destCols), '?')) . ')';
  $stmt    = $destConn->prepare("INSERT INTO `$table` ($colsSql) VALUES $place");

  $written = 0;
  $destConn->begin_transaction();
  try {
    foreach ($values as $rowVals) {
      // Bind as strings for cross-type simplicity
      $types = str_repeat('s', count($rowVals));
      $stmt->bind_param($types, ...$rowVals);
      $stmt->execute();
      $written++;
    }
    $destConn->commit();
  } catch (Throwable $e) {
    $destConn->rollback();
    throw $e;
  }
  return $written;
}

/** Main: run a configured job and return run_id. */
function xfer_run_job(int $job_id): int {
  $job = xfer_job_get($job_id);
  if (!$job) throw new RuntimeException("Job $job_id not found");

  $srcCols = json_decode((string)$job['src_cols_json'], true) ?: [];
  $map     = json_decode((string)$job['column_map_json'], true) ?: [];
  $where   = $job['where_clause'] ?: null;

  // Create run + install guard so crashes never leave 'running'
  $run_id = xfer_run_start($job_id, 'Started via runner');
  xfer_run_guard_init($run_id);

  $rowsRead = 0;
  $rowsWritten = 0;

  try {
    $src  = xfer_connect($job['src_type'],  $job['src_server_key']);
    $dest = xfer_connect($job['dest_type'], $job['dest_server_key']);

    $total = xfer_count_rows($src, $job['src_type'], $job['src_db'], $job['src_table'], $where);
    $batch = max(1, (int)$job['batch_size']);
    $heartbeatEvery = 500; // rows

    // Handle truncate_insert ONCE before the loop
    $writeMode = (string)$job['mode'];
    if ($writeMode === 'truncate_insert') {
      xfer_truncate_table($dest, $job['dest_type'], $job['dest_db'], $job['dest_table']);
      $writeMode = 'insert'; // subsequent writes are simple inserts
      xfer_run_message($run_id, 'Destination truncated.', true);
    }

    for ($off = 0; $off < $total; $off += $batch) {
      // ✅ Manual stop: if user requested stop, finish gracefully
      if (xfer_run_should_stop($run_id)) {
        xfer_run_finish($run_id, 'stopped', $rowsRead, $rowsWritten, 'Stopped by user');
        return $run_id;
      }

      $chunk = xfer_fetch_chunk(
        $src,
        $job['src_type'],
        $job['src_db'],
        $job['src_table'],
        $srcCols,
        $where,
        $batch,
        $off
      );

      $count = count($chunk);
      $rowsRead += $count;

      if ($count > 0) {
        $w = xfer_write_rows(
          $dest,
          $job['dest_type'],
          $job['dest_db'],
          $job['dest_table'],
          $chunk,
          $map,
          $writeMode
        );
        $rowsWritten += $w;
      }

      // Heartbeat every N rows (or at the tail of the import)
      if (($rowsRead % $heartbeatEvery) === 0 || $count < $batch) {
        xfer_run_heartbeat(
          $run_id,
          0, 0,
          "Processed $rowsRead / $total; written $rowsWritten"
        );
      }
    }

    xfer_run_finish($run_id, 'ok', $rowsRead, $rowsWritten, "Done: written $rowsWritten from $rowsRead");
  } catch (Throwable $e) {
    // If we get here, finish explicitly (guard would also flip it on fatal)
    xfer_run_finish($run_id, 'error', $rowsRead, $rowsWritten, 'FAIL: '.$e->getMessage());
    throw $e;
  }

  return $run_id;
}
