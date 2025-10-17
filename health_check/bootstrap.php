<?php
declare(strict_types=1);

/**
 * Health-Check module bootstrap
 * - Centralizes includes
 * - Stable entry point for any HC consumer
 * - Keeps legacy calls working via _compat/legacy_shims.php
 */

$HC_BASE = __DIR__;

// â€” Project-level dependencies (adjust to your project structure)
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/mysql.php';
require_once dirname(__DIR__) . '/debug_sentinel.php';

/* === NEW: PhpSpreadsheet autoload discovery =================================
   Your bundle lives at <base>/lib/PhpSpreadsheet/vendor.
   We also try the typical Composer root vendor first (harmless if missing). */
$__ps_autoload_candidates = [
  dirname(__DIR__) . '/vendor/autoload.php',              // project Composer (if you have it)
  dirname(__DIR__) . '/lib/PhpSpreadsheet/vendor/autoload.php', // your bundled path
];
foreach ($__ps_autoload_candidates as $__auto) {
  if (is_file($__auto)) { require_once $__auto; }
}
// ========================================================================== */

// â€” Module config
require_once $HC_BASE . '/config.php';

// â€” Core libs + services
require_once $HC_BASE . '/health_check_lib.php';
require_once $HC_BASE . '/ImportService.php';
require_once $HC_BASE . '/UploadService.php';
require_once $HC_BASE . '/ManufacturerRegistry.php';
require_once $HC_BASE . '/StatusStore.php';

// â€” Parsers
require_once $HC_BASE . '/CsvParser.php';
require_once $HC_BASE . '/XlsxParser.php';

// â€” Optional: thin sentinel adapter if you want consistent naming
$__hc_sentinel_factory = static function(string $channel, ?PDO $pdo = null): debug_sentinel {
  return new debug_sentinel($channel, $pdo ?? db());
};

if (!function_exists('qall_ms')) {
  function qall_ms(PDO $pdo, string $sql, array $params = []): array {
    return qall($sql, $params, null, 'ts');
  }
}