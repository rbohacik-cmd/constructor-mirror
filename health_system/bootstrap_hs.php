<?php
declare(strict_types=1);


// Project deps
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/debug_sentinel.php';


// Local config
$__HS_CFG = require __DIR__ . '/config_hs.php';


// Autoload PhpSpreadsheet if available (adjust to your vendor path)
$autoloads = [
dirname(__DIR__) . '/vendor/autoload.php',
dirname(__DIR__, 2) . '/vendor/autoload.php',
];
foreach ($autoloads as $a) { if (is_file($a)) { require_once $a; break; } }


// HS libs
require_once __DIR__ . '/lib/hs_paths.php';
require_once __DIR__ . '/lib/hs_transform.php';
require_once __DIR__ . '/lib/hs_parsers.php';
require_once __DIR__ . '/lib/hs_lib.php';
require_once __DIR__ . '/controllers/ImportWorker.php'; 


function hs_cfg(string $key, $default=null) {
global $__HS_CFG; return $__HS_CFG[$key] ?? $default;
}


function hs_pdo(): PDO { return db(); }