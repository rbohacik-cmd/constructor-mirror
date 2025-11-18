<?php
declare(strict_types=1);

/**
 * Iterate files in remotePath and stream-download each matched file.
 *
 * @param array{
 *   protocol:string, host:string, port:int, user:string, pass:string,
 *   passive?:bool, root?:string
 * } $conn
 * @param string    $remotePath   Remote directory (or exact file path for SFTP)
 * @param string    $globPattern  e.g. '*.csv'
 * @param bool      $recursive    (ignored in v1 for FTP/FTPS)
 * @param ?DateTime $onlyNewer    optional threshold
 * @param callable  $onFile       function($dir, $name, $size, $mtime, $streamResource): void
 */
function ftp_download_iter(array $conn, string $remotePath, string $globPattern, bool $recursive, ?DateTime $onlyNewer, callable $onFile): void {
  $proto = strtoupper($conn['protocol'] ?? 'FTPS');

  if ($proto === 'SFTP' && class_exists('\\phpseclib3\\Net\\SFTP')) {
    sftp_iter($conn, $remotePath, $globPattern, $recursive, $onlyNewer, $onFile);
    return;
  }

  // FTP/FTPS (non-recursive in v1)
  ftps_iter_norec($conn, $remotePath, $globPattern, $onlyNewer, $onFile);
}

/** SFTP (phpseclib3) */
function sftp_iter(array $c, string $remotePath, string $globPattern, bool $recursive, ?DateTime $onlyNewer, callable $onFile): void {
  $root = rtrim((string)($c['root'] ?? ''), '/');
  $base = $root !== '' ? $root : '';
  $dir  = $remotePath;
  if ($base !== '' && strpos($dir, $base) !== 0) {
    $dir = rtrim($base, '/').'/'.ltrim($remotePath, '/');
  }

  $sftp = new \phpseclib3\Net\SFTP($c['host'], (int)$c['port']);
  if (!$sftp->login($c['user'], $c['pass'])) {
    throw new RuntimeException('SFTP login failed');
  }

  $iter = function(string $path) use ($sftp, $globPattern, $recursive, $onlyNewer, $onFile, &$iter) {
    $list = $sftp->rawlist($path);
    if (!is_array($list)) {
      throw new RuntimeException("SFTP list failed: $path");
    }
    foreach ($list as $name => $meta) {
      if ($name === '.' || $name === '..') continue;
      $full = rtrim($path,'/').'/'.$name;

      // directory
      if (($meta['type'] ?? null) === 2) {
        if ($recursive) $iter($full);
        continue;
      }

      // file
      if (!fnmatch($globPattern, $name, FNM_CASEFOLD)) continue;
      $size  = (int)($meta['size'] ?? 0);
      $mtime = isset($meta['mtime']) ? (new DateTime("@{$meta['mtime']}")) : null;
      if ($onlyNewer && $mtime && $mtime <= $onlyNewer) continue;

      $stream = fopen('php://temp', 'w+b');
      $data = $sftp->get($full);
      if ($data === false) continue;
      fwrite($stream, $data);
      rewind($stream);

      $onFile(dirname($full), $name, $size, $mtime? $mtime->format('Y-m-d H:i:s') : null, $stream);
      fclose($stream);
    }
  };

  $iter($dir);
}

/** Do we have cURL available? */
function supports_curl_ftp(): bool {
  return function_exists('curl_init');
}

/**
 * FTPS/FTP (non-recursive v1).
 * Uses cURL when available, otherwise native PHP ftp_* (more portable on Windows).
 */
function ftps_iter_norec(array $c, string $remoteDir, string $globPattern, ?DateTime $onlyNewer, callable $onFile): void {
  $scheme = strtoupper($c['protocol']) === 'FTPS' ? 'ftps' : 'ftp';
  $root   = rtrim((string)($c['root'] ?? ''), '/');
  $dir    = $root ? rtrim($root,'/').'/'.ltrim($remoteDir,'/') : $remoteDir;
  // normalize to exactly one leading slash, no trailing slash
  $dir    = '/' . trim($dir, '/');

  if (supports_curl_ftp()) {
    // ===== cURL path =====
    $list = curl_ftp_nlst($c, $scheme, $dir);
    foreach ($list as $entry) {
      $name = basename($entry);
      if ($name === '.' || $name === '..') continue;
      if (!fnmatch($globPattern, $name, FNM_CASEFOLD)) continue;

      // cURL size is best-effort; mtime not reliable on FTP
      $size  = curl_ftp_size($c, $scheme, $dir.'/'.$name);
      $mtime = null;

      $stream = tmpfile();
      if (!curl_ftp_download($c, $scheme, $dir.'/'.$name, $stream)) {
        fclose($stream);
        continue;
      }
      rewind($stream);
      $onFile($dir, $name, $size > 0 ? $size : 0, $mtime, $stream);
      fclose($stream);
    }
    return;
  }

  // ===== Native PHP ftp_* fallback =====
  $port = (int)($c['port'] ?? 0);
  if ($port <= 0 || $port > 65535) $port = ($scheme === 'ftps') ? 21 : 21;

  $conn = (strtoupper($c['protocol']) === 'FTPS')
    ? @ftp_ssl_connect($c['host'], $port, 10)
    : @ftp_connect($c['host'], $port, 10);

  if (!$conn) throw new RuntimeException("FTP connect failed: {$c['host']}:{$port}");
  if (!@ftp_login($conn, $c['user'], $c['pass'])) {
    @ftp_close($conn);
    throw new RuntimeException("FTP login failed for user '{$c['user']}'");
  }
  @ftp_pasv($conn, !empty($c['passive']));

  $list = @ftp_nlist($conn, $dir);
  if ($list === false) {
    @ftp_close($conn);
    throw new RuntimeException("FTP list failed at '{$dir}'");
  }

  foreach ($list as $path) {
    $name = basename($path);
    if ($name === '.' || $name === '..') continue;
    if (!fnmatch($globPattern, $name, FNM_CASEFOLD)) continue;

    $full     = $dir.'/'.$name;
    $size     = @ftp_size($conn, $full);
    $mtimeInt = @ftp_mdtm($conn, $full);
    $mtimeStr = ($mtimeInt > 0) ? gmdate('Y-m-d H:i:s', $mtimeInt) : null;

    if ($onlyNewer && $mtimeStr && new DateTime($mtimeStr) <= $onlyNewer) continue;

    $stream = tmpfile();
    if (!@ftp_fget($conn, $stream, $full, FTP_BINARY)) {
      fclose($stream);
      continue;
    }
    rewind($stream);
    $onFile($dir, $name, ($size > 0 ? $size : 0), $mtimeStr, $stream);
    fclose($stream);
  }

  @ftp_close($conn);
}

/** ===== cURL helpers ===== */

/** Build a safe ftp/ftps URL with numeric port and normalized path */
function build_ftp_url(string $scheme, string $host, $port, string $path): string {
  $p = (int)$port;
  if ($p <= 0 || $p > 65535) $p = ($scheme === 'ftps') ? 21 : 21; // explicit TLS default (21)
  $cleanPath = '/' . ltrim($path, '/'); // ensure single leading slash
  return sprintf('%s://%s:%d%s', $scheme, $host, $p, $cleanPath);
}

function curl_ftp_nlst(array $c, string $scheme, string $dir): array {
  $url = build_ftp_url($scheme, (string)$c['host'], (int)($c['port'] ?? 0), $dir);
  $ch = curl_init($url);

  $opts = [
    CURLOPT_USERPWD        => "{$c['user']}:{$c['pass']}",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_DIRLISTONLY    => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USE_SSL        => ($scheme === 'ftps') ? CURLUSESSL_ALL : CURLUSESSL_NONE,
    CURLOPT_FTPSSLAUTH     => CURLFTPAUTH_DEFAULT,
    CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_NONE,
  ];
  // Passive is default. If passive=0, force active via FTPPORT.
  if (empty($c['passive'])) {
    $opts[CURLOPT_FTPPORT] = '-';
  }

  curl_setopt_array($ch, $opts);
  $resp = curl_exec($ch);
  if ($resp === false) {
    $err = curl_error($ch);
    $code = curl_errno($ch);
    curl_close($ch);
    throw new RuntimeException("FTP NLST failed (cURL $code): $err | url={$url}");
  }
  curl_close($ch);

  $lines = preg_split('/\r\n|\r|\n/', trim((string)$resp));
  return array_values(array_filter($lines, fn($x) => $x !== ''));
}

function curl_ftp_size(array $c, string $scheme, string $path): int {
  $url = build_ftp_url($scheme, (string)$c['host'], (int)($c['port'] ?? 0), $path);
  $ch = curl_init($url);

  $opts = [
    CURLOPT_USERPWD        => "{$c['user']}:{$c['pass']}",
    CURLOPT_NOBODY         => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USE_SSL        => ($scheme === 'ftps') ? CURLUSESSL_ALL : CURLUSESSL_NONE,
  ];
  if (empty($c['passive'])) {
    $opts[CURLOPT_FTPPORT] = '-';
  }

  curl_setopt_array($ch, $opts);
  curl_exec($ch);
  $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
  curl_close($ch);
  return (int)$size;
}

function curl_ftp_download(array $c, string $scheme, string $path, $destStream): bool {
  $url = build_ftp_url($scheme, (string)$c['host'], (int)($c['port'] ?? 0), $path);
  $ch = curl_init($url);

  $opts = [
    CURLOPT_USERPWD        => "{$c['user']}:{$c['pass']}",
    CURLOPT_FILE           => $destStream,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USE_SSL        => ($scheme === 'ftps') ? CURLUSESSL_ALL : CURLUSESSL_NONE,
  ];
  if (empty($c['passive'])) {
    $opts[CURLOPT_FTPPORT] = '-';
  }

  curl_setopt_array($ch, $opts);
  $ok = curl_exec($ch);
  if ($ok !== true) {
    $err = curl_error($ch);
    $code = curl_errno($ch);
    curl_close($ch);
    throw new RuntimeException("FTP download failed (cURL $code): $err | url={$url}");
  }
  curl_close($ch);
  return true;
}
