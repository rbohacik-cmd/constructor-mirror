<?php
declare(strict_types=1);

function hs_path_resolve(string $path): string {
  $path = trim($path);
  if ($path === '') throw new RuntimeException('Empty path');

  // deny control chars and traversal
  if (preg_match('~[\x00-\x1F]~', $path)) throw new RuntimeException('Unsafe path');
  if (str_contains($path, '..')) throw new RuntimeException('Path traversal forbidden');

  $winRoot = (string)hs_cfg('HS_IMPORT_ROOT_WIN', 'C:/imports');
  $unxRoot = (string)hs_cfg('HS_IMPORT_ROOT_UNX', '/var/imports');

  // rel://... → under configured import root
  if (stripos($path, 'rel://') === 0) {
    $sub  = substr($path, 6);
    $base = DIRECTORY_SEPARATOR === '\\' ? $winRoot : $unxRoot;
    $abs  = rtrim($base, '/\\') . '/' . ltrim($sub, '/\\');
    return $abs;
  }

  // file://... → explicit absolute
  if (stripos($path, 'file://') === 0) {
    // Accept file://C:/..., file:///C:/..., file://///SERVER/share/...
    $p = preg_replace('~^file:(/*)~i', '', $path); // strip file: and any slashes
    // Restore leading slashes for UNC if it started with 4-5 slashes
    if (preg_match('~^/{2,}~', $path)) $p = '//' . ltrim($p, '/');
    return $p;
  }

  // Absolute UNC on Windows: \\SERVER\share\...
  if (preg_match('~^\\\\\\\\~', $path)) {
    return $path;
  }

  // Absolute Windows drive path: C:\ or C:/
  if (preg_match('~^[A-Za-z]:[\\/]+~', $path)) {
    return $path;
  }

  // Absolute POSIX path: /var/...
  if (substr($path, 0, 1) === '/') {
    return $path;
  }

  // Fallback: treat as rel://
  $base = DIRECTORY_SEPARATOR === '\\' ? $winRoot : $unxRoot;
  $abs  = rtrim($base, '/\\') . '/' . ltrim($path, '/\\');
  return $abs;
}
