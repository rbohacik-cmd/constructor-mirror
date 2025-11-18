<?php
declare(strict_types=1);

// One bootstrap to rule them all
require_once __DIR__ . '/../partials/bootstrap.php';

// Cross-module include: use PROJECT_FS (root-constant rule)
if (!function_exists('appcfg')) {
  require_once PROJECT_FS . '/appcfg.php';
}

// ✅ Force HTML/UTF-8 early (before any output)
if (!headers_sent()) {
  @ini_set('default_charset', 'UTF-8');
  header('Content-Type: text/html; charset=UTF-8');
}
if (function_exists('mb_internal_encoding')) {
  @mb_internal_encoding('UTF-8');
}

$cfg           = appcfg();
$brand         = $cfg['brand']          ?? [];
$siteTitle     = $cfg['site_title']     ?? 'Constructor Local';
$readOnlyLabel = $cfg['readonly_label'] ?? 'READ-ONLY';

// Optional: language from config, fallback to en
$lang = $cfg['lang'] ?? 'en';
?>
<!doctype html>
<html lang="<?= e($lang) ?>">
<head>
  <meta charset="utf-8">
  <title><?= e($siteTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Bootstrap Icons (for folder/chevrons etc.) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <!-- Project CSS (root-level) -->
  <link href="<?= e(asset_url('custom.css')) ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-md navbar-dark shadow-sm sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand px-0 d-flex align-items-center gap-2" href="<?= e(url_rel('index.php')) ?>">
      <span class="brand d-flex align-items-center gap-2">
        <span class="brand-logo"><?= e($brand['logo_text'] ?? 'CL') ?></span>
        <span class="brand-text lh-sm">
          <span class="title d-block"><?= e($siteTitle) ?></span>
          <span class="subtitle small text-secondary"><?= e($brand['subtitle'] ?? 'digital union comrade') ?></span>
        </span>
      </span>
    </a>

    <?php if (!empty($readOnlyLabel)): ?>
      <div class="ms-3 d-none d-md-block">
        <span class="chip readonly-badge"><?= e($readOnlyLabel) ?></span>
      </div>
    <?php endif; ?>

    <button class="navbar-toggler ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#topnav" aria-controls="topnav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse ms-md-3" id="topnav">
      <ul class="navbar-nav ms-auto align-items-center gap-2">
        <?php if (exists_rel('index.php')): ?>
          <li class="nav-item">
            <a class="btn btn-sm btn-outline-info<?= active_exact('index.php') ?>" href="<?= e(url_rel('index.php')) ?>">Dashboard</a>
          </li>
        <?php endif; ?>

        <?php
          // Health System
          $hsCandidates = [
            'health_system/public/index.php'                 => 'HS · Import Jobs',
            'health_system/public/hs_manufacturer_check.php' => 'HS · Items — EOL/No Stock/Bad EAN',
          ];
          $hsFilesExisting = [];
          foreach ($hsCandidates as $file => $label) {
            if (exists_rel($file)) $hsFilesExisting[$file] = $label;
          }
          $hsActive = $hsFilesExisting ? active_any_of(array_keys($hsFilesExisting)) : '';
        ?>
        <?php if ($hsFilesExisting): ?>
          <li class="nav-item dropdown">
            <button class="btn btn-sm btn-outline-success dropdown-toggle<?= $hsActive ?>" data-bs-toggle="dropdown">
              Health System
            </button>
            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
              <?php foreach ($hsFilesExisting as $file => $label): ?>
                <li><a class="dropdown-item<?= active_exact($file) ?>" href="<?= e(url_rel($file)) ?>"><?= e($label) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php
          // Parameters
          $paramCandidates = [
            'parameters/health_check_categories.php'             => 'KABEL - Categories',
            'parameters/health_parameters_mapper.php'            => 'Param groups mapper',
            'parameters/health_check_parameters_by_category.php' => 'Parameters by category',
            'parameters/health_check_validate_parameters.php'    => 'Validate parameters',
          ];
          $paramFilesExisting = [];
          foreach ($paramCandidates as $file => $label) {
            if (exists_rel($file)) $paramFilesExisting[$file] = $label;
          }
          $paramActive = active_any_of(array_keys($paramFilesExisting));
        ?>
        <?php if ($paramFilesExisting): ?>
          <li class="nav-item dropdown">
            <button class="btn btn-sm btn-outline-info dropdown-toggle<?= $paramActive ?>" data-bs-toggle="dropdown">Parameters</button>
            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
              <?php foreach ($paramFilesExisting as $file => $label): ?>
                <li><a class="dropdown-item<?= active_exact($file) ?>" href="<?= e(url_rel($file)) ?>"><?= e($label) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php
          // FTP
          $ftpFiles = ['ftp_manager/ftp_connections.php','ftp_manager/ftp_jobs_manager.php','ftp_manager/ftp_runs.php','ftp_manager/ftp_manifest.php'];
          $anyFtp   = array_reduce($ftpFiles, fn($c,$f)=>$c||exists_rel($f), false);
          $ftpActive= active_any_of($ftpFiles);
        ?>
        <?php if ($anyFtp): ?>
          <li class="nav-item dropdown">
            <button class="btn btn-sm btn-outline-info dropdown-toggle<?= $ftpActive ?>" data-bs-toggle="dropdown">FTP</button>
            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
              <?php foreach ($ftpFiles as $f): if (exists_rel($f)): ?>
                <li><a class="dropdown-item<?= active_exact($f) ?>" href="<?= e(url_rel($f)) ?>"><?= e(pathinfo($f, PATHINFO_FILENAME)) ?></a></li>
              <?php endif; endforeach; ?>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="<?= e(url_rel('data/downloads/')) ?>" target="_blank" rel="noopener">Open downloads folder</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <?php
          // DB Transfer
          $xferFiles   = ['apps/transfer/index.php','apps/transfer/job_edit.php','apps/transfer/runs.php'];
          $anyXfer     = array_reduce($xferFiles, fn($c,$f)=>$c||exists_rel($f), false);
          $xferActive  = active_any_of($xferFiles);
        ?>
        <?php if ($anyXfer): ?>
          <li class="nav-item dropdown">
            <button class="btn btn-sm btn-outline-info dropdown-toggle<?= $xferActive ?>" data-bs-toggle="dropdown">DB Transfer</button>
            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
              <?php if (exists_rel('apps/transfer/index.php')): ?>
                <li><a class="dropdown-item<?= active_exact('apps/transfer/index.php') ?>" href="<?= e(url_rel('apps/transfer/index.php')) ?>">Jobs</a></li>
              <?php endif; ?>
              <?php if (exists_rel('apps/transfer/job_edit.php')): ?>
                <li><a class="dropdown-item<?= active_exact('apps/transfer/job_edit.php') ?>" href="<?= e(url_rel('apps/transfer/job_edit.php')) ?>">New job</a></li>
              <?php endif; ?>
              <?php if (exists_rel('apps/transfer/runs.php')): ?>
                <li><a class="dropdown-item<?= active_exact('apps/transfer/runs.php') ?>" href="<?= e(url_rel('apps/transfer/runs.php')) ?>">Runs</a></li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php
          // Viewers
          $viewerActive = active_any_of(['apps/ts/server-ts.php','apps/mysql/index.php']);
          $hasTsViewer  = exists_rel('apps/ts/server-ts.php');
          $hasMyViewer  = exists_rel('apps/mysql/index.php');
        ?>
        <?php if ($hasTsViewer || $hasMyViewer): ?>
          <li class="nav-item dropdown">
            <button class="btn btn-sm btn-outline-info dropdown-toggle<?= $viewerActive ?>" data-bs-toggle="dropdown">Open viewer…</button>
            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
              <?php if ($hasTsViewer): ?>
                <li><a class="dropdown-item<?= active_exact('apps/ts/server-ts.php') ?>" href="<?= e(url_rel('apps/ts/server-ts.php')) ?>">TS Server (MSSQL)</a></li>
              <?php endif; ?>
              <?php if ($hasMyViewer): ?>
                <li><a class="dropdown-item<?= active_exact('apps/mysql/index.php') ?>" href="<?= e(url_rel('apps/mysql/index.php')) ?>">BlueHost (MySQL)</a></li>
              <?php endif; ?>
            </ul>
          </li>
        <?php endif; ?>

        <?php
          // Tools
          $toolFiles   = [
            'tools/self_check.php',
            'tools/local_constructor_structure.php',
            'tools/table_usage_audit.php',
            'tools/file_usage_check.php',
            'tools/php_info.php',
            'tools/broken_links_check.php',
          ];
          $anyTools    = array_reduce($toolFiles, fn($c,$f)=>$c||exists_rel($f), false);
          $toolsActive = active_any_of($toolFiles);
        ?>
        <?php if ($anyTools): ?>
          <li class="nav-item dropdown">
            <button class="btn btn-sm btn-outline-info dropdown-toggle<?= $toolsActive ?>" data-bs-toggle="dropdown">Tools</button>
            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
              <?php foreach ($toolFiles as $f): if (exists_rel($f)): ?>
                <li><a class="dropdown-item<?= active_exact($f) ?>" href="<?= e(url_rel($f)) ?>"><?= e(pathinfo($f, PATHINFO_FILENAME)) ?></a></li>
              <?php endif; endforeach; ?>
            </ul>
          </li>
        <?php endif; ?>

      </ul>
    </div>
  </div>
</nav>

<main class="container py-3">
