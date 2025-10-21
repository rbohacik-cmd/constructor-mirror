<?php
declare(strict_types=1);

namespace HS;

use PDO;
use Throwable;

final class AdminTools
{
    public static function reset(PDO $pdo, array $in, \debug_sentinel $sentinel): array
    {
        $dry   = (int)($in['dry'] ?? 0) === 1;
        $scope = (string)($in['scope'] ?? 'all');

        // context, message, value(meta), code, level, group_id
        $sentinel->log('health_system', 'Admin reset requested', ['dry'=>$dry,'scope'=>$scope], null, 'info', null);

        $res = [
            'affected' => [
                'runs_cancelled'     => 0,  // new, primary
                'runs_finished'      => 0,  // compatibility mirror of runs_cancelled
                'progress_cleared'   => 0,
                'hs_logs_deleted'    => 0,
                'sentinel_deleted'   => 0,
                'stop_all_cleared'   => 0,
            ],
            'warnings' => [],
        ];

        // existence checks
        $hasRuns     = self::tableExists($pdo, 'hs_runs');
        $hasProgress = self::tableExists($pdo, 'hs_progress');
        $hasHsLogs   = self::tableExists($pdo, 'hs_logs');
        $hasSentinel = self::tableExists($pdo, 'debug_sentinel');
        $hasControl  = self::tableExists($pdo, 'hs_control');

        // pre-counts for DRY summary
        $openStates = ['pending','started','running','reading','inserting','cancelling'];
        $openCount  = $hasRuns
            ? (int)self::qcell($pdo, 'SELECT COUNT(*) FROM hs_runs WHERE status IN (?,?,?,?,?,?)', $openStates)
            : 0;
        $progCount  = $hasProgress ? (int)self::qcell($pdo, 'SELECT COUNT(*) FROM hs_progress') : 0;
        $hsLogsCnt  = $hasHsLogs   ? (int)self::qcell($pdo, 'SELECT COUNT(*) FROM hs_logs')     : 0;
        $sentCnt    = $hasSentinel ? (int)self::qcell($pdo, "SELECT COUNT(*) FROM debug_sentinel WHERE context='health_system'") : 0;
        $stopAllExists = $hasControl ? ((int)self::qcell($pdo, "SELECT COUNT(*) FROM hs_control WHERE k='stop_all_at'") > 0) : false;

        if ($dry) {
            $res['affected']['runs_cancelled']    = $openCount;
            $res['affected']['runs_finished']     = $openCount; // compat for UI
            $res['affected']['progress_cleared']  = $progCount;
            $res['affected']['hs_logs_deleted']   = $hsLogsCnt;
            $res['affected']['sentinel_deleted']  = $sentCnt;
            $res['affected']['stop_all_cleared']  = (int)$stopAllExists;

            $sentinel->log('health_system', 'Admin reset (dry-run summary)', $res, null, 'info', null);
            return $res;
        }

        // live mode: perform best-effort mutations
        try {
            $pdo->beginTransaction();

            // 0) Cancel any OPEN runs first (so UI stops showing RUNNING)
            if ($hasRuns) {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE hs_runs
                           SET status='cancelled',
                               finished_at=NOW(),
                               error_message = IFNULL(error_message, 'reset by admin')
                         WHERE status IN ('pending','started','running','reading','inserting','cancelling')
                    ");
                    $stmt->execute();
                    $res['affected']['runs_cancelled'] = $stmt->rowCount();
                    $res['affected']['runs_finished']  = $res['affected']['runs_cancelled']; // compat
                } catch (Throwable $e) {
                    $res['warnings'][] = 'Failed to cancel open runs: '.$e->getMessage();
                }
            } else {
                $res['warnings'][] = 'Table hs_runs not found (skipped cancelling runs).';
            }

            // 1) Clear progress (DELETE for FK safety)
            if ($hasProgress) {
                try {
                    $res['affected']['progress_cleared'] = $progCount;
                    $pdo->exec("DELETE FROM hs_progress");
                } catch (Throwable $e) {
                    $res['warnings'][] = 'Failed to clear hs_progress: '.$e->getMessage();
                }
            }

            // 2) Clear HS logs (if table exists)
            if ($hasHsLogs) {
                try {
                    $res['affected']['hs_logs_deleted'] = $hsLogsCnt;
                    $pdo->exec("DELETE FROM hs_logs");
                } catch (Throwable $e) {
                    $res['warnings'][] = 'Failed to clear hs_logs: '.$e->getMessage();
                }
            }

            // 3) Clear Sentinel logs for this context
            if ($hasSentinel) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM debug_sentinel WHERE context='health_system'");
                    $stmt->execute();
                    $res['affected']['sentinel_deleted'] = $stmt->rowCount();
                } catch (Throwable $e) {
                    $res['warnings'][] = 'Failed to clear debug_sentinel: '.$e->getMessage();
                }
            }

            // 4) Clear Stop-All pulse
            if ($hasControl && $stopAllExists) {
                try {
                    $pdo->prepare("DELETE FROM hs_control WHERE k='stop_all_at'")->execute();
                    $res['affected']['stop_all_cleared'] = 1;
                } catch (Throwable $e) {
                    $res['warnings'][] = 'Failed to clear stop_all_at: '.$e->getMessage();
                }
            }

            // 5) Normalize job.last_status to the latest run status
            self::normalizeJobStatuses($pdo);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $sentinel->log('health_system', 'Admin reset failed (transaction)', ['error'=>$e->getMessage()], null, 'error', null);
            throw $e;
        }

        $sentinel->log('health_system', 'Admin reset completed', $res, null, 'info', null);
        return $res;
    }

    /**
     * Normalize hs_import_jobs.last_status to reflect the latest run status per job.
     */
    public static function normalizeJobStatuses(PDO $pdo): void
    {
        // Update last_status to latest hs_runs.status per job_id (or 'pending' if no runs)
        $pdo->exec("
            UPDATE hs_import_jobs j
            LEFT JOIN (
                SELECT r1.job_id, r1.status
                FROM hs_runs r1
                JOIN (
                    SELECT job_id, MAX(id) AS max_id
                    FROM hs_runs
                    GROUP BY job_id
                ) r2 ON r2.job_id = r1.job_id AND r2.max_id = r1.id
            ) x ON x.job_id = j.id
            SET j.last_status = COALESCE(x.status, 'pending'),
                j.last_run_at = IFNULL(j.last_run_at, NOW())
        ");

        // Defensive: if job says 'running' but there are no open runs, downgrade to 'failed'
        $pdo->exec("
            UPDATE hs_import_jobs j
            LEFT JOIN (
                SELECT job_id, SUM(status IN ('pending','started','running','reading','inserting','cancelling')) AS open_cnt
                FROM hs_runs
                GROUP BY job_id
            ) o ON o.job_id = j.id
            SET j.last_status = IF(j.last_status='running' AND COALESCE(o.open_cnt,0)=0, 'failed', j.last_status)
        ");
    }

    /* ---------- helpers ---------- */

    private static function tableExists(PDO $pdo, string $name): bool {
        $sql = "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name]);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    private static function qcell(PDO $pdo, string $sql, array $args = []) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchColumn();
    }
}
