<?php
declare(strict_types=1);

namespace HS;

use PDO;

final class JobsController
{
    /** List all jobs with manufacturer info + latest run/progress (prefix-only transforms) */
    public static function list(PDO $pdo): array
    {
        $rows = qall(
            // Latest run per job + latest upload for that run + its progress + file path
            'SELECT
                 j.*,
                 m.slug,
                 m.name AS manufacturer_name,

                 -- latest run for this job
                 r.id          AS run_id,
                 r.status      AS run_status,
                 r.started_at  AS run_started_at,
                 r.finished_at AS last_finished_at,

                 -- progress of latest upload in the latest run
                 p.status      AS progress_status,
                 p.rows_done,
                 p.rows_total,
                 p.percent,

                 -- source file path (latest upload in latest run)
                 u.stored_path AS file_path,

                 -- robust "overall last status" fallback used by UI
                 COALESCE(p.status, r.status, j.last_status, "pending") AS last_status
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
             LEFT JOIN hs_uploads  u ON u.id = lu.last_upload_id
             ORDER BY j.id DESC'
        ) ?: [];

        // Compute ETA + normalize JSON fields
        $now = time();
        foreach ($rows as &$row) {
            // ETA (only when we have a running phase + non-zero percent)
            $row['eta_seconds'] = null;
            $pct       = isset($row['percent']) ? (float)$row['percent'] : 0.0;
            $status    = (string)($row['run_status'] ?? '');
            $startedAt = isset($row['run_started_at']) ? strtotime((string)$row['run_started_at']) : null;

            if ($startedAt && $pct > 0 && $pct < 100
                && in_array($status, ['started','running','reading','inserting'], true)) {
                $elapsed = max(1, $now - $startedAt);
                $eta     = (int)round($elapsed * (100.0 / $pct - 1.0));
                if ($eta > 0 && $eta < 604800) { // cap to 7 days for sanity
                    $row['eta_seconds'] = $eta;
                }
            }

            // JSON decode & normalize to prefix-only for UI consumers
            if (isset($row['columns_map']) && is_string($row['columns_map'])) {
                $cm = json_decode($row['columns_map'], true);
                if (is_array($cm)) $row['columns_map'] = $cm;
            }
            if (isset($row['transforms']) && is_string($row['transforms'])) {
                $tf = json_decode($row['transforms'], true);
                if (is_array($tf)) $row['transforms'] = $tf;
            }
            $row['transforms'] = self::toPrefixOnly($row['transforms'] ?? []);
        }
        unset($row);

        return $rows;
    }

    /** Get a single job (decode + prefix-only transforms + manufacturer info) */
    public static function get(PDO $pdo, int $id): array
    {
        if ($id <= 0) {
            return [
                'id' => 0,
                'manufacturer_id'   => null,
                'manufacturer_name' => null,
                'manufacturer'      => null,
                'title'      => '',
                'file_path'  => '',
                'enabled'    => 1,
                'mode'       => 'replace',
                // include eta in defaults so UI can show an empty box
                'columns_map'=> ['code'=>'','ean'=>'','name'=>'','stock'=>'','eta'=>''],
                'transforms' => ['code'=>['trim'=>false,'prefix'=>''], 'name'=>['prefix'=>'']],
                'notes'      => '',
            ];
        }

        $row = qrow(
            'SELECT j.*, m.name AS manufacturer_name, m.slug
               FROM hs_import_jobs j
               JOIN hs_manufacturers m ON m.id = j.manufacturer_id
              WHERE j.id = ?
              LIMIT 1',
            [$id]
        );
        if (!$row) return [];

        // JSON decode
        $row['columns_map'] = self::jsonDecodeSafe($row['columns_map'] ?? '{}');
        $row['transforms']  = self::toPrefixOnly(self::jsonDecodeSafe($row['transforms'] ?? '{}'));

        // expose convenient fields for UI
        $row['manufacturer']     = $row['manufacturer_name'];
        $row['manufacturer_id']  = (int)$row['manufacturer_id'];
        $row['enabled']          = (int)$row['enabled'];
        $row['id']               = (int)$row['id'];

        // Defaults for any missing keys (now including eta)
        $row['columns_map'] += ['code'=>'','ean'=>'','name'=>'','stock'=>'','eta'=>''];
        $row['transforms']  += ['code'=>['trim'=>false,'prefix'=>''], 'name'=>['prefix'=>'']];

        return $row;
    }

    /** Create / update a job (prefix-only; never writes suffix) */
    public static function save(PDO $pdo, array $in): array
    {
        // ---- Extract & normalize ----
        $id              = isset($in['id']) ? (int)$in['id'] : 0;
        $manufacturer_id = (int)($in['manufacturer_id'] ?? 0);
        $title           = trim((string)($in['title'] ?? ''));
        $file_path       = trim((string)($in['file_path'] ?? ''));
        $mode            = (string)($in['mode'] ?? 'replace');
        $enabled         = (int)!!($in['enabled'] ?? 1);
        $notes           = (string)($in['notes'] ?? '');

        // columns_map / transforms may be arrays or JSON strings
        $columns_map = $in['columns_map'] ?? [];
        $transforms  = $in['transforms']  ?? [];

        if (is_string($columns_map)) {
            $t = json_decode($columns_map, true);
            $columns_map = is_array($t) ? $t : [];
        }
        if (is_string($transforms)) {
            $t = json_decode($transforms, true);
            $transforms = is_array($t) ? $t : [];
        }

        // Also accept flat fields from forms: map_code|ean|name|stock|eta (eta optional)
        $flatProvided = isset($in['map_code']) || isset($in['map_ean']) || isset($in['map_name']) || isset($in['map_stock']) || array_key_exists('map_eta', $in);
        if ($flatProvided) {
            $cm = [
                'code'  => trim((string)($in['map_code']  ?? ($columns_map['code']  ?? ''))),
                'ean'   => trim((string)($in['map_ean']   ?? ($columns_map['ean']   ?? ''))),
                'name'  => trim((string)($in['map_name']  ?? ($columns_map['name']  ?? ''))),
                'stock' => trim((string)($in['map_stock'] ?? ($columns_map['stock'] ?? ''))),
            ];
            $eta = trim((string)($in['map_eta'] ?? ($columns_map['eta'] ?? '')));
            if ($eta !== '') { $cm['eta'] = $eta; }
            $columns_map = $cm;
        } else {
            // sanitize any incoming columns_map to allow only known keys and drop empty eta
            $allowed = ['code','ean','name','stock','eta'];
            $columns_map = array_intersect_key((array)$columns_map, array_fill_keys($allowed, true));
            if (isset($columns_map['eta']) && trim((string)$columns_map['eta']) === '') {
                unset($columns_map['eta']);
            }
        }

        // Normalize transforms to prefix-only (convert legacy suffix->prefix, then drop suffix)
        $transforms = self::toPrefixOnly($transforms);

        // Encode JSON for storage
        $columns_map_json = json_encode($columns_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $transforms_json  = json_encode($transforms,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // ---- Defensive validation ----
        if ($manufacturer_id <= 0) { throw new \RuntimeException('Manufacturer required'); }
        if ($title === '')         { throw new \RuntimeException('Title required'); }
        if ($file_path === '')     { throw new \RuntimeException('File path required'); }
        if (!in_array($mode, ['replace','merge'], true)) $mode = 'replace';

        // ---- Write to DB ----
        if ($id > 0) {
            qexec(
                'UPDATE hs_import_jobs
                    SET manufacturer_id=?, title=?, file_path=?, enabled=?, mode=?, columns_map=?, transforms=?, notes=?, updated_at=NOW()
                  WHERE id=?',
                [$manufacturer_id, $title, $file_path, $enabled, $mode, $columns_map_json, $transforms_json, $notes, $id]
            );
            return ['id' => $id, 'updated' => true];
        } else {
            qexec(
                'INSERT INTO hs_import_jobs
                     (manufacturer_id, title, file_path, enabled, mode, columns_map, transforms, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [$manufacturer_id, $title, $file_path, $enabled, $mode, $columns_map_json, $transforms_json, $notes]
            );
            return ['id' => (int)qlastid(), 'created' => true];
        }
    }

    /** Delete a job */
    public static function delete(PDO $pdo, int $id): void
    {
        if ($id <= 0) return;
        qexec('DELETE FROM hs_import_jobs WHERE id=?', [$id]);
    }

    /**
     * Run a job: create hs_runs, update job, snapshot upload, return ids
     */
    public static function run(PDO $pdo, int $id, \debug_sentinel $sentinel): array
    {
        $job = qrow('SELECT * FROM hs_import_jobs WHERE id=?', [$id]);
        if (!$job) throw new \RuntimeException('Job not found');

        qexec('INSERT INTO hs_runs (job_id, status, started_at) VALUES (?, "started", NOW())', [$id]);
        $runId = (int)qlastid();

        qexec('UPDATE hs_import_jobs SET last_status="running", last_run_at=NOW() WHERE id=?', [$id]);

        require_once __DIR__ . '/UploadsController.php';
        $up = UploadsController::register(
            $pdo,
            ['job_id' => $id, 'file_path' => $job['file_path']],
            $sentinel,
            $runId
        );

        return ['run_id' => $runId, 'upload_id' => $up['upload_id']];
    }

    /* ---------------- helpers ---------------- */

    private static function jsonDecodeSafe($v): array
    {
        if (is_array($v)) return $v;
        if ($v === null || $v === '') return [];
        $decoded = json_decode((string)$v, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Convert any legacy/new mix to prefix-only:
     *  - code: {trim, prefix}
     *  - name: {prefix}
     * If only suffix is present, map it to prefix. Suffix is not returned.
     */
    private static function toPrefixOnly($t): array
    {
        $t = is_array($t) ? $t : [];
        $code = is_array($t['code'] ?? null) ? $t['code'] : [];
        $name = is_array($t['name'] ?? null) ? $t['name'] : [];

        $codeTrim   = (bool)($code['trim'] ?? false);
        $codePrefix = (string)($code['prefix'] ?? ($code['suffix'] ?? '')); // legacy -> prefix
        $namePrefix = (string)($name['prefix'] ?? ($name['suffix'] ?? ''));

        return [
            'code' => ['trim' => $codeTrim, 'prefix' => $codePrefix],
            'name' => ['prefix' => $namePrefix],
        ];
    }
}
