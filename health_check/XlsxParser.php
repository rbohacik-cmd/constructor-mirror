<?php
declare(strict_types=1);

namespace HealthCheck\Parsers;

final class XlsxParser implements \IteratorAggregate {
  public function __construct(private string $path) {}

  public function getIterator(): \Traversable {
    if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
      throw new \RuntimeException('XLSX parsing requires PhpSpreadsheet.');
    }
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($this->path);
    $reader->setReadDataOnly(true);
    $sheet = $reader->load($this->path)->getActiveSheet();
    $rows  = $sheet->toArray(null, true, true, true);
    $header = null;
    foreach ($rows as $r) {
      if ($header === null) { $header = array_values($r); continue; }
      $assoc = [];
      $i = 0;
      foreach ($r as $cell) { $assoc[$header[$i] ?? "col_$i"] = $cell; $i++; }
      yield $assoc;
    }
  }
}
