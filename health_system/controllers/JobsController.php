<?php
declare(strict_types=1);

namespace HS;

final class JobsController
{
    /** List all jobs with manufacturer info */
	public static function list(\PDO $pdo): array
	{
		// Latest run per job
		$rows = qall(
			'SELECT
				j.*,
				m.slug,
				m.name AS manufacturer_name,
				r.id   AS run_id,
				r.status AS run_status,
				r.started_at AS run_started_at,
				p.status AS progress_status,
				p.rows_done,
				p.rows_total,
				p.percent
			 FROM hs_import_jobs j
			 JOIN hs_manufacturers m ON m.id = j.manufacturer_id
			 LEFT JOIN (
				SELECT job_id, MAX(id) AS last_run_id
				FROM hs_runs
				GROUP BY job_id
			 ) lr ON lr.job_id = j.id
			 LEFT JOIN hs_runs r ON r.id = lr.last_run_id
			 LEFT JOIN (
				SELECT u.run_id, MAX(u.id) AS last_upload_id
				FROM hs_uploads u
				GROUP BY u.run_id
			 ) lu ON lu.run_id = r.id
			 LEFT JOIN hs_progress p ON p.upload_id = lu.last_upload_id
			 ORDER BY j.id DESC'
		);

		// Compute ETA if we can (running & have percent)
		$now = time();
		foreach ($rows as &$row) {
			$row['eta_seconds'] = null;
			$pct = isset($row['percent']) ? (float)$row['percent'] : 0.0;
			$status = (string)($row['run_status'] ?? '');
			$startedAt = isset($row['run_started_at']) ? strtotime((string)$row['run_started_at']) : null;

			if ($startedAt && $pct > 0 && $pct < 100 && in_array($status, ['started', 'imported', 'failed']) ) {
				// If status='started' we’re mid-run; if imported/failed we won’t show ETA anyway on FE.
				$elapsed = max(1, $now - $startedAt);
				// naive linear estimate: total = elapsed / (pct/100); eta = total - elapsed
				$eta = (int)round($elapsed * (100.0 / $pct - 1.0));
				if ($eta > 0 && $eta < 86400*7) { // cap 7 days
					$row['eta_seconds'] = $eta;
				}
			}
		}
		unset($row);

		return $rows ?: [];
	}


    /** Get a single job */
    public static function get(\PDO $pdo, int $id): array
    {
        $row = qrow('SELECT * FROM hs_import_jobs WHERE id=?', [$id]);
        return $row ?: [];
    }

    /** Create / update a job */
    public static function save(\PDO $pdo, array $in): array
    {
        $man = trim((string)($in['manufacturer'] ?? ''));
        if ($man === '') {
            throw new \RuntimeException('Manufacturer required');
        }

        // ensure manufacturer + its unified table
        $m = \hs_ensure_manufacturer($pdo, $man);

        $title = trim((string)($in['title'] ?? $man));
        $file  = trim((string)($in['file_path'] ?? ''));
        if ($file === '') {
            throw new \RuntimeException('Source file path required');
        }

        $mode = (string)($in['mode'] ?? 'replace');
        if (!in_array($mode, ['replace', 'merge'], true)) {
            $mode = 'replace';
        }

        $columns_map = json_decode((string)($in['columns_map'] ?? '{}'), true) ?: [];
        $transforms  = json_decode((string)($in['transforms'] ?? '{}'), true) ?: [];
        $enabled     = (int)($in['enabled'] ?? 1);

        // Minimal mapping sanity: require code + stock columns defined
        if (empty($columns_map['code'])) {
            throw new \RuntimeException('Column mapping for "code" is required');
        }
        if (empty($columns_map['stock'])) {
            throw new \RuntimeException('Column mapping for "stock" is required');
        }

        if (!empty($in['id'])) {
            qexec(
                'UPDATE hs_import_jobs
                    SET manufacturer_id=?,
                        title=?,
                        file_path=?,
                        enabled=?,
                        mode=?,
                        columns_map=?,
                        transforms=?,
                        updated_at=NOW()
                  WHERE id=?',
                [
                    $m['id'],
                    $title,
                    $file,
                    $enabled,
                    $mode,
                    json_encode($columns_map, JSON_UNESCAPED_UNICODE),
                    json_encode($transforms,  JSON_UNESCAPED_UNICODE),
                    (int)$in['id']
                ]
            );
            return ['id' => (int)$in['id']];
        }

        qexec(
            'INSERT INTO hs_import_jobs
               (manufacturer_id, title, file_path, enabled, mode, columns_map, transforms, created_at)
             VALUES (?,?,?,?,?,?,?,NOW())',
            [
                $m['id'],
                $title,
                $file,
                $enabled,
                $mode,
                json_encode($columns_map, JSON_UNESCAPED_UNICODE),
                json_encode($transforms,  JSON_UNESCAPED_UNICODE)
            ]
        );
        return ['id' => (int)qlastid()];
    }

    /** Delete a job */
    public static function delete(\PDO $pdo, int $id): void
    {
        qexec('DELETE FROM hs_import_jobs WHERE id=?', [$id]);
    }

    /**
     * Run a job:
     *  - creates hs_runs row
     *  - updates job status
     *  - snapshots source file into hs_uploads
     *  - returns run_id + upload_id (frontend then calls import.start)
     */
    public static function run(\PDO $pdo, int $id, \debug_sentinel $sentinel): array
    {
        $job = qrow('SELECT * FROM hs_import_jobs WHERE id=?', [$id]);
        if (!$job) {
            throw new \RuntimeException('Job not found');
        }

        // Create run row
        qexec('INSERT INTO hs_runs (job_id, status, started_at) VALUES (?, "started", NOW())', [$id]);
        $runId = (int)qlastid();

        // Update job status immediately
        qexec('UPDATE hs_import_jobs SET last_status="running", last_run_at=NOW() WHERE id=?', [$id]);

        // Register upload snapshot (copy file into storage)
        require_once __DIR__ . '/UploadsController.php';
        $up = UploadsController::register(
            $pdo,
            ['job_id' => $id, 'file_path' => $job['file_path']],
            $sentinel,
            $runId
        );

        return ['run_id' => $runId, 'upload_id' => $up['upload_id']];
    }
}
