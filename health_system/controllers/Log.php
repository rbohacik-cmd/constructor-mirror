<?php
declare(strict_types=1);

namespace HS;

final class Log
{
    /** Allowed levels to keep data clean */
    private const LEVELS = ['debug','info','warn','error'];

    /** Max sizes to avoid oversized rows blowing up inserts */
    private const MAX_PHASE = 64;
    private const MAX_MSG   = 4000;   // TEXT can hold more, but we keep logs tidy
    private const MAX_META  = 8000;   // guard for very large arrays

    /**
     * Write a single log line to hs_logs (and optionally mirror to debug_sentinel).
     */
    public static function add(\PDO $pdo, int $jobId, int $runId, string $phase, string $message, string $level='info', array $meta=[]): void
    {
        // Normalize inputs
        $level = in_array($level, self::LEVELS, true) ? $level : 'info';
        $phase = mb_substr((string)$phase, 0, self::MAX_PHASE);
        $msg   = (string)$message;
        if (mb_strlen($msg) > self::MAX_MSG) {
            $msg = mb_substr($msg, 0, self::MAX_MSG) . ' …';
        }

        // Encode meta defensively and clamp size
        $metaJ = null;
        if (!empty($meta)) {
            try {
                $metaJ = json_encode($meta, JSON_UNESCAPED_UNICODE);
                if ($metaJ !== null && strlen($metaJ) > self::MAX_META) {
                    // Trim heavy fields if any, then re-encode
                    foreach ($meta as $k => $v) {
                        if (is_string($v) && strlen($v) > 1024) {
                            $meta[$k] = substr($v, 0, 1024) . ' …';
                        } elseif (is_array($v) && count($v) > 50) {
                            $meta[$k] = array_slice($v, 0, 50);
                            $meta[$k]['__truncated__'] = true;
                        }
                    }
                    $metaJ = json_encode($meta, JSON_UNESCAPED_UNICODE);
                    if ($metaJ !== null && strlen($metaJ) > self::MAX_META) {
                        $metaJ = substr($metaJ, 0, self::MAX_META) . ' …';
                    }
                }
            } catch (\Throwable $e) {
                $metaJ = null; // never let meta break logging
            }
        }

        // Ensure table exists once (cheap check)
        self::ensureTable($pdo);

        // Insert
        \qexec(
            "INSERT INTO hs_logs (ts, job_id, run_id, level, phase, message, meta_json)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?)",
            [$jobId, $runId, $level, $phase, $msg, $metaJ]
        );

        // Optional mirror to debug_sentinel (non-fatal if it fails)
        if (class_exists('\\debug_sentinel')) {
            try {
                $s = new \debug_sentinel('health_system', $pdo);
                $s->info("[$phase] $msg", ['run_id'=>$runId,'job_id'=>$jobId,'level'=>$level]);
            } catch (\Throwable $e) {
                // swallow
            }
        }
    }

    /** Create hs_logs if it’s missing (idempotent) */
    private static function ensureTable(\PDO $pdo): void
    {
        static $checked = false;
        if ($checked) return;

        try {
            // Cheap existence probe
            $pdo->query("SELECT 1 FROM hs_logs LIMIT 1");
            $checked = true;
            return;
        } catch (\Throwable $e) {
            // create table
            $sql = "
                CREATE TABLE IF NOT EXISTS hs_logs (
                  id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                  ts        DATETIME NOT NULL,
                  job_id    INT UNSIGNED NOT NULL,
                  run_id    INT UNSIGNED NOT NULL,
                  level     ENUM('debug','info','warn','error') NOT NULL DEFAULT 'info',
                  phase     VARCHAR(64) NOT NULL,
                  message   TEXT NOT NULL,
                  meta_json JSON NULL,
                  KEY (run_id),
                  KEY (job_id),
                  KEY (ts)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $pdo->exec($sql);
            $checked = true;
        }
    }
}
