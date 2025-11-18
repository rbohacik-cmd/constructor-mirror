<?php
declare(strict_types=1);

// Make sure our root constants (PROJECT_FS, BASE_URL, etc.) exist
if (!defined('PROJECT_FS')) {
  require_once __DIR__ . '/../partials/bootstrap.php';
}

// Use unified root-based include
require_once PROJECT_FS . '/partials/crypto.php';

// CLI only
if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "This tool must be run from the command line.\n");
  exit(2);
}

$plain = $argv[1] ?? '';
if ($plain === '') {
  fwrite(STDERR, "Usage: php tools/encrypt_ftp_pass.php <plain_password>\n");
  exit(2);
}

echo encrypt_pass($plain), PHP_EOL;
