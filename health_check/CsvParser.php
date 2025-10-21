<?php
declare(strict_types=1);

namespace HealthCheck\Parsers;

final class CsvParser implements \IteratorAggregate {
  public function __construct(private string $path, private string $delimiter = ',') {}

  public function getIterator(): \Traversable {
    $fh = fopen($this->path, 'rb');
    if (!$fh) throw new \RuntimeException('Cannot open CSV.');
    $header = null;
    while (($row = fgetcsv($fh, 0, $this->delimiter)) !== false) {
      if ($header === null) { $header = $row; continue; }
      yield array_combine($header, $row);
    }
    fclose($fh);
  }
}
