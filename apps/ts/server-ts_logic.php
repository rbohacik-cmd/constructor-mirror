<?php
declare(strict_types=1);

// JSON headers
@ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Ensure bootstrap (defines PROJECT_FS/BASE_URL/helpers)
if (!defined('PROJECT_FS')) {
  require_once __DIR__ . '/../../partials/bootstrap.php';
}

// Core includes via PROJECT_FS
require_once PROJECT_FS . '/appcfg.php';
require_once PROJECT_FS . '/db.php';

// Tiny request helpers
function gs(string $k, $def = ''): string { return (string)($_GET[$k]  ?? $def); }
function gi(string $k, int $def = 0): int { return (int)   ($_GET[$k]  ?? $def); }
function ps(string $k, $def = ''): string { return (string)($_POST[$k] ?? $def); }

// Optional session (for favorites user_id); no-op if already active
if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
  @session_start();
}

try {
  // ---------- Resolve server (must be MSSQL) ----------
  $server = gs('server', '');
  $resolvedKey = '';
  if ($server !== '') {
    if (!server_exists($server)) { echo json_encode(['ok'=>false,'error'=>'Unknown server key: '.$server]); exit; }
    if (!is_mssql($server))      { echo json_encode(['ok'=>false,'error'=>'Server "'.$server.'" is not MSSQL.']); exit; }
    $resolvedKey = $server;
  } else {
    foreach (servers() as $k => $s) {
      $t = strtolower((string)($s['type'] ?? ''));
      if ($t === 'mssql' || $t === 'sqlsrv') { $resolvedKey = (string)$k; break; }
    }
    if ($resolvedKey === '') { echo json_encode(['ok'=>false,'error'=>'No MSSQL server configured.']); exit; }
  }

  // =====================================================
  // Favorites helpers (safe / optional; uses default MySQL via PDO)
  // =====================================================
  $favTable = 'ts_favorites';
  $userId   = !empty($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;

  function fav_available(): bool {
    try { db(); return true; } catch (Throwable $e) { return false; }
  }

  function fav_ensure_table(): bool {
    try {
      qexec("
        CREATE TABLE IF NOT EXISTS `ts_favorites` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` INT NOT NULL DEFAULT 0,
          `server_key` VARCHAR(64) NOT NULL,
          `db_name` VARCHAR(128) NOT NULL,
          `table_name` VARCHAR(128) NOT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_user_server_db_table` (`user_id`,`server_key`,`db_name`,`table_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");
      return true;
    } catch (Throwable $e) { return false; }
  }

  function fav_list(int $userId, string $serverKey, string $dbName, string $favTable): array {
    if ($dbName === '') return [];
    try {
      $rows = qall("
        SELECT table_name FROM {$favTable}
        WHERE user_id=? AND server_key=? AND db_name=?
        ORDER BY table_name
      ", [$userId, $serverKey, $dbName]);
      return array_map(fn($r)=> (string)$r['table_name'], $rows ?: []);
    } catch (Throwable $e) {
      return [];
    }
  }

  function fav_toggle(string $favTable, int $userId, string $serverKey, string $dbName, string $tableName): array {
    if ($dbName === '' || $tableName === '') return ['ok'=>false,'fav'=>false,'error'=>'Missing db or table'];
    if (!fav_available()) return ['ok'=>false,'fav'=>false,'error'=>'Favorites DB unavailable'];

    fav_ensure_table();

    try {
      $row = qrow("
        SELECT 1 AS x FROM {$favTable}
        WHERE user_id=? AND server_key=? AND db_name=? AND table_name=?
        LIMIT 1
      ", [$userId, $serverKey, $dbName, $tableName]);
      $exists = !empty($row);

      if ($exists) {
        qexec("
          DELETE FROM {$favTable}
          WHERE user_id=? AND server_key=? AND db_name=? AND table_name=?
        ", [$userId, $serverKey, $dbName, $tableName]);
        return ['ok'=>true,'fav'=>false,'table'=>$tableName];
      } else {
        qexec("
          INSERT INTO {$favTable} (user_id, server_key, db_name, table_name)
          VALUES (?,?,?,?)
        ", [$userId, $serverKey, $dbName, $tableName]);
        return ['ok'=>true,'fav'=>true,'table'=>$tableName];
      }
    } catch (Throwable $e) {
      return ['ok'=>false,'fav'=>false,'error'=>$e->getMessage()];
    }
  }

  // ---------- Actions for favorites ----------
  $action = strtolower(gs('action', 'state'));

  if ($action === 'fav_toggle') {
    $dbName    = ps('db', '');
    $tableName = preg_replace('~[^a-zA-Z0-9_]+~','', ps('table', ''));
    $res = fav_toggle($favTable, $userId, $resolvedKey, $dbName, $tableName);
    http_response_code(200);
    echo json_encode($res);
    exit;
  }

  if ($action === 'fav_list') {
    $dbName = gs('db', '');
    $favorites = fav_list($userId, $resolvedKey, $dbName, $favTable);
    http_response_code(200);
    echo json_encode(['ok'=>true,'favorites'=>$favorites]);
    exit;
  }

  // ---------- Inputs + pagination ----------
  $qDb     = trim(gs('qdb', ''));
  $qTable  = trim(gs('q',   ''));
  $qVal    = trim(gs('qv',  ''));                         // row value search
  $qValCol = preg_replace('~[^a-zA-Z0-9_]+~','', gs('qvcol', '')); // target column (optional)
  $table   = preg_replace('~[^a-zA-Z0-9_]+~','', gs('table', ''));

  $tpage   = max(1, gi('tpage', 1));
  $tper    = min(200, max(5, gi('tper', 25)));
  $toffset = ($tpage - 1) * $tper;

  $rpage   = max(1, gi('rpage', 1));
  $rper    = min(200, max(5, gi('rper', 25)));
  $roffset = ($rpage - 1) * $rper;

  // ---------- DB list ----------
  $dbRows = qall("SELECT name FROM sys.databases ORDER BY name", [], null, $resolvedKey);
  $dbs = array_map(fn($r)=> (string)$r['name'], $dbRows);

  $selectedDb = gs('db', '');
  if ($selectedDb !== '' && in_array($selectedDb, $dbs, true)) {
    try { qexec("USE [$selectedDb]", [], null, $resolvedKey); } catch (Throwable $e) { $selectedDb = ''; }
  } else {
    $cur = qrow("SELECT DB_NAME() AS db", [], null, $resolvedKey);
    $selectedDb = (string)($cur['db'] ?? '');
  }

  $filteredDbs = ($qDb === '') ? $dbs : array_values(array_filter($dbs, fn($d)=> stripos($d,$qDb)!==false));

  // ---------- Full table list ----------
  $allTables = [];
  if ($selectedDb !== '') {
    try {
      qexec("USE [$selectedDb]", [], null, $resolvedKey);
      $allTables = array_map(fn($r)=> (string)$r['name'],
        qall("SELECT name FROM sys.tables ORDER BY name", [], null, $resolvedKey)
      );
    } catch (Throwable $e) {
      $allTables = [];
    }
  }
  if ($qTable !== '') {
    $allTables = array_values(array_filter($allTables, fn($t)=> stripos($t, $qTable)!==false));
  }

  // Favorites for this server+db (safe even if MySQL down)
  $favorites = fav_available() ? fav_list($userId, $resolvedKey, $selectedDb, $favTable) : [];

  $totalTables = count($allTables);
  $tmax = max(1, (int)ceil($totalTables / max(1, $tper)));
  if ($tpage > $tmax) { $tpage = $tmax; $toffset = ($tpage - 1) * $tper; }
  $tables = array_slice($allTables, $toffset, $tper);

  // ---------- Structure + rows ----------
  $cols = [];
  $rowCount = 0;
  $rows = [];
  $colNames = [];

  if ($selectedDb !== '' && $table !== '' && in_array($table, $allTables, true)) {
    try {
      qexec("USE [$selectedDb]", [], null, $resolvedKey);

      // Structure
      $cols = qall("
        SELECT
          c.name       AS [Column],
          t.name       AS [Type],
          c.max_length,
          c.precision,
          c.scale,
          c.is_nullable
        FROM sys.columns c
        JOIN sys.types  t  ON t.user_type_id = c.user_type_id
        JOIN sys.tables tb ON tb.object_id   = c.object_id
        WHERE tb.name = ?
        ORDER BY c.column_id
      ", [ $table ], null, $resolvedKey);

      // Column list for SELECT / dropdown
      $pick = array_map(fn($r)=> $r['Column'], $cols);
      $colList  = $pick ? ('[' . implode('],[', $pick) . ']') : '*';
      $colNames = $pick ?: [];

      // WHERE builder for value search
      $whereSql   = '';
      $whereParam = [];
      if ($qVal !== '' && !empty($cols)) {
        $like = '%'.$qVal.'%';

        $colTypes = [];
        foreach ($cols as $c) {
          $colTypes[(string)$c['Column']] = strtolower((string)$c['Type']);
        }
        $textTypes = ['nvarchar','varchar','nchar','char','text','ntext','sysname'];

        if ($qValCol !== '' && isset($colTypes[$qValCol])) {
          $t = $colTypes[$qValCol];
          $expr = in_array($t, $textTypes, true)
            ? "[{$qValCol}] LIKE ?"
            : "TRY_CONVERT(NVARCHAR(4000), [{$qValCol}]) LIKE ?";
          $whereSql = "WHERE {$expr}";
          $whereParam[] = $like;
        } else {
          $exprs = [];
          foreach ($cols as $c) {
            $cn = (string)$c['Column'];
            $t  = strtolower((string)$c['Type']);
            $exprs[] = in_array($t, $textTypes, true)
              ? "[$cn] LIKE ?"
              : "TRY_CONVERT(NVARCHAR(4000), [$cn]) LIKE ?";
            $whereParam[] = $like;
          }
          if ($exprs) $whereSql = 'WHERE ('.implode(' OR ', $exprs).')';
        }
      }

      // Timeouts (seconds) for potentially heavy queries
      $to = ($qVal !== '') ? 60 : null;

      // Count with optional WHERE
      $cnt = qrow("SELECT COUNT(*) AS c FROM [$table] WITH (NOLOCK) $whereSql", $whereParam, $to, $resolvedKey);
      $rowCount = (int)($cnt['c'] ?? 0);

      // Clamp rows pager
      $rmax = max(1, (int)ceil($rowCount / max(1, $rper)));
      if ($rpage > $rmax) { $rpage = $rmax; $roffset = ($rpage - 1) * $rper; }

      $off = max(0, (int)$roffset);
      $per = max(1, (int)$rper);

      $sql = "
        SELECT {$colList}
        FROM [$table] WITH (NOLOCK)
        $whereSql
        ORDER BY 1
        OFFSET {$off} ROWS FETCH NEXT {$per} ROWS ONLY
      ";
      $rows = qall($sql, $whereParam, $to, $resolvedKey);
    } catch (Throwable $e) {
      http_response_code(200);
      echo json_encode([
        'ok'=>false,
        'error'=>'Query error: '.$e->getMessage(),
        'state'=>[
          'server'=>$resolvedKey,
          'qdb'=>$qDb,
          'q'=>$qTable,
          'qv'=>$qVal,
          'qvcol'=>$qValCol,
          'dbs'=>$dbs,
          'filteredDbs'=>$filteredDbs,
          'selectedDb'=>$selectedDb,
          'tables'=>$tables,
          'totalTables'=>$totalTables,
          'table'=>$table,
          'tpage'=>$tpage,'tper'=>$tper,
          'rpage'=>$rpage,'rper'=>$rper,
          'cols'=>$cols,
          'rows'=>[],
          'rowCount'=>$rowCount,
          'colNames'=>$colNames,
          'favorites'=>$favorites
        ]
      ]);
      exit;
    }
  }

  http_response_code(200);
  echo json_encode([
    'ok'=>true,
    'state'=>[
      'server'=>$resolvedKey,
      'qdb'=>$qDb,
      'q'=>$qTable,
      'qv'=>$qVal,
      'qvcol'=>$qValCol,
      'dbs'=>$dbs,
      'filteredDbs'=>$filteredDbs,
      'selectedDb'=>$selectedDb,
      'tables'=>$tables,
      'totalTables'=>$totalTables,
      'table'=>$table,
      'tpage'=>$tpage,'tper'=>$tper,
      'rpage'=>$rpage,'rper'=>$rper,
      'cols'=>$cols,
      'rows'=>$rows,
      'rowCount'=>$rowCount,
      'colNames'=>$colNames,
      'favorites'=>$favorites
    ]
  ]);
} catch (Throwable $e) {
  http_response_code(200);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
