<?php
declare(strict_types=1);

namespace HealthCheck;

final class UploadService {
  public function __construct(private StatusStore $store) {}

  /**
   * @return array{0:string uploadId,1:string tmpPath,2:array meta}
   */
  public function receiveUploadedFile(?array $file, array $allowedExts): array {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      throw new \RuntimeException('Upload failed.');
    }
    $orig = (string)($file['name'] ?? 'upload');
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
      throw new \RuntimeException("Unsupported file type .$ext");
    }

    $uploadId = $this->store->newId();
    $tmpPath  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hc_' . $uploadId . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
      // Fallback for non-SAPI env
      if (!rename($file['tmp_name'], $tmpPath)) {
        throw new \RuntimeException('Failed to store uploaded file.');
      }
    }
    return [$uploadId, $tmpPath, ['original'=>$orig,'ext'=>$ext,'size'=>(int)$file['size']]];
  }
}
