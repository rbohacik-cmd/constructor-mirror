<?php
declare(strict_types=1);


function hs_apply_transforms(?string $val, array $rules): ?string {
if ($val === null) return null;
$s = (string)$val;
if (!empty($rules['trim'])) $s = trim($s);
if (!empty($rules['upper'])) $s = mb_strtoupper($s, 'UTF-8');
if (!empty($rules['lower'])) $s = mb_strtolower($s, 'UTF-8');
if (!empty($rules['remove_prefix'])) {
$rp = (string)$rules['remove_prefix'];
if ($rp !== '' && str_starts_with($s, $rp)) $s = substr($s, strlen($rp));
}
if (!empty($rules['regex'])) {
$rx = (string)$rules['regex']; $repl = (string)($rules['regex_repl'] ?? '');
if (@preg_match($rx, '') !== false) $s = preg_replace($rx, $repl, $s);
}
if (array_key_exists('suffix', $rules)) $s .= (string)$rules['suffix'];
return $s;
}