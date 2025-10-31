<?php
// health_system/lib/hs_code_norm.php
declare(strict_types=1);

/** Returns array of comparable keys for an HS code based on manufacturer profile. */
function hs_make_match_keys(string $hsCode, array $mfg): array {
  $hsCode = trim($hsCode);
  if ($hsCode === '') return [];

  $keys = [ mb_strtolower($hsCode) ];

  // Optionally also compare “code without prefix”, e.g. L20439 -> 20439 for Lindy
  $pref = (string)($mfg['code_prefix'] ?? '');
  if ($pref !== '' && !empty($mfg['make_article_key'])) {
    if (mb_strtolower(mb_substr($hsCode, 0, mb_strlen($pref))) === mb_strtolower($pref)) {
      $article = trim(mb_substr($hsCode, mb_strlen($pref)));
      if ($article !== '') $keys[] = mb_strtolower($article);
    }
  }
  // De-dupe
  return array_values(array_unique($keys));
}
