<?php
declare(strict_types=1);

/**
 * Map old include paths to the new ones.
 * Remove this file once all call sites include /health_check/bootstrap.php instead.
 */

$HC = hc_paths();

// Old: /lib/health_check_lib.php
if (!class_exists('CsvParser', false)) {
  // files are already included by bootstrap, but you can add no-op stubs if needed
}

/* If some files were previously included directly, define tiny wrappers:
   e.g., function hc_table_name($key) { return \HealthCheck\Lib\hc_table_name($key); }
   Prefer to NOT create duplicates—just keep bootstrap inclusion centralized.
*/
