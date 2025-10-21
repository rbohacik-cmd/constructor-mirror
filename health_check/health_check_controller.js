import { initUploadForm } from './uploadForm.js';
import { initJobsTable }  from './jobsTable.js';

(() => {
  const form = document.getElementById('hc-upload-form');
  if (!form) return;

  const base = form.dataset;
  const routes = {
    upload:       base.routeUpload       || (base.logicUrl    ? (base.logicUrl + '?action=upload')        : 'health_check_logic.php?action=upload'),
    import:       base.routeImport       || (base.logicUrl    ? (base.logicUrl + '?action=import')        : 'health_check_logic.php?action=import'),
    progress:     base.routeProgress     || (base.progressUrl ? (base.progressUrl + '?action=status')     : 'health_check_logic.php?action=status'),
    jobs_list:    base.routeJobsList     || (base.jobsUrl     ? (base.jobsUrl + '?action=jobs_list')      : 'health_check_logic.php?action=jobs_list'),
    job_get:      base.routeJobGet       || (base.jobsUrl     ? (base.jobsUrl + '?action=job_get')        : 'health_check_logic.php?action=job_get'),
    job_save:     base.routeJobSave      || (base.jobsUrl     ? (base.jobsUrl + '?action=job_save')       : 'health_check_logic.php?action=job_save'),
    job_delete:   base.routeJobDelete    || (base.jobsUrl     ? (base.jobsUrl + '?action=job_delete')     : 'health_check_logic.php?action=job_delete'),
    job_run:      base.routeJobRun       || (base.jobsUrl     ? (base.jobsUrl + '?action=job_run')        : 'health_check_logic.php?action=job_run'),
    job_run_stat: base.routeJobRunStatus || (base.jobsUrl     ? (base.jobsUrl + '?action=job_run_status') : 'health_check_logic.php?action=job_run_status'),

    // NEW (optional): server-side file browser (supports hints)
    file_picker:  base.routeFilePicker   || (base.filesUrl    ? (base.filesUrl + '?action=file_picker')
                                                              : 'health_check.php?action=file_picker'),
  };

  // If you plan to show stock header hints in the picker, have initUploadForm
  // call routes.file_picker with { dir, q, hints: true }.
  initUploadForm({ routes /*, pickerHints: true */ });
  initJobsTable({ routes });
})();
