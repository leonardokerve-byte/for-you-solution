<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$pdo = gestao_db();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $note = trim($_POST['note'] ?? '') ?: null;

    if ($quantity <= 0) {
        $error = 'Informe uma quantidade válida.';
    } elseif ($action === 'entrada') {
        $stmt = $pdo->prepare(
            "INSERT INTO stock_movements (type, quantity, note, created_by) VALUES ('entrada_distribuidora', ?, ?, ?)"
        );
        $stmt->execute([$quantity, $note, $user['id']]);
        $success = "Entrada de $quantity kit(s) registrada.";
    } elseif ($action === 'saida') {
        $technicianId = (int) ($_POST['technician_id'] ?? 0);
        $currentBalance = distributor_stock_balance($pdo);

        if ($technicianId <= 0) {
            $error = 'Selecione o técnico.';
        } elseif ($quantity > $currentBalance) {
            $error = "Saldo insuficiente na distribuidora (disponível: $currentBalance).";
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO stock_movements (type, technician_id, quantity, note, created_by)
                 VALUES ('saida_para_tecnico', ?, ?, ?, ?)"
            );
            $stmt->execute([$technicianId, $quantity, $note, $user['id']]);
            $success = "Envio de $quantity kit(s) para o técnico registrado.";
        }
    }
}

$balance = distributor_stock_balance($pdo);
$technicians = $pdo->query('SELECT id, name FROM technicians WHERE active = 1 ORDER BY name')->fetchAll();

$movements = $pdo->query(
    "SELECT sm.*, t.name AS technician_name, u.name AS created_by_name
     FROM stock_movements sm
     LEFT JOIN technicians t ON t.id = sm.technician_id
     LEFT JOIN users u ON u.id = sm.created_by
     ORDER BY sm.created_at DESC
     LIMIT 200"
)->fetchAll();

$pageTitle = 'Estoque';
$activePage = 'estoque';
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="alert alert-success"><?= h($success) ?></p><?php endif; ?>
<?php if ($balance < 300): ?>
  <p class="alert alert-warning">Saldo baixo: <strong><?= $balance ?></strong> kits na distribuidora (abaixo de 300).</p>
<?php endif; ?>

<section class="cards">
  <div class="card">
    <p class="card-label">Saldo atual · KIT TVRO</p>
    <p class="card-value"><?= $balance ?> un.</p>
  </div>
</section>

<section class="panel">
  <h2>Registrar entrada</h2>
  <form method="post" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="entrada">
    <label>Quantidade <input type="number" name="quantity" min="1" required></label>
    <label>Nota <span class="optional">(opcional)</span> <input type="text" name="note" maxlength="255"></label>
    <button type="submit" class="btn-primary">Registrar entrada</button>
  </form>
</section>

<section class="panel">
  <h2>Enviar para técnico</h2>
  <form method="post" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="saida">
    <label>Técnico
      <select name="technician_id" required>
        <option value="">Selecione</option>
        <?php foreach ($technicians as $tech): ?>
          <option value="<?= $tech['id'] ?>"><?= h($tech['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Quantidade <input type="number" name="quantity" min="1" required></label>
    <label>Nota <span class="optional">(opcional)</span> <input type="text" name="note" maxlength="255"></label>
    <button type="submit" class="btn-primary">Enviar</button>
  </form>
  <?php if (!$technicians): ?><p class="hint">Nenhum técnico cadastrado ainda. <a href="tecnicos.php">Cadastrar técnico</a>.</p><?php endif; ?>
</section>

<section class="panel">
  <h2>Histórico de movimentos</h2>
  <table class="data-table">
    <thead><tr><th>Data</th><th>Tipo</th><th>Técnico</th><th>Quantidade</th><th>Nota</th><th>Registrado por</th></tr></thead>
    <tbody>
      <?php foreach ($movements as $m): ?>
        <tr>
          <td><?= h(date('d/m/Y H:i', strtotime($m['created_at']))) ?></td>
          <td><?= $m['type'] === 'entrada_distribuidora' ? 'Entrada' : 'Saída p/ técnico' ?></td>
          <td><?= h($m['technician_name'] ?? '—') ?></td>
          <td><?= $m['type'] === 'entrada_distribuidora' ? '+' : '-' ?><?= (int) $m['quantity'] ?></td>
          <td><?= h($m['note'] ?? '—') ?></td>
          <td><?= h($m['created_by_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$movements): ?><tr><td colspan="6" class="empty">Nenhum movimento registrado.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
