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

        if ($quantity <= 0) {
            $error = 'Informe uma quantidade válida.';
        } elseif ($distributorId <= 0) {
            $error = 'Selecione o distribuidor.';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO stock_movements (type, distributor_id, reason, quantity, created_by)
                 VALUES ('entrada_distribuidora', ?, 'Carga (Entrada de kits)', ?, ?)"
            );
            $stmt->execute([$distributorId, $quantity, $user['id']]);
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
    } elseif ($action === 'transferencia') {
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $fromId = (int) ($_POST['from_distributor_id'] ?? 0);
        $toId = (int) ($_POST['to_distributor_id'] ?? 0);

        if ($quantity <= 0) {
            $error = 'Informe uma quantidade válida.';
        } elseif ($fromId <= 0 || $toId <= 0) {
            $error = 'Selecione o distribuidor de origem (De) e de destino (Para).';
        } elseif ($fromId === $toId) {
            $error = 'Origem e destino precisam ser distribuidores diferentes.';
        } else {
            $currentBalance = distributor_stock_balance($pdo, $fromId);
            if ($quantity > $currentBalance) {
                $error = "Saldo insuficiente no distribuidor de origem (disponível: $currentBalance).";
            } else {
                $nameStmt = $pdo->prepare('SELECT id, name FROM distributors WHERE id IN (?, ?)');
                $nameStmt->execute([$fromId, $toId]);
                $names = [];
                foreach ($nameStmt->fetchAll() as $row) {
                    $names[$row['id']] = $row['name'];
                }

                $pdo->beginTransaction();
                $pdo->prepare(
                    "INSERT INTO stock_movements (type, distributor_id, quantity, note, created_by)
                     VALUES ('saida_para_tecnico', ?, ?, ?, ?)"
                )->execute([$fromId, $quantity, 'Transferência para ' . ($names[$toId] ?? '—'), $user['id']]);
                $pdo->prepare(
                    "INSERT INTO stock_movements (type, distributor_id, reason, quantity, created_by)
                     VALUES ('entrada_distribuidora', ?, ?, ?, ?)"
                )->execute([$toId, 'Transferência de ' . ($names[$fromId] ?? '—'), $quantity, $user['id']]);
                $pdo->commit();

                $success = "Transferência de $quantity kit(s) de {$names[$fromId]} para {$names[$toId]} registrada.";
            }
        }
    } elseif ($action === 'ajustar_saldo') {
        $distributorId = (int) ($_POST['distributor_id'] ?? 0);
        $newBalanceRaw = $_POST['new_balance'] ?? '';
        $note = trim($_POST['note'] ?? '') ?: 'Ajuste manual de saldo';

        if ($distributorId <= 0) {
            $error = 'Selecione o distribuidor.';
        } elseif ($newBalanceRaw === '' || !is_numeric($newBalanceRaw) || (int) $newBalanceRaw < 0) {
            $error = 'Informe o novo saldo (um número igual ou maior que zero).';
        } else {
            $newBalance = (int) $newBalanceRaw;
            $currentBalance = distributor_stock_balance($pdo, $distributorId);
            $delta = $newBalance - $currentBalance;

            if ($delta === 0) {
                $success = "O saldo já está em $currentBalance. Nada foi alterado.";
            } elseif ($delta > 0) {
                $pdo->prepare(
                    "INSERT INTO stock_movements (type, distributor_id, reason, quantity, created_by)
                     VALUES ('entrada_distribuidora', ?, ?, ?, ?)"
                )->execute([$distributorId, $note, $delta, $user['id']]);
                $success = "Saldo ajustado de $currentBalance para $newBalance.";
            } else {
                $pdo->prepare(
                    "INSERT INTO stock_movements (type, distributor_id, quantity, note, created_by)
                     VALUES ('saida_para_tecnico', ?, ?, ?, ?)"
                )->execute([$distributorId, abs($delta), $note, $user['id']]);
                $success = "Saldo ajustado de $currentBalance para $newBalance.";
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

function movement_label(array $m): string
{
    $note = $m['reason'] ?? $m['note'] ?? '';
    if ($m['type'] === 'entrada_distribuidora') {
        if (str_starts_with((string) $note, 'Transferência')) {
            return 'Transferência (entrada)';
        }
        if (str_starts_with((string) $note, 'Ajuste')) {
            return 'Ajuste (entrada)';
        }
        return 'Entrada';
    }
    if ($m['technician_id']) {
        return 'Saída p/ técnico';
    }
    if (str_starts_with((string) $note, 'Transferência')) {
        return 'Transferência (saída)';
    }
    if (str_starts_with((string) $note, 'Ajuste')) {
        return 'Ajuste (saída)';
    }
    return 'Saída';
}

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
  <p class="hint">Carga de kits novos chegando de fora (fornecedor). Para mover kits entre seus próprios distribuidores, use "Transferência entre distribuidores" abaixo.</p>
  <form method="post" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="entrada">
    <label>Quantidade <input type="number" name="quantity" min="1" required></label>
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
  <h2>Transferência entre distribuidores</h2>
  <p class="hint">Move o saldo de um distribuidor para outro: dá baixa no "De" e credita no "Para" automaticamente.</p>
  <form method="post" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="transferencia">
    <label>De:
      <select name="from_distributor_id" required>
        <option value="">Selecione</option>
        <?php foreach ($distributors as $d): ?>
          <option value="<?= $d['id'] ?>"><?= h($d['name']) ?> (<?= $distributorBalances[$d['id']] ?> un.)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Para:
      <select name="to_distributor_id" required>
        <option value="">Selecione</option>
        <?php foreach ($distributors as $d): ?>
          <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Quantidade <input type="number" name="quantity" min="1" required></label>
    <button type="submit" class="btn-primary">Transferir</button>
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
  <h2>Ajustar saldo manualmente</h2>
  <p class="hint">Use quando o saldo do sistema não bater com a contagem física — informe o saldo correto e o sistema lança a diferença automaticamente.</p>
  <form method="post" class="form-inline" data-confirm="Confirma o ajuste manual de saldo? Isso lança um movimento de acerto no histórico.">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="ajustar_saldo">
    <label>Distribuidor
      <select name="distributor_id" required>
        <option value="">Selecione</option>
        <?php foreach ($distributors as $d): ?>
          <option value="<?= $d['id'] ?>"><?= h($d['name']) ?> (atual: <?= $distributorBalances[$d['id']] ?> un.)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Novo saldo <input type="number" name="new_balance" min="0" required></label>
    <label>Motivo <span class="optional">(opcional)</span> <input type="text" name="note" maxlength="255" placeholder="Ex: contagem física de estoque"></label>
    <button type="submit" class="btn-primary">Ajustar saldo</button>
  </form>
</section>

<section class="panel">
  <h2>Histórico de movimentos</h2>
  <table class="data-table">
    <thead><tr><th>Data</th><th>Tipo</th><th>Distribuidor</th><th>Motivo/Nota</th><th>Técnico</th><th>Quantidade</th><th>Registrado por</th></tr></thead>
    <tbody>
      <?php foreach ($movements as $m): ?>
        <tr>
          <td><?= h(date('d/m/Y H:i', strtotime($m['created_at']))) ?></td>
          <td><?= h(movement_label($m)) ?></td>
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
