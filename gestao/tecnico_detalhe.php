<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$pdo = gestao_db();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM technicians WHERE id = ?');
$stmt->execute([$id]);
$technician = $stmt->fetch();

if (!$technician) {
    http_response_code(404);
    die('Técnico não encontrado.');
}

$balance = technician_stock_balance($pdo, $id);

$received = $pdo->prepare(
    "SELECT sm.*, u.name AS created_by_name FROM stock_movements sm
     LEFT JOIN users u ON u.id = sm.created_by
     WHERE sm.type = 'saida_para_tecnico' AND sm.technician_id = ?
     ORDER BY sm.created_at DESC"
);
$received->execute([$id]);
$receivedRows = $received->fetchAll();

$orders = $pdo->prepare(
    'SELECT * FROM work_orders WHERE technician_id = ? ORDER BY install_date DESC, created_at DESC'
);
$orders->execute([$id]);
$orderRows = $orders->fetchAll();

$pageTitle = 'Técnico: ' . $technician['name'];
$activePage = 'tecnicos';
require __DIR__ . '/includes/header.php';
?>

<p><a href="tecnicos.php">&larr; Voltar para técnicos</a></p>

<section class="cards">
  <div class="card">
    <p class="card-label">Saldo atual de kits</p>
    <p class="card-value"><?= $balance ?> un.</p>
  </div>
  <div class="card">
    <p class="card-label">OS executadas</p>
    <p class="card-value"><?= count($orderRows) ?></p>
  </div>
</section>

<section class="panel">
  <h2>Kits recebidos</h2>
  <table class="data-table">
    <thead><tr><th>Data</th><th>Quantidade</th><th>Nota</th><th>Registrado por</th></tr></thead>
    <tbody>
      <?php foreach ($receivedRows as $r): ?>
        <tr>
          <td><?= h(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td>
          <td>+<?= (int) $r['quantity'] ?></td>
          <td><?= h($r['note'] ?? '—') ?></td>
          <td><?= h($r['created_by_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$receivedRows): ?><tr><td colspan="4" class="empty">Nenhum envio registrado.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<section class="panel">
  <h2>OS executadas (baixa de kit)</h2>
  <table class="data-table">
    <thead><tr><th>Nº OS</th><th>Data</th><th>Cidade</th><th>UF</th></tr></thead>
    <tbody>
      <?php foreach ($orderRows as $o): ?>
        <tr>
          <td><?= h($o['os_number']) ?></td>
          <td><?= h($o['install_date'] ?? '—') ?></td>
          <td><?= h($o['city'] ?? '—') ?></td>
          <td><?= h($o['uf'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orderRows): ?><tr><td colspan="4" class="empty">Nenhuma OS registrada para este técnico.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
