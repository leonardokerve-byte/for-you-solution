<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$pdo = gestao_db();

$error = null;
$success = null;

$entradaReasons = ['Carga (Entrada de kits)', 'Transferência entre distribuidores'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_distributor') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $error = 'Informe o nome do distribuidor.';
        } else {
            $pdo->prepare('INSERT INTO distributors (name) VALUES (?)')->execute([$name]);
            $success = 'Distribuidor cadastrado.';
        }
    } elseif ($action === 'rename_distributor') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $error = 'Informe o novo nome do distribuidor.';
        } else {
            $pdo->prepare('UPDATE distributors SET name = ? WHERE id = ?')->execute([$name, $id]);
            $success = 'Distribuidor renomeado.';
        }
    } elseif ($action === 'entrada') {
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $distributorId = (int) ($_POST['distributor_id'] ?? 0);
        $reason = $_POST['reason'] ?? '';

        if ($quantity <= 0) {
            $error = 'Informe uma quantidade válida.';
        } elseif ($distributorId <= 0) {
            $error = 'Selecione o distribuidor.';
        } elseif (!in_array($reason, $entradaReasons, true)) {
            $error = 'Selecione o motivo da entrada.';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO stock_movements (type, distributor_id, reason, quantity, created_by)
                 VALUES ('entrada_distribuidora', ?, ?, ?, ?)"
            );
            $stmt->execute([$distributorId, $reason, $quantity, $user['id']]);
            $success = "Entrada de $quantity kit(s) registrada.";
        }
    } elseif ($action === 'saida') {
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $distributorId = (int) ($_POST['distributor_id'] ?? 0);
        $technicianId = (int) ($_POST['technician_id'] ?? 0);
        $note = trim($_POST['note'] ?? '') ?: null;

        if ($quantity <= 0) {
            $error = 'Informe uma quantidade válida.';
        } elseif ($distributorId <= 0) {
            $error = 'Selecione o distribuidor.';
        } elseif ($technicianId <= 0) {
            $error = 'Selecione o técnico.';
        } else {
            $techStmt = $pdo->prepare('SELECT distributor_id FROM technicians WHERE id = ?');
            $techStmt->execute([$technicianId]);
            $techDistributorId = (int) $techStmt->fetchColumn();

            $currentBalance = distributor_stock_balance($pdo, $distributorId);

            if ($techDistributorId !== $distributorId) {
                $error = 'Esse técnico não pertence ao distribuidor selecionado.';
            } elseif ($quantity > $currentBalance) {
                $error = "Saldo insuficiente nesse distribuidor (disponível: $currentBalance).";
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO stock_movements (type, distributor_id, technician_id, quantity, note, created_by)
                     VALUES ('saida_para_tecnico', ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$distributorId, $technicianId, $quantity, $note, $user['id']]);
                $success = "Envio de $quantity kit(s) para o técnico registrado.";
            }
        }
    }
}

$distributors = $pdo->query('SELECT id, name FROM distributors WHERE active = 1 ORDER BY name')->fetchAll();
$distributorBalances = [];
foreach ($distributors as $d) {
    $distributorBalances[$d['id']] = distributor_stock_balance($pdo, (int) $d['id']);
}

$technicians = $pdo->query(
    "SELECT t.id, t.name, t.distributor_id, d.name AS distributor_name
     FROM technicians t LEFT JOIN distributors d ON d.id = t.distributor_id
     WHERE t.active = 1 ORDER BY t.name"
)->fetchAll();

$movements = $pdo->query(
    "SELECT sm.*, t.name AS technician_name, d.name AS distributor_name, u.name AS created_by_name
     FROM stock_movements sm
     LEFT JOIN technicians t ON t.id = sm.technician_id
     LEFT JOIN distributors d ON d.id = sm.distributor_id
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

<section class="cards">
  <?php foreach ($distributors as $d): ?>
    <div class="card <?= $distributorBalances[$d['id']] < 300 ? 'card-negative' : '' ?>">
      <p class="card-label"><?= h($d['name']) ?> · KIT TVRO</p>
      <p class="card-value"><?= $distributorBalances[$d['id']] ?> un.</p>
    </div>
  <?php endforeach; ?>
</section>

<section class="panel">
  <h2>Distribuidores</h2>
  <table class="data-table">
    <thead><tr><th>Nome</th><th>Saldo</th><th>Renomear</th></tr></thead>
    <tbody>
      <?php foreach ($distributors as $d): ?>
        <tr>
          <td><?= h($d['name']) ?></td>
          <td><?= $distributorBalances[$d['id']] ?> un.</td>
          <td>
            <form method="post" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="rename_distributor">
              <input type="hidden" name="id" value="<?= $d['id'] ?>">
              <input type="text" name="name" value="<?= h($d['name']) ?>" required maxlength="120">
              <button type="submit" class="btn-small">Salvar nome</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h3>Novo distribuidor</h3>
  <form method="post" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_distributor">
    <label>Nome <input type="text" name="name" required maxlength="120"></label>
    <button type="submit" class="btn-primary">Cadastrar</button>
  </form>
</section>

<section class="panel">
  <h2>Registrar entrada</h2>
  <form method="post" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="entrada">
    <label>Quantidade <input type="number" name="quantity" min="1" required></label>
    <label>Motivo
      <select name="reason" required>
        <option value="">Selecione</option>
        <?php foreach ($entradaReasons as $r): ?>
          <option value="<?= h($r) ?>"><?= h($r) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Distribuidor
      <select name="distributor_id" required>
        <option value="">Selecione</option>
        <?php foreach ($distributors as $d): ?>
          <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="btn-primary">Registrar entrada</button>
  </form>
</section>

<section class="panel">
  <h2>Enviar para técnico</h2>
  <form method="post" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="saida">
    <label>Distribuidor
      <select name="distributor_id" required>
        <option value="">Selecione</option>
        <?php foreach ($distributors as $d): ?>
          <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Técnico
      <select name="technician_id" required>
        <option value="">Selecione</option>
        <?php foreach ($technicians as $tech): ?>
          <option value="<?= $tech['id'] ?>" data-distributor="<?= $tech['distributor_id'] ?>"><?= h($tech['name']) ?><?= $tech['distributor_name'] ? ' (' . h($tech['distributor_name']) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Quantidade <input type="number" name="quantity" min="1" required></label>
    <label>Nota <span class="optional">(opcional)</span> <input type="text" name="note" maxlength="255"></label>
    <button type="submit" class="btn-primary">Enviar</button>
  </form>
  <?php if (!$technicians): ?><p class="hint">Nenhum técnico cadastrado ainda. <a href="tecnicos.php">Cadastrar técnico</a>.</p><?php endif; ?>
  <p class="hint">O técnico precisa pertencer ao distribuidor selecionado (defina isso na tela Técnicos).</p>
</section>

<section class="panel">
  <h2>Histórico de movimentos</h2>
  <table class="data-table">
    <thead><tr><th>Data</th><th>Tipo</th><th>Distribuidor</th><th>Motivo/Nota</th><th>Técnico</th><th>Quantidade</th><th>Registrado por</th></tr></thead>
    <tbody>
      <?php foreach ($movements as $m): ?>
        <tr>
          <td><?= h(date('d/m/Y H:i', strtotime($m['created_at']))) ?></td>
          <td><?= $m['type'] === 'entrada_distribuidora' ? 'Entrada' : 'Saída p/ técnico' ?></td>
          <td><?= h($m['distributor_name'] ?? '—') ?></td>
          <td><?= h($m['reason'] ?? $m['note'] ?? '—') ?></td>
          <td><?= h($m['technician_name'] ?? '—') ?></td>
          <td><?= $m['type'] === 'entrada_distribuidora' ? '+' : '-' ?><?= (int) $m['quantity'] ?></td>
          <td><?= h($m['created_by_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$movements): ?><tr><td colspan="7" class="empty">Nenhum movimento registrado.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
