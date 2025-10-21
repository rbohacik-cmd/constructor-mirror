<?php
declare(strict_types=1);

namespace HealthCheck;

final class StatusStore {
  private string $dir;

  public function __construct(string $dir) {
    $this->dir = rtrim($dir, '/\\');
    if (!is_dir($this->dir)) @mkdir($this->dir, 0775, true);
  }

  public function newId(): string {
    return bin2hex(random_bytes(8)) . '-' . dechex(time());
  }

  private function path(string $id): string {
    return $this->dir . DIRECTORY_SEPARATOR . $id . '.json';
  }

  public function write(string $id, array $data): void {
    file_put_contents($this->path($id), json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  }

  public function read(string $id): ?array {
    $p = $this->path($id);
    if (!is_file($p)) return null;
    $j = json_decode((string)file_get_contents($p), true);
    return is_array($j) ? $j : null;
  }
}
