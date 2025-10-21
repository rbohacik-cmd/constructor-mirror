<?php
declare(strict_types=1);

final class HcRouter {
  public function __construct(private PDO $pdo, private debug_sentinel $sentinel) {}

  public function handle(string $action, array $in, array $files): void {
    // Legacy alias
    if ($action === 'job_list') $action = 'jobs_list';

    $jobs = new JobsController($this->pdo, $this->sentinel);
    $upl  = new UploadsController($this->pdo, $this->sentinel);

    switch ($action) {
      // Jobs
      case 'jobs_list':       $jobs->list(); break;
      case 'job_get':         $jobs->get((int)($in['id'] ?? 0)); break;
      case 'job_save':        $jobs->save($in); break;
      case 'job_delete':      $jobs->delete((int)($in['id'] ?? 0)); break;
      case 'job_run':         $jobs->run((int)($in['id'] ?? 0)); break;
      case 'job_run_status':  $jobs->runStatus((int)($in['run_id'] ?? 0)); break;

      // Upload/Import/Status
      case 'upload':          $upl->upload($files, $in); break;
      case 'import':          $upl->import((int)($in['upload_id'] ?? 0), (int)($in['run_id'] ?? 0)); break;
      case 'status':          $upl->status((int)($in['upload_id'] ?? 0)); break;

      default: hc_json_error('Unknown action');
    }
  }
}
