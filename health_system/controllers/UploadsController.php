<?php
declare(strict_types=1);

namespace HS;

final class UploadsController
{
    public static function pickerList(array $in): array
    {
        // Minimal: list files under rel:// root folder (server-side)
        $sub  = trim((string)($in['sub'] ?? ''));
        $root = (DIRECTORY_SEPARATOR === '\\' ? \hs_cfg('HS_IMPORT_ROOT_WIN') : \hs_cfg('HS_IMPORT_ROOT_UNX'));
        $base = rtrim((string)$root, '/\\') . '/' . ltrim($sub, '/\\');

        if (!is_dir($base)) {
            return ['files' => []];
        }

        $out = [];
        $it  = new \DirectoryIterator($base);
        foreach ($it as $f) {
            if ($f->isDot() || $f->isDir()) continue;
            $p   = $f->getPathname();
            $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'xlsx'], true)) continue;

            $out[] = [
                'name' => $f->getFilename(),
                'size' => $f->getSize(),
                'path' => 'rel://' . trim($sub, '/') . '/' . $f->getFilename(),
            ];
        }
        return ['files' => $out];
    }

    public static function register(\PDO $pdo, array $in, \debug_sentinel $sentinel, ?int $runId = null): array
    {
        $jobId = (int)($in['job_id'] ?? 0);
        $job   = qrow('SELECT * FROM hs_import_jobs WHERE id=?', [$jobId]);
        if (!$job) {
            throw new \RuntimeException('Job not found');
        }

        $runId = $runId ?? (int)($in['run_id'] ?? 0);
        if (!$runId) {
            throw new \RuntimeException('run_id required');
        }

        $src    = (string)($in['file_path'] ?? $job['file_path']);
        $absSrc = \hs_path_resolve($src);
        if (!is_file($absSrc)) {
            throw new \RuntimeException('Source file not found: ' . $src);
        }

        $fmt   = \hs_detect_format($absSrc);
        $stamp = date('Y/m');
        $store = rtrim((string)\hs_cfg('HS_STORAGE_ROOT'), '/\\') . '/' . $stamp;
        if (!is_dir($store)) {
            @mkdir($store, 0775, true);
        }

        $dest = $store . '/job-' . $jobId . '-run-' . $runId . '-' . date('Ymd_His') . '.' . $fmt;
        if (!@copy($absSrc, $dest)) {
            throw new \RuntimeException('Failed to snapshot file');
        }

        qexec(
            'INSERT INTO hs_uploads (run_id, src_path, stored_path, format, bytes_total, created_at)
             VALUES (?,?,?,?,?,NOW())',
            [$runId, $src, $dest, $fmt, filesize($dest)]
        );
        $uploadId = (int)qlastid();

        // CHANGED: stage -> status
        qexec(
            'INSERT INTO hs_progress (upload_id, rows_total, rows_done, percent, status, updated_at)
             VALUES (?,?,?,?,?,NOW())',
            [$uploadId, 0, 0, 0, 'pending']
        );

        return ['upload_id' => $uploadId];
    }
}
