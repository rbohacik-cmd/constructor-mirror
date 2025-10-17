<?php
declare(strict_types=1);

// Cross-module include via PROJECT_FS (bootstrap must be loaded by caller)
require_once PROJECT_FS . '/secrets.php';

function decrypt_pass(string $cipherB64): string {
  $key = get_kms_key();
  $raw = base64_decode($cipherB64, true);
  if ($raw === false || strlen($raw) < 17) throw new RuntimeException('Invalid encrypted payload');
  $iv  = substr($raw, 0, 16);
  $c   = substr($raw, 16);
  $k   = hash('sha256', $key, true);
  $p   = openssl_decrypt($c, 'aes-256-cbc', $k, OPENSSL_RAW_DATA, $iv);
  if ($p === false) throw new RuntimeException('Decrypt failed');
  return $p;
}

function encrypt_pass(string $plain): string {
  $key = get_kms_key();
  $iv  = random_bytes(16);
  $k   = hash('sha256', $key, true);
  $c   = openssl_encrypt($plain, 'aes-256-cbc', $k, OPENSSL_RAW_DATA, $iv);
  if ($c === false) throw new RuntimeException('Encrypt failed');
  return base64_encode($iv . $c);
}
