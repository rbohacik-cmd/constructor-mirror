<?php
declare(strict_types=1);

/**
 * Unified App Bootstrap (global)
 * - Defines PROJECT_FS (project root, no trailing slash)
 * - Computes BASE_URL (always trailing slash) and REQ_REL
 * - Discovers Composer/bundled autoloaders
 * - Loads shared helpers from /partials/web_helpers.php
 */

# --- Filesystem roots (normalized) ---
if (!defined('PROJECT_FS')) {
  $proj = realpath(dirname(__DIR__)) ?: __DIR__;
  define('PROJECT_FS', rtrim(str_replace('\\', '/', $proj), '/'));
}

if (!defined('DOCROOT_FS')) {
  $doc = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
  define('DOCROOT_FS', rtrim(str_replace('\\', '/', $doc), '/'));
}

# --- Base URL (project-relative; always ends with /) ---
if (!defined('BASE_URL')) {
  $rel = '/';
  if (DOCROOT_FS && str_starts_with(PROJECT_FS, DOCROOT_FS)) {
    $rel = substr(PROJECT_FS, strlen(DOCROOT_FS));
    if ($rel === '' || $rel[0] !== '/') $rel = '/' . $rel;
  }
  define('BASE_URL', rtrim($rel, '/') . '/'); // e.g. "/" or "/work/"
}

# --- Request path relative to project root (empty in CLI) ---
if (!defined('REQ_REL')) {
  $reqPath = ($_SERVER['REQUEST_URI'] ?? '') ? (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '') : '';
  $reqRel  = $reqPath ? ltrim(substr($reqPath, strlen(BASE_URL)), '/') : '';
  define('REQ_REL', $reqRel);
}

# --- Autoload discovery (harmless if missing) ---
$__autoload_candidates = [
  PROJECT_FS . '/vendor/autoload.php',                     // project Composer
  PROJECT_FS . '/lib/PhpSpreadsheet/vendor/autoload.php',  // bundled PhpSpreadsheet
];
foreach ($__autoload_candidates as $__auto) {
  if (is_file($__auto)) { require_once $__auto; }
}

# --- Shared helpers ---
$__helpers = PROJECT_FS . '/partials/web_helpers.php';
if (is_file($__helpers)) {
  require_once $__helpers;
} else {
  // Fail fast with a clear message to avoid silent path bugs
  throw new RuntimeException("Missing helpers: {$__helpers}");
}

if (!function_exists('pdo')) {
    function pdo(): PDO {
        static $pdo = null;
        if ($pdo) return $pdo;

        // adapt credentials to your environment
        $dbHost = '127.0.0.1';
        $dbName = 'wgdddkmy_workstuff';
        $dbUser = 'root';
        $dbPass = '';
        $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    }
}