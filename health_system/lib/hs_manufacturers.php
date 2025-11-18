<?php
declare(strict_types=1);

/**
 * One place to describe how each manufacturer maps HS <-> MSSQL
 * - hs_table:   local HS table (has unified columns: code, ean, name, stock, eta) 
 *               (see hs_lindy / hs_roline schemas)  // hs_* tables have `code` unified. 
 * - ms_name:    MSSQL Vyrobce_Nazev to filter Artikly_Artikl
 * - ms_code_col: which MSSQL column we match on: 'Kod' or 'Katalog'
 * - code_prefix: (optional) remove this prefix before comparing (e.g., 'L' for Lindy)
 * - make_article_key: (bool) also compare with code minus prefix (Lindy behavior)
 */
function hs_manufacturer_profiles(): array {
  return [
    'lindy' => [
      'hs_table'        => 'hs_lindy',
      'ms_name'         => 'Lindy',
      'ms_code_col'     => 'Kod',       // compare HS code to MSSQL a.Kod  (Lindy pages use Kod)  :contentReference[oaicite:1]{index=1}
      'code_prefix'     => 'L',
      'make_article_key'=> true,        // compare on raw + code without 'L'
    ],
    'roline' => [
      'hs_table'        => 'hs_roline',
      'ms_name'         => 'Roline',
      'ms_code_col'     => 'Katalog',   // compare HS code to MSSQL a.Katalog (Roline uses "Katalog")
      'code_prefix'     => '',
      'make_article_key'=> false,
    ],
    // add more:
    'wentronic' => [
      'hs_table'        => 'hs_wentronic',
      'ms_name'         => 'Wentronic',
      'ms_code_col'     => 'Kod',
      'code_prefix'     => '',
      'make_article_key'=> false,
    ],
    'efb' => [
      'hs_table'        => 'hs_efb',
      'ms_name'         => 'EFB',
      'ms_code_col'     => 'Kod',
      'code_prefix'     => '',
      'make_article_key'=> false,
    ],
	'inline' => [
      'hs_table'        => 'hs_inline',
      'ms_name'         => 'InLine',
      'ms_code_col'     => 'Kod',
      'code_prefix'     => '',
      'make_article_key'=> false,
    ],
  ];
}

function hs_mfg(string $slug): array {
  $all = hs_manufacturer_profiles();
  if (!isset($all[$slug])) {
    throw new RuntimeException("Unknown manufacturer profile: {$slug}");
  }
  return $all[$slug];
}
