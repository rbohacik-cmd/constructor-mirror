<?php
declare(strict_types=1);

function hc_require_post(): array {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    hc_json_error('Use POST', 405);
  }
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  $raw = file_get_contents('php://input') ?: '';
  $isJson = stripos($ct, 'application/json') !== false;
  return $isJson ? (json_decode($raw, true) ?: []) : $_POST;
}

function hc_action(array $in): string {
  return (string)($_GET['action'] ?? ($in['action'] ?? ''));
}

function hc_json_ok(array $payload = [], int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function hc_json_error(string $msg, int $code = 400): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}
