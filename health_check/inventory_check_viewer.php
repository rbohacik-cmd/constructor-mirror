<?php
declare(strict_types=1);
require_once __DIR__ . '/../partials/bootstrap.php';

$pdo = db();
$q = trim((string)($_GET['q'] ?? ''));
$where = '';
$args = [];
if ($q !== '') {
  $where = "WHERE (code LIKE ? OR katalog LIKE ? OR ean LIKE ? OR name LIKE ?)";
  $like = "%$q%";
  $args = [$like,$like,$like,$like];
}
$sql = "SELECT id, code, katalog, ean, name, found_pieces, user_id, created_at
          FROM inventory_checks
          $where
         ORDER BY created_at DESC
         LIMIT 200";
$rows = $pdo->prepare($sql);
$rows->execute($args);
$data = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<?php require_once __DIR__ . '/../partials/header.php'; ?>

<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h5 m-0">Inventory Check — Recent Entries</h1>
    <form class="d-flex gap-2" method="get">
      <input class="form-control" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Filter…">
      <button class="btn btn-outline-secondary">Search</button>
      <a class="btn btn-secondary" href="inventory_check.php">Back</a>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-sm table-striped align-middle">
      <thead class="table-dark">
        <tr>
          <th>#</th>
          <th>Code</th>
          <th>Katalog</th>
          <th>EAN</th>
          <th>Name</th>
          <th class="text-end">Qty</th>
          <th>User</th>
          <th>Created</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($data as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars((string)$r['code']) ?></td>
            <td><?= htmlspecialchars((string)$r['katalog']) ?></td>
            <td><?= htmlspecialchars((string)$r['ean']) ?></td>
            <td><?= htmlspecialchars((string)$r['name']) ?></td>
            <td class="text-end"><?= (int)$r['found_pieces'] ?></td>
            <td><?= htmlspecialchars((string)($r['user_id'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)$r['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$data): ?>
          <tr><td colspan="8" class="text-center text-muted">No records.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>