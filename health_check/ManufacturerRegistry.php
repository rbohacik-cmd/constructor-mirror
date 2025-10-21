<?php
declare(strict_types=1);

namespace HealthCheck;

/**
 * Resolve a manufacturer and decide a target table.
 * Here we keep it simple: slugify name to table name like "hc_{slug}".
 * Replace with real DB lookups if you maintain manufacturers in SQL.
 */
final class ManufacturerRegistry {
  public function resolveOrCreate(string $name): array {
    $slug = strtolower(preg_replace('~[^a-z0-9]+~i', '_', $name));
    $slug = trim($slug, '_') ?: 'manufacturer';
    return [
      'id'    => crc32($slug),
      'name'  => $name,
      'table' => 'hc_' . $slug,
    ];
  }
}
