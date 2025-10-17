<?php
declare(strict_types=1);

return [
  'chunk_size_default'  => 200,
  'chunk_size_min'      => 10,
  'lock_wait_timeout_s' => 8,     // innodb_lock_wait_timeout
  'net_read_timeout_s'  => 20,    // MySQL net read (if you set it)
  'max_execution_s'     => 900,
  'memory_limit'        => '1024M',
];
