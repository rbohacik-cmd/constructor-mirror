<?php /* partials/footer.php */ ?>
</main>

<footer class="container py-4">
  <div class="text-center text-secondary small">
    Constructor Local • <?= date('Y') ?> • Digital Union
  </div>
</footer>

<?php
// --- helpers (de-duped emitters) ------------------------
$GLOBALS['__HS_SCRIPTS_ADDED__'] ??= [];

function script_tag_once(string $src, array $attrs = [], bool $defer = true): string {
  if (isset($GLOBALS['__HS_SCRIPTS_ADDED__'][$src])) return '';   // skip duplicates
  $GLOBALS['__HS_SCRIPTS_ADDED__'][$src] = true;

  $attr = $attrs;
  if ($defer && !isset($attr['defer'])) $attr['defer'] = 'defer';
  $attrStr = '';
  foreach ($attr as $k=>$v) $attrStr .= ' ' . htmlspecialchars($k) . '="' . htmlspecialchars((string)$v) . '"';
  return '<script src="' . htmlspecialchars($src) . '"' . $attrStr . "></script>\n";
}

/**
 * $defs element can be:
 * - ['src' => '/path/file.js', 'defer'=>true, 'attrs'=>['crossorigin'=>'anonymous']]
 * - ['inline' => 'console.log("hi")', 'id' => 'unique-id']  // id de-dupes inline blocks
 */
function scripts_html_once(array $defs): string {
  $out = '';
  foreach ($defs as $def) {
    if (isset($def['src'])) {
      $out .= script_tag_once($def['src'], $def['attrs'] ?? [], (bool)($def['defer'] ?? true));
    } elseif (isset($def['inline'])) {
      $id = $def['id'] ?? md5($def['inline']);
      if (isset($GLOBALS['__HS_SCRIPTS_ADDED__'][$id])) continue; // dedupe inline by id
      $GLOBALS['__HS_SCRIPTS_ADDED__'][$id] = true;
      $attrStr = isset($def['type']) ? ' type="'.htmlspecialchars($def['type']).'"' : '';
      $out .= '<script'.$attrStr.'>' . $def['inline'] . "</script>\n";
    }
  }
  return $out;
}
?>

<!-- Bootstrap bundle (CDN) -->
<?= script_tag_once('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js') ?>

<?php
// Optional per-page JS hook.
// Prefer array form so we can dedupe & keep order.
// $extraScripts may still be a string for legacy pages.
if (!empty($extraScripts)) {
  if (is_array($extraScripts)) {
    echo scripts_html_once($extraScripts);
  } else {
    // last-resort: emit raw string (can't dedupe reliably)
    echo $extraScripts;
  }
}
?>
</body>
</html>
