<?php
declare(strict_types=1);

/**
 * Inventory Check API
 * - action=diag / diag_mysql
 * - action=search : MSSQL lookup S4_Agenda_PCB.dbo.Artikly_Artikl (CarovyKod → Katalog → Kod) + PLU
 * - action=insert : Insert into MySQL (code, katalog, ean, name, found_pieces, [user_id])
 * - action=replace_quantity : Delete all rows for item, insert one row with new qty
 * - action=delete_item      : Delete all rows for item
 * - action=list   : Recent unique items (by code) from MySQL + stock & PLU from MSSQL
 * - action=find_stock : MSSQL stock search with pagination, server-side sorting, and selectable search field
 */

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../partials/bootstrap.php';

if (!class_exists('debug_sentinel')) {
  class debug_sentinel { public function __construct($c=null){} public function info($m,$c=[]){ } public function error($m,$c=[]){ } }
}

function json_ok($data = null): void { echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE); exit; }
function json_err(string $msg, int $code = 400): void { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }
function read_json(): array { $raw=file_get_contents('php://input')?:''; $d=json_decode($raw,true); return is_array($d)?$d:[]; }

function load_cfg(): array {
  if (function_exists('appcfg')) { $cfg=appcfg(); if (is_array($cfg)) return $cfg; }
  if (isset($GLOBALS['CONFIG']) && is_array($GLOBALS['CONFIG'])) return $GLOBALS['CONFIG'];
  $confPath = realpath(__DIR__ . '/../config.php'); if ($confPath) { $maybe = include $confPath; if (is_array($maybe)) return $maybe; }
  return [];
}

function ts_pdo_or_fail(): PDO {
  if (function_exists('db_for')) { $pdo=db_for('ts'); if (!($pdo instanceof PDO)) throw new RuntimeException('db_for("ts") did not return PDO'); return $pdo; }
  $cfg=load_cfg(); $srv=$cfg['servers']['ts']??null; if (!is_array($srv)) throw new RuntimeException('servers.ts not found');
  $server=trim((string)($srv['server']??'')); if ($server===''){ $h=trim((string)($srv['host']??'')); $p=trim((string)($srv['port']??'')); if ($h==='') throw new RuntimeException('ts server/host missing'); $server=$p!==''?($h.','.$p):$h; }
  $opt=(array)($srv['options']??[]); $db=(string)($opt['Database']??'S4_Agenda_PCB'); $user=(string)($opt['UID']??''); $pass=(string)($opt['PWD']??'');
  $encrypt=strtolower((string)($opt['Encrypt']??'no')); $trust=strtolower((string)($opt['TrustServerCertificate']??'yes')); $timeout=(int)($opt['LoginTimeout']??8); $intent=(string)($opt['ApplicationIntent']??'');
  $dsn="sqlsrv:Server={$server};Database={$db}"; $dsn.=";Encrypt=".(in_array($encrypt,['yes','true','1'],true)?'yes':'no'); $dsn.=";TrustServerCertificate=".(in_array($trust,['yes','true','1'],true)?'yes':'no'); if($timeout>0)$dsn.=";LoginTimeout={$timeout}"; if($intent!=='')$dsn.=";ApplicationIntent={$intent}";
  return new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}

function mysql_pdo_or_fail(): PDO {
  if (function_exists('db')) { $pdo=db(); if (!($pdo instanceof PDO)) throw new RuntimeException('db() did not return PDO'); return $pdo; }
  $cfg=load_cfg(); $srv=$cfg['servers']['blue']??null; if (!is_array($srv)) throw new RuntimeException('servers.blue not found');
  $my=(array)($srv['mysqli']??[]); $host=(string)($my['host']??'localhost'); $port=(string)($my['port']??''); $db=(string)($my['db']??$my['database']??''); $user=(string)($my['user']??$my['username']??''); $pass=(string)($my['pass']??$my['password']??''); $ssl=(bool)($my['ssl']??false);
  if ($db==='') throw new RuntimeException('MySQL database name is missing in config (servers.blue.mysqli.db)');
  $dsn="mysql:host={$host}".($port!==''?";port={$port}":"").";dbname={$db};charset=utf8mb4";
  $opts=[ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false ];
  if ($ssl){ $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=false; if(!empty($my['ca'])) $opts[PDO::MYSQL_ATTR_SSL_CA]=$my['ca']; }
  return new PDO($dsn,$user,$pass,$opts);
}

function ensure_inventory_checks_schema(PDO $pdo): array {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `inventory_checks` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `code` VARCHAR(128) DEFAULT NULL,
      `katalog` VARCHAR(128) DEFAULT NULL,
      `ean` VARCHAR(64) DEFAULT NULL,
      `name` VARCHAR(512) DEFAULT NULL,
      `found_pieces` INT NOT NULL DEFAULT 0,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_code` (`code`),
      KEY `idx_katalog` (`katalog`),
      KEY `idx_ean` (`ean`),
      KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
  $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='inventory_checks'");
  $st->execute([$dbName]);
  $cols = array_flip(array_map('strtolower', array_column($st->fetchAll(PDO::FETCH_ASSOC),'COLUMN_NAME')));

  $hasUser = array_key_exists('user_id', $cols);
  if (!$hasUser) {
    try { $pdo->exec("ALTER TABLE `inventory_checks` ADD COLUMN `user_id` INT UNSIGNED DEFAULT NULL AFTER `found_pieces`; ALTER TABLE `inventory_checks` ADD KEY `idx_user` (`user_id`)"); $hasUser=true; } catch(Throwable $e) { /* ignore */ }
  }
  return ['has_user_id'=>$hasUser];
}

// Optional: warehouse filter GUID (for stock)
function sklad_guid_from_cfg(): ?string {
  $cfg = load_cfg();
  $inv = $cfg['inventory_check'] ?? [];
  $guid = isset($inv['sklad_guid']) ? (string)$inv['sklad_guid'] : '';
  return $guid !== '' ? $guid : null;
}

// Delete ALL rows for an item by (code) OR (katalog) OR (ean)
function delete_all_rows_for_item(PDO $pdo, ?string $code, ?string $katalog, ?string $ean): int {
  $code    = trim((string)$code);
  $katalog = trim((string)$katalog);
  $ean     = trim((string)$ean);

  if ($code !== '') {
    $st = $pdo->prepare("DELETE FROM `inventory_checks` WHERE `code` = ?");
    $st->execute([$code]);
    return $st->rowCount();
  }
  if ($katalog !== '') {
    $st = $pdo->prepare("DELETE FROM `inventory_checks` WHERE `katalog` = ?");
    $st->execute([$katalog]);
    return $st->rowCount();
  }
  if ($ean !== '') {
    $st = $pdo->prepare("DELETE FROM `inventory_checks` WHERE `ean` = ?");
    $st->execute([$ean]);
    return $st->rowCount();
  }
  throw new InvalidArgumentException('No identifier provided (code/katalog/ean).');
}

$action   = (string)($_GET['action'] ?? '');
$sentinel = new debug_sentinel('inventory_check');

if (function_exists('is_logged_in') && !is_logged_in()) json_err('Not authenticated', 401);

try {
  if ($action === 'diag') {
    $diag = [
      'pdo_sqlsrv_loaded' => extension_loaded('pdo_sqlsrv'),
      'sqlsrv_loaded'     => extension_loaded('sqlsrv'),
      'drivers'           => class_exists('PDO') ? PDO::getAvailableDrivers() : [],
      'path'              => function_exists('db_for') ? 'db_for(ts)' : 'config->servers.ts.options',
    ];
    try { $pdoTs=ts_pdo_or_fail(); $st=$pdoTs->query("SELECT TOP 1 name FROM sys.databases"); $diag['connect_ok']=(bool)$st; $diag['sample']=$st?$st->fetch(PDO::FETCH_ASSOC):null; }
    catch(Throwable $e){ $diag['connect_ok']=false; $diag['error']=$e->getMessage(); }
    json_ok($diag);
  }

  if ($action === 'diag_mysql') {
    $diag = ['path' => function_exists('db') ? 'db()' : 'config->servers.blue.mysqli'];
    try { $pdo=mysql_pdo_or_fail(); $row=$pdo->query('SELECT 1 AS ok')->fetch(); $diag['connect_ok']=(bool)($row['ok']??0); $diag['schema']=ensure_inventory_checks_schema($pdo); }
    catch(Throwable $e){ $diag['connect_ok']=false; $diag['error']=$e->getMessage(); }
    json_ok($diag);
  }

  if ($action === 'search') {
    $in = read_json(); $query = trim((string)($in['query'] ?? '')); if ($query==='') json_err('Empty query');
    try { $pdoTs=ts_pdo_or_fail(); }
    catch(Throwable $e){ $sentinel->error('MSSQL connect failed',['err'=>$e->getMessage()]); http_response_code(503); echo json_encode(['ok'=>false,'error'=>'MSSQL connection failed','hint'=>'Verify servers.ts.server and options (UID/PWD, Encrypt/TrustServerCertificate, LoginTimeout).'], JSON_UNESCAPED_UNICODE); exit; }

    $hit = null;
    foreach (['CarovyKod','Katalog','Kod'] as $col) {
      $sql = "SELECT TOP 1 a.Kod, a.Katalog, a.Nazev, a.CarovyKod, a.PLU
              FROM S4_Agenda_PCB.dbo.Artikly_Artikl a
              WHERE a.{$col} = ?";
      $st = $pdoTs->prepare($sql); $st->execute([$query]); $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row) { $hit=$row; $sentinel->info('Search hit',['by'=>$col]); break; }
      $sentinel->info('No hit',['by'=>$col]);
    }
    json_ok(['hit'=>$hit]); // hit contains PLU now
  }

  if ($action === 'insert') {
    $in = read_json();
    $code    = trim((string)($in['code'] ?? ''));
    $katalog = trim((string)($in['katalog'] ?? ''));
    $ean     = trim((string)($in['ean'] ?? ''));
    $name    = trim((string)($in['name'] ?? ''));
    $qty     = (int)($in['found_pieces'] ?? -1);
    if ($qty < 0) json_err('Invalid quantity');
    if ($code==='' && $katalog==='' && $ean==='') json_err('Missing key identifiers (code/katalog/ean)');

    $pdoMy = mysql_pdo_or_fail();
    $schema = ensure_inventory_checks_schema($pdoMy);
    $hasUser = (bool)$schema['has_user_id'];
    $userId = function_exists('current_user_id') ? (int)current_user_id() : null;

    if ($hasUser) {
      $sql = "INSERT INTO `inventory_checks` (`code`,`katalog`,`ean`,`name`,`found_pieces`,`user_id`) VALUES (?,?,?,?,?,?)";
      $st = $pdoMy->prepare($sql); $st->execute([$code?:null,$katalog?:null,$ean?:null,$name?:null,$qty,$userId?:null]);
    } else {
      $sql = "INSERT INTO `inventory_checks` (`code`,`katalog`,`ean`,`name`,`found_pieces`) VALUES (?,?,?,?,?)";
      $st = $pdoMy->prepare($sql); $st->execute([$code?:null,$katalog?:null,$ean?:null,$name?:null,$qty]);
    }

    $id = (int)$pdoMy->lastInsertId();
    $sentinel->info('Row inserted', ['id'=>$id,'code'=>$code,'katalog'=>$katalog,'ean'=>$ean,'qty'=>$qty,'user_id'=>$userId,'has_user_col'=>$hasUser]);
    json_ok(['inserted_id'=>$id,'has_user_id'=>$hasUser]);
  }

  if ($action === 'replace_quantity') {
    $in      = read_json();
    $code    = trim((string)($in['code'] ?? ''));
    $katalog = trim((string)($in['katalog'] ?? ''));
    $ean     = trim((string)($in['ean'] ?? ''));
    $name    = trim((string)($in['name'] ?? ''));
    $qty     = (int)($in['found_pieces'] ?? -1);

    if ($qty < 0) json_err('Invalid quantity');
    if ($code==='' && $katalog==='' && $ean==='') json_err('Missing key identifiers (code/katalog/ean)');

    $pdoMy = mysql_pdo_or_fail();
    ensure_inventory_checks_schema($pdoMy);

    $pdoMy->beginTransaction();
    try {
      delete_all_rows_for_item($pdoMy, $code, $katalog, $ean);

      $sql = "INSERT INTO `inventory_checks` (`code`,`katalog`,`ean`,`name`,`found_pieces`) VALUES (?,?,?,?,?)";
      $st  = $pdoMy->prepare($sql);
      $st->execute([$code ?: null, $katalog ?: null, $ean ?: null, $name ?: null, $qty]);

      $pdoMy->commit();
      json_ok(['ok' => true]);
    } catch (Throwable $e) {
      $pdoMy->rollBack();
      throw $e;
    }
  }

  if ($action === 'delete_item') {
    $in      = read_json();
    $code    = trim((string)($in['code'] ?? ''));
    $katalog = trim((string)($in['katalog'] ?? ''));
    $ean     = trim((string)($in['ean'] ?? ''));

    if ($code==='' && $katalog==='' && $ean==='') json_err('Missing key identifiers (code/katalog/ean)');

    $pdoMy = mysql_pdo_or_fail();
    ensure_inventory_checks_schema($pdoMy);
    $deleted = delete_all_rows_for_item($pdoMy, $code, $katalog, $ean);

    json_ok(['deleted' => (int)$deleted]);
  }

	if ($action === 'list') {
	  $in    = read_json();
	  $page  = max(1, (int)($in['page']  ?? 1));
	  $limit = max(1, min(500, (int)($in['limit'] ?? 50)));
	  $offset = ($page - 1) * $limit;

	  $pdoMy = mysql_pdo_or_fail();
	  ensure_inventory_checks_schema($pdoMy);

	  // Total unique items (by code) for pagination
	  $sqlTotal = "SELECT COUNT(*) AS cnt
				   FROM (SELECT 1 FROM inventory_checks WHERE code IS NOT NULL GROUP BY code) t";
	  $total = (int)$pdoMy->query($sqlTotal)->fetchColumn();

	  if ($total === 0) {
		json_ok(['rows' => [], 'total' => 0, 'page' => $page, 'limit' => $limit]);
	  }

	  // Page of "latest row per code" + sum(found_pieces) per code, ordered by latest created_at DESC
	  $sql = "
		SELECT
		  l.code,
		  s.sum_found AS found_pieces,
		  l.katalog,
		  l.ean,
		  l.name,
		  l.created_at
		FROM (
		  SELECT code, SUM(found_pieces) AS sum_found, MAX(id) AS last_id
		  FROM inventory_checks
		  WHERE code IS NOT NULL
		  GROUP BY code
		) AS s
		JOIN inventory_checks AS l ON l.id = s.last_id
		ORDER BY l.created_at DESC, l.code
		LIMIT :lim OFFSET :off
	  ";
	  $st = $pdoMy->prepare($sql);
	  $st->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
	  $st->bindValue(':off', (int)$offset, PDO::PARAM_INT);
	  $st->execute();
	  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

	  // Enrich from MSSQL: stock (Dost), reserved (Rez), PLU by code
	  $codes = [];
	  foreach ($rows as $r) {
		$c = trim((string)($r['code'] ?? ''));
		if ($c !== '') $codes[$c] = true;
	  }
	  $codes = array_values(array_keys($codes));

	  $stockByCode    = [];
	  $reservedByCode = [];
	  $pluByCode      = [];

	  if ($codes) {
		try {
		  $pdoTs = ts_pdo_or_fail();
		  $skladGuid = sklad_guid_from_cfg();

		  $ph = implode(',', array_fill(0, count($codes), '?'));
		  $sql2 = "
			SELECT a.Kod,
				   a.PLU,
				   SUM(COALESCE(z.DostupneMnozstvi, 0)) AS Dost,
				   SUM(COALESCE(z.Rezervovano,     0)) AS Rez
			FROM S4_Agenda_PCB.dbo.Artikly_Artikl a WITH (NOLOCK)
			LEFT JOIN S4_Agenda_PCB.dbo.Sklady_Zasoba z WITH (NOLOCK)
			  ON z.Artikl_ID = a.ID
			  " . ($skladGuid ? "AND z.Sklad_ID = CONVERT(uniqueidentifier, ?)" : "") . "
			WHERE a.Kod IN ($ph)
			GROUP BY a.Kod, a.PLU
		  ";
		  $params = $codes;
		  if ($skladGuid) array_unshift($params, $skladGuid);

		  $st2 = $pdoTs->prepare($sql2);
		  $st2->execute($params);

		  while ($r2 = $st2->fetch(PDO::FETCH_ASSOC)) {
			$code = (string)($r2['Kod'] ?? '');
			$stockByCode[$code]    = (float)($r2['Dost'] ?? 0);
			$reservedByCode[$code] = (float)($r2['Rez']  ?? 0);
			$pluByCode[$code]      = $r2['PLU'] ?? null;
		  }
		} catch (Throwable $e) {
		  // optional: ignore MSSQL errors, leave values null
		}
	  }

	  $out = [];
	  foreach ($rows as $r) {
		$code = (string)($r['code'] ?? '');
		$out[] = [
		  'code'         => $code,
		  'katalog'      => $r['katalog'],
		  'ean'          => $r['ean'],
		  'name'         => $r['name'],
		  'plu'          => array_key_exists($code, $pluByCode)      ? $pluByCode[$code]      : null,
		  'found_pieces' => (int)$r['found_pieces'],
		  'reserved_ts'  => ($code !== '' && array_key_exists($code, $reservedByCode)) ? $reservedByCode[$code] : null,
		  'stock_ts'     => ($code !== '' && array_key_exists($code, $stockByCode))    ? $stockByCode[$code]    : null,
		];
	  }

	  json_ok(['rows' => $out, 'total' => $total, 'page' => $page, 'limit' => $limit]);
	}
  
  if ($action === 'clear_all') {
	  $pdoMy = mysql_pdo_or_fail();
	  ensure_inventory_checks_schema($pdoMy);

	  // Delete all rows; rowCount returns affected rows for MySQL here
	  $deleted = 0;
	  try {
		$st = $pdoMy->prepare("DELETE FROM `inventory_checks`");
		$st->execute();
		$deleted = (int)$st->rowCount();
	  } catch (Throwable $e) {
		// If rowCount is unreliable on your driver, you could also SELECT COUNT(*) before the delete.
		throw $e;
	  }

	  json_ok(['deleted' => $deleted]);
	}

 if ($action === 'find_stock') {
	  $in    = read_json();
	  $q     = trim((string)($in['q'] ?? ''));
	  $page  = max(1, (int)($in['page'] ?? 1));
	  $limit = max(1, min(100, (int)($in['limit'] ?? 20)));

	  // Which column to search by (from UI dropdown)
	  $field = strtolower((string)($in['field'] ?? 'name'));
	  $fieldMap = [
		'name'    => 'a.Nazev',
		'plu'     => 'a.PLU',
		'kod'     => 'a.Kod',
		'katalog' => 'a.Katalog',
		'ean'     => 'a.CarovyKod',
	  ];
	  if (!array_key_exists($field, $fieldMap)) $field = 'name';
	  $whereCol  = $fieldMap[$field];
	  $whereExpr = "{$whereCol} LIKE ? ESCAPE '\\'";  // ✅ define once

	  // Min length: PLU=2, others=3
	  $minLen = ($field === 'plu') ? 2 : 3;
	  if (mb_strlen($q) < $minLen) {
		json_err('Query too short (min '.$minLen.' chars for '.$field.')');
	  }

	  // Server-side sorting
	  $orderBy  = strtolower((string)($in['order_by']  ?? 'name'));
	  $orderDir = strtolower((string)($in['order_dir'] ?? 'asc'));
	  if (!in_array($orderDir, ['asc','desc'], true)) $orderDir = 'asc';

	  // Whitelisted ORDER BY expressions
	  $orderMap = [
		'name'     => 'a.Nazev',
		'kod'      => 'a.Kod',
		'katalog'  => 'a.Katalog',
		'plu'      => 'a.PLU',
		'stock'    => 'COALESCE(s.Dost,0)',
		'reserved' => 'COALESCE(s.Rez,0)',  // ✅ allow sorting by Reserved
		// 'checked' intentionally not supported for global ordering
	  ];
	  if (!array_key_exists($orderBy, $orderMap)) $orderBy = 'name';
	  $orderExpr = $orderMap[$orderBy];

	  // LIKE value (escape specials)
	  $like = strtr($q, ['%' => '\\%', '_' => '\\_', '\\' => '\\\\']);
	  $like = '%' . $like . '%';

	  $pdoTs     = ts_pdo_or_fail();
	  $skladGuid = sklad_guid_from_cfg();
	  $offset    = ($page - 1) * $limit;

	  // --- COUNT ---
	  $sqlCount = "
		SELECT COUNT(*) AS cnt
		FROM (
		  SELECT a.ID
		  FROM S4_Agenda_PCB.dbo.Artikly_Artikl a WITH (NOLOCK)
		  OUTER APPLY (
			SELECT
			  SUM(COALESCE(z.DostupneMnozstvi,0)) AS Dost,
			  SUM(COALESCE(z.Rezervovano,0))      AS Rez
			FROM S4_Agenda_PCB.dbo.Sklady_Zasoba z WITH (NOLOCK)
			WHERE z.Artikl_ID = a.ID
			  " . ($skladGuid ? "AND z.Sklad_ID = CONVERT(uniqueidentifier, ?)" : "") . "
		  ) s
		  WHERE {$whereExpr}
			AND COALESCE(s.Dost,0) > 0
		  GROUP BY a.ID
		) t
	  ";
	  $paramsCount = [];
	  if ($skladGuid) $paramsCount[] = $skladGuid;
	  $paramsCount[] = $like;

	  $stC = $pdoTs->prepare($sqlCount);
	  $stC->execute($paramsCount);
	  $total = (int)($stC->fetchColumn() ?: 0);

	  if ($total === 0) {
		json_ok(['rows'=>[], 'total'=>0]);
	  }

	  // --- PAGE ROWS ---
	  $sqlRows = "
		SELECT a.Kod, a.Katalog, a.Nazev, a.PLU,
			   COALESCE(s.Dost,0) AS Stock,
			   COALESCE(s.Rez,0)  AS Reserved
		FROM S4_Agenda_PCB.dbo.Artikly_Artikl a WITH (NOLOCK)
		OUTER APPLY (
		  SELECT
			SUM(COALESCE(z.DostupneMnozstvi,0)) AS Dost,
			SUM(COALESCE(z.Rezervovano,0))      AS Rez
		  FROM S4_Agenda_PCB.dbo.Sklady_Zasoba z WITH (NOLOCK)
		  WHERE z.Artikl_ID = a.ID
			" . ($skladGuid ? "AND z.Sklad_ID = CONVERT(uniqueidentifier, ?)" : "") . "
		) s
		WHERE {$whereExpr}
		  AND COALESCE(s.Dost,0) > 0
		ORDER BY {$orderExpr} {$orderDir}
		OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
	  ";
	  $st = $pdoTs->prepare($sqlRows);
	  $idx = 1;
	  if ($skladGuid) { $st->bindValue($idx++, $skladGuid, PDO::PARAM_STR); }
	  $st->bindValue($idx++, $like, PDO::PARAM_STR);
	  $st->bindValue($idx++, (int)$offset, PDO::PARAM_INT);
	  $st->bindValue($idx++, (int)$limit,  PDO::PARAM_INT);
	  $st->execute();

	  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

	  // Enrich with FoundSum from MySQL (sum found_pieces by code for codes on this page)
	  if ($rows) {
		$codes = [];
		foreach ($rows as $r) {
		  $c = trim((string)($r['Kod'] ?? ''));
		  if ($c !== '') $codes[$c] = true;
		}
		$codes = array_keys($codes);

		if ($codes) {
		  try {
			$pdoMy = mysql_pdo_or_fail();
			ensure_inventory_checks_schema($pdoMy);

			$ph = implode(',', array_fill(0, count($codes), '?'));
			$sqlSum = "SELECT code, SUM(found_pieces) AS sum_found
					   FROM inventory_checks
					   WHERE code IN ($ph)
					   GROUP BY code";
			$stm = $pdoMy->prepare($sqlSum);
			$stm->execute($codes);

			$sumByCode = [];
			while ($r2 = $stm->fetch(PDO::FETCH_ASSOC)) {
			  $sumByCode[(string)$r2['code']] = (int)($r2['sum_found'] ?? 0);
			}
			foreach ($rows as &$r) {
			  $k = (string)($r['Kod'] ?? '');
			  $r['FoundSum'] = array_key_exists($k, $sumByCode) ? $sumByCode[$k] : null;
			}
			unset($r);
		  } catch (Throwable $e) {
			foreach ($rows as &$r) { $r['FoundSum'] = null; } unset($r);
		  }
		} else {
		  foreach ($rows as &$r) { $r['FoundSum'] = null; } unset($r);
		}
	  }

	  json_ok(['rows'=>$rows, 'total'=>$total]);
	}


  json_err('Unknown action', 404);

} catch (Throwable $e) {
  (new debug_sentinel('inventory_check'))->error('API failure', ['action'=>$action, 'error'=>$e->getMessage()]);
  json_err('Server error: ' . $e->getMessage(), 500);
}
