<?php
declare(strict_types=1);

/**
 * Shared web helpers
 * Requires /partials/bootstrap.php to have defined:
 * - PROJECT_FS (filesystem root of the project, no trailing slash)
 * - BASE_URL   (project base URL, always trailing slash)
 * - REQ_REL    (request path relative to project root, may be empty in CLI)
 */

/** Detect absolute (or protocol-relative) URLs */
if (!function_exists('is_abs_url')) {
  function is_abs_url(string $s): bool {
    $s = ltrim($s);
    // http://, https://, //cdn...
    return (bool)preg_match('~^(?:[a-z][a-z0-9+\-.]*:)?//~i', $s);
  }
}

/** HTML escape */
if (!function_exists('e')) {
  function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

/** Absolute FS path from project-relative (normalized forward slashes) */
if (!function_exists('project_path')) {
  function project_path(string $rel): string {
    $rel = ltrim(str_replace(['\\','//'], ['/', '/'], $rel), '/');
    return PROJECT_FS . '/' . $rel;
  }
}

/** Absolute URL from project-relative (normalized forward slashes; passes through absolute URLs) */
if (!function_exists('project_url')) {
  function project_url(string $rel): string {
    $rel = str_replace('\\','/',$rel);
    if (is_abs_url($rel)) return $rel; // pass-through for CDN/absolute URLs
    $rel = ltrim($rel, '/');
    return rtrim(BASE_URL, '/') . '/' . $rel;
  }
}

/** File exists relative to project root */
if (!function_exists('exists_rel')) {
  function exists_rel(string $rel): bool {
    if (is_abs_url($rel)) return false;
    return is_file(project_path($rel));
  }
}

/** Build absolute URL from project-relative path (alias; passes through absolute) */
if (!function_exists('url_rel')) {
  function url_rel(string $rel): string {
    return project_url($rel);
  }
}

/**
 * Cache-busted asset URL using filemtime().
 * If file is missing, returns plain URL; passes through absolute URLs.
 */
if (!function_exists('asset_url')) {
  function asset_url(string $rel): string {
    if (is_abs_url($rel)) return $rel; // pass-through for CDN/absolute URLs
    $fs  = project_path($rel);
    $url = project_url($rel);
    if (is_file($fs)) {
      $v = (string)filemtime($fs);
      return $url . '?v=' . rawurlencode($v);
    }
    return $url;
  }
}

/** Active helpers (compare against REQ_REL) */
if (!function_exists('active_exact')) {
  function active_exact(string $relFile): string {
    $cur = ltrim((string)(defined('REQ_REL') ? REQ_REL : ''), '/');
    $rel = ltrim($relFile, '/');
    return ($cur === $rel) ? ' active' : '';
  }
}

if (!function_exists('active_any_of')) {
  function active_any_of(array $relFiles): string {
    $cur = ltrim((string)(defined('REQ_REL') ? REQ_REL : ''), '/');
    foreach ($relFiles as $f) {
      if ($cur === ltrim((string)$f, '/')) return ' active';
    }
    return '';
  }
}

/** Build a single <script> tag from a path (project-relative or absolute) */
if (!function_exists('script_tag')) {
  /**
   * Generates a script tag with proper URL resolution.
   *
   * @param string $relOrAbs Project-relative (e.g. 'health_check/controller.js') or absolute CDN URL
   * @param array  $attrs    Optional HTML attributes (e.g. ['type'=>'module','defer'=>true])
   * @param bool   $cacheBust Use asset_url() (true) or url_rel() (false) for project-relative paths.
   */
  function script_tag(string $relOrAbs, array $attrs = [], bool $cacheBust = true): string {
    $src = is_abs_url($relOrAbs)
      ? $relOrAbs
      : ($cacheBust ? asset_url($relOrAbs) : url_rel($relOrAbs));

    $html = '<script src="' . e($src) . '"';
    foreach ($attrs as $key => $val) {
      if (is_bool($val)) {
        if ($val) $html .= ' ' . htmlspecialchars($key, ENT_QUOTES);
      } else {
        $html .= ' ' . htmlspecialchars($key, ENT_QUOTES) . '="' . htmlspecialchars((string)$val, ENT_QUOTES) . '"';
      }
    }
    $html .= '></script>';
    return $html;
  }
}

/** Build multiple script tags at once */
if (!function_exists('scripts_html')) {
  /**
   * @param array<array{path:string,attrs?:array,cacheBust?:bool}> $scripts
   */
  function scripts_html(array $scripts): string {
    $tags = [];
    foreach ($scripts as $s) {
      $path = $s['path'] ?? '';
      if ($path === '') continue;
      $attrs = $s['attrs'] ?? [];
      $cache = array_key_exists('cacheBust', $s) ? (bool)$s['cacheBust'] : true;
      $tags[] = script_tag($path, $attrs, $cache);
    }
    return implode("\n", $tags);
  }
}
