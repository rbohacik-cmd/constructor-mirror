<?php
declare(strict_types=1);


return [
// Unified roots (used by rel:// paths)
'HS_IMPORT_ROOT_WIN' => 'C:\\xampp\\htdocs\\imports',
'HS_IMPORT_ROOT_UNX' => '/var/imports',


// Where snapshots are stored (copied files for audit)
'HS_STORAGE_ROOT' => __DIR__ . '/../storage',


// Chunks + timeouts
  'chunk_size'          => 1000,   // was 200
  'lock_timeout_s'      => 300,
  'max_execution_s'     => 900,
  'memory_limit'        => '1024M',

  // NEW: throttle progress writes (rows between updates)
  'progress_throttle_rows' => 1000,

  // NEW: use fast pipeline
  'use_bulk_loader' => true,
  
  'bulk_insert_batch_size' => 3000,   // 2k–5k is a sweet spot
  'progress_throttle_rows' => 1000,   // reduce DB chatter
  'lock_timeout_s'         => 300,    // wait up to 5 min for table lock
];