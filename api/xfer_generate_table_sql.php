<?php
declare(strict_types=1);

/**
 * API: Generate (and optionally execute) a CREATE TABLE on the destination
 * from a selected source table + (optional) selected columns.
 *
 * Request (JSON):
 * {
 *   "src_type": "mssql" | "mysql",
 *   "src_server": "<server key>",
 *   "src_db": "<db name>",
 *   "src_table": "<table name>",
 *   "only_cols": ["ColA","ColB"] | [],  // optional; empty => all
 *
 *   "dest_type": "mysql" | "mssql",
 *   "dest_server": "<server key>",
 *   "dest_db": "<db name>",
 *   "dest_table": "<table name>",
 *
 *   "execute": 0|1                       // 0 = preview DDL, 1 = run it
 * }
 *
 * Response (JSON):
 *  - Preview: { ok:true, ddl:"...", notes:{columnsCount, pkChosen[], identityDetected:bool} }
 *  - Execute: { ok:true, executed:true }
 *  - Error:   { ok:false, error:"..." }
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json');

// Convert notices/warnings into exceptions so we can JSONify them.
set_error_handler(function($severity, $message, $file, $line) {
  if (!(error_reporting() & $severity)) return false;
  throw new ErrorException($message, 0, $severity, $file, $line);
});

/* ==============================
   Utilities
   ============================== */

// Backtick-quote for MySQL identifiers
function qi_mysql(string $name): string {
  return '`' . str_replace('`','``',$name) . '`';
}

// Bracket-quote for MSSQL identifiers
function qi_mssql(string $name): string {
  // tolerate already-quoted
  if (preg_match('~^\[.*\]$~', $name)) return $name;
  return '[' . str_replace(']', ']]', $name) . ']';
}

// JSON output helper
function json_out(array $payload, int $code = 200): never {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ==============================
   Load connectors
   ============================== */
require_once __DIR__ . '/../apps/transfer/connection_mssql.php';
require_once __DIR__ . '/../apps/transfer/query_mssql.php';
require_once __DIR__ . '/../apps/transfer/connection_mysql.php';
require_once __DIR__ . '/../apps/transfer/query_mysql.php';

/* ==============================
   MSSQL → MySQL type mapping
   ============================== */

/**
 * Map one MSSQL column (from sys catalog) to a MySQL column DDL fragment.
 * Expected keys:
 *  - name, type (lowercased), max_length (bytes; -1 for MAX), precision, scale,
 *  - is_nullable (0/1), is_identity (0/1)
 */
function map_mssql_col_to_mysql(array $c): string {
  $name   = (string)$c['name'];
  $t      = strtolower((string)$c['type']);
  $lenB   = (int)($c['max_length'] ?? 0);
  $prec   = (int)($c['precision']  ?? 0);
  $scale  = (int)($c['scale']      ?? 0);
  $ident  = !empty($c['is_identity']);
  $null   = !empty($c['is_nullable']);

  $nullable = $null ? 'NULL' : 'NOT NULL';
  $isMax    = ($lenB === -1);

  // NVARCHAR/NCHAR report bytes; convert to characters
  $lenChars = $lenB;
  if (in_array($t, ['nchar','nvarchar'], true) && $lenB > 0) {
    $lenChars = (int)ceil($lenB / 2);
  }

  switch ($t) {
    case 'bit':       $type = 'TINYINT(1)'; break;
    case 'tinyint':   $type = 'TINYINT';    break;
    case 'smallint':  $type = 'SMALLINT';   break;
    case 'int':       $type = 'INT';        break;
    case 'bigint':    $type = 'BIGINT';     break;

    case 'decimal':
    case 'numeric':
      if ($prec <= 0) $prec = 10;
      if ($scale < 0) $scale = 0;
      $type = "DECIMAL($prec,$scale)";
      break;

    case 'money':      $type = 'DECIMAL(19,4)'; break;
    case 'smallmoney': $type = 'DECIMAL(10,4)'; break;

    case 'float':      $type = 'DOUBLE'; break;
    case 'real':       $type = 'FLOAT';  break;

    case 'date':           $type = 'DATE'; break;
    case 'time':           $type = 'TIME'; break;
    case 'datetime':
    case 'smalldatetime':
    case 'datetime2':      $type = 'DATETIME'; break;
    case 'datetimeoffset': $type = 'DATETIME'; break; // TZ lost

    case 'uniqueidentifier':
      $type = 'CHAR(36)'; // canonical GUID text
      break;

    case 'binary':
      $type = $isMax ? 'LONGBLOB' : ('BINARY(' . max(1,$lenB) . ')');
      break;
    case 'varbinary':
      if ($isMax) {
        $type = 'LONGBLOB';
      } else {
        if ($lenB > 65535)       $type = 'LONGBLOB';
        elseif ($lenB > 16384)   $type = 'MEDIUMBLOB';
        elseif ($lenB > 255)     $type = 'BLOB';
        else                     $type = 'VARBINARY(' . max(1,$lenB) . ')';
      }
      break;

    case 'nchar':
    case 'nvarchar':
      if ($isMax) {
        $type = 'LONGTEXT';
      } else {
        if ($lenChars > 65535)        $type = 'LONGTEXT';
        elseif ($lenChars > 16384)    $type = 'MEDIUMTEXT';
        // 21845 is ~ max VARCHAR for utf8mb4 worst-case
        elseif ($lenChars > 21845)    $type = 'TEXT';
        else                          $type = 'VARCHAR(' . max(1,$lenChars) . ')';
      }
      break;

    case 'char':
    case 'varchar':
      if ($isMax) {
        $type = 'LONGTEXT';
      } else {
        if ($lenB > 65535)            $type = 'LONGTEXT';
        elseif ($lenB > 16384)        $type = 'MEDIUMTEXT';
        elseif ($lenB > 65535)        $type = 'TEXT';
        else                          $type = 'VARCHAR(' . max(1,$lenB) . ')';
      }
      break;

    case 'text':
    case 'ntext':  $type = 'LONGTEXT';  break;
    case 'xml':    $type = 'LONGTEXT';  break;
    case 'image':  $type = 'LONGBLOB';  break;

    default:
      $type = 'LONGTEXT'; // safe fallback for exotic types
      break;
  }

  $extra = '';
  if ($ident && preg_match('~^(TINYINT|SMALLINT|INT|BIGINT)\b~i', $type)) {
    $extra = ' AUTO_INCREMENT';
  }

  return qi_mysql($name) . ' ' . $type . ' ' . $nullable . $extra;
}

function build_mysql_create_from_mssql(string $destTable, array $cols, array $primaryKeyCols = []): string {
  $lines = [];
  foreach ($cols as $c) {
    $lines[] = '  ' . map_mssql_col_to_mysql($c);
  }
  if (!empty($primaryKeyCols)) {
    $qpk = array_map('qi_mysql', $primaryKeyCols);
    $lines[] = '  PRIMARY KEY (' . implode(',', $qpk) . ')';
  }
  $sql  = "CREATE TABLE " . qi_mysql($destTable) . " (\n";
  $sql .= implode(",\n", $lines) . "\n";
  $sql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
  return $sql;
}

/* ==============================
   Read source metadata
   ============================== */

function fetch_mssql_columns(PDO $pdo, string $db, string $table): array {
  $pdo->query('USE ' . qi_mssql($db));
  // Grab columns
  $cols = mssql_all($pdo, "
    SELECT
      c.name                           AS name,
      LOWER(t.name)                    AS type,
      c.max_length,
      c.precision,
      c.scale,
      c.is_nullable,
      c.is_identity
    FROM sys.columns c
    JOIN sys.types t ON t.user_type_id = c.user_type_id
    JOIN sys.tables tb ON tb.object_id = c.object_id
    WHERE tb.name = :t
    ORDER BY c.column_id
  ", [':t' => $table]);

  // PK columns
  $pkRows = mssql_all($pdo, "
    SELECT c.name
    FROM sys.indexes i
    JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id
    JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
    JOIN sys.tables tb ON tb.object_id=i.object_id
    WHERE i.is_primary_key = 1 AND tb.name = :t
    ORDER BY ic.key_ordinal
  ", [':t'=>$table]);
  $pk = array_map(fn($r)=>$r['name'], $pkRows);

  return ['cols'=>$cols, 'pk'=>$pk];
}

function fetch_mysql_columns(mysqli $db, string $database, string $table): array {
  $db->select_db($database);
  $cols = [];
  $pk   = [];
  if ($r = $db->query("SHOW COLUMNS FROM " . qi_mysql($table))) {
    while ($c = $r->fetch_assoc()) {
      $cols[] = [
        'name'        => $c['Field'],
        'type'        => strtolower($c['Type']),
        'max_length'  => null,
        'precision'   => null,
        'scale'       => null,
        'is_nullable' => (strtoupper($c['Null']) === 'YES') ? 1 : 0,
        'is_identity' => 0,
      ];
      if (strtoupper($c['Key']) === 'PRI') $pk[] = $c['Field'];
    }
  }
  return ['cols'=>$cols, 'pk'=>$pk];
}

/* ==============================
   MySQL → MySQL cloning (SHOW CREATE)
   ============================== */

function mysql_clone_create_table_sql(mysqli $db, string $database, string $srcTable, string $destTable): string {
  $db->select_db($database);
  $r = $db->query("SHOW CREATE TABLE " . qi_mysql($srcTable));
  if (!$r) throw new RuntimeException("SHOW CREATE failed: " . $db->error);
  $row = $r->fetch_assoc();
  $create = $row['Create Table'] ?? '';
  if ($create === '') throw new RuntimeException("SHOW CREATE returned empty DDL");

  // Replace table name at the start: CREATE TABLE `src` ( ... )
  // Safe-ish swap: first occurrence after CREATE TABLE
  $create = preg_replace(
    '~^(\s*CREATE\s+TABLE\s+)`[^`]+`~i',
    '$1' . qi_mysql($destTable),
    $create,
    1
  );

  return $create . ';';
}

/* ==============================
   Main
   ============================== */

try {
  $raw = file_get_contents('php://input');
  if ($raw === '' || $raw === false) {
    throw new RuntimeException('Empty request body');
  }
  $p = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

  foreach (['src_type','src_server','src_db','src_table','dest_type','dest_server','dest_db','dest_table'] as $k) {
    if (empty($p[$k])) throw new InvalidArgumentException("Missing: $k");
  }

  $srcType   = strtolower((string)$p['src_type']);
  $srcServer = (string)$p['src_server'];
  $srcDb     = (string)$p['src_db'];
  $srcTable  = (string)$p['src_table'];

  $destType   = strtolower((string)$p['dest_type']);
  $destServer = (string)$p['dest_server'];
  $destDb     = (string)$p['dest_db'];
  $destTable  = (string)$p['dest_table'];

  $onlyCols   = isset($p['only_cols']) && is_array($p['only_cols']) ? $p['only_cols'] : [];
  $execute    = !empty($p['execute']);

  // Load source metadata
  $cols = [];
  $pk   = [];

  if ($srcType === 'mssql') {
    $pdo = mssql_connect_server($srcServer);
    $m   = fetch_mssql_columns($pdo, $srcDb, $srcTable);
    $cols = $m['cols'];
    $pk   = $m['pk'];
  } elseif ($srcType === 'mysql') {
    $mysqli = mysql_connect_server($srcServer);
    $m      = fetch_mysql_columns($mysqli, $srcDb, $srcTable);
    $cols   = $m['cols'];
    $pk     = $m['pk'];
  } else {
    throw new InvalidArgumentException("Unsupported src_type: $srcType");
  }

  // Restrict to a subset if requested
  if (!empty($onlyCols)) {
    $whitelist = array_flip($onlyCols);
    $cols = array_values(array_filter($cols, fn($c)=> isset($whitelist[$c['name']])));
    // keep only PK columns that remain present
    if (!empty($pk)) {
      $pk = array_values(array_filter($pk, fn($c)=> isset($whitelist[$c])));
    }
  }

  if (empty($cols)) {
    throw new RuntimeException('No columns resolved from source (check permissions or table name).');
  }

  // Build DDL
  $ddl = '';
  if ($destType === 'mysql') {
    if ($srcType === 'mssql') {
      $ddl = build_mysql_create_from_mssql($destTable, $cols, $pk);
    } elseif ($srcType === 'mysql') {
      // Clone the source MySQL table structure and rename
      $mysqli = mysql_connect_server($srcServer);
      $ddl    = mysql_clone_create_table_sql($mysqli, $srcDb, $srcTable, $destTable);
    } else {
      throw new InvalidArgumentException("Unsupported conversion: $srcType → $destType");
    }
  } elseif ($destType === 'mssql') {
    // (Optional) Implement MySQL→MSSQL or MSSQL→MSSQL here if you need it.
    throw new InvalidArgumentException("Destination type 'mssql' not implemented yet.");
  } else {
    throw new InvalidArgumentException("Unsupported dest_type: $destType");
  }

  if ($execute) {
    if ($destType === 'mysql') {
      $mysqli = mysql_connect_server($destServer);
      if (!mysqli_select_db($mysqli, $destDb)) {
        throw new RuntimeException('Cannot select destination DB: ' . $mysqli->error);
      }
      if (!$mysqli->query($ddl)) {
        throw new RuntimeException('DDL failed: ' . $mysqli->error);
      }
      json_out(['ok'=>true, 'executed'=>true]);
    } elseif ($destType === 'mssql') {
      // Implement when needed
      throw new InvalidArgumentException("Execution to MSSQL not implemented yet.");
    }
  }

  // Preview
  $notes = [
    'columnsCount'     => count($cols),
    'pkChosen'         => array_values($pk),
    'identityDetected' => (bool)array_filter($cols, fn($c)=> !empty($c['is_identity'])),
  ];
  json_out(['ok'=>true, 'ddl'=>$ddl, 'notes'=>$notes]);

} catch (Throwable $e) {
  json_out(['ok'=>false, 'error'=>$e->getMessage()], 400);
}
