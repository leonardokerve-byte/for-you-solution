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

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '') ?: null;
        $city = trim($_POST['city'] ?? '') ?: null;

        if ($name === '') {
            $error = 'Informe o nome do técnico.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO technicians (name, phone, city) VALUES (?, ?, ?)');
            $stmt->execute([$name, $phone, $city]);
            $success = 'Técnico cadastrado.';
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE technicians SET active = NOT active WHERE id = ?')->execute([$id]);
        $success = 'Status atualizado.';
    }
}

$technicians = $pdo->query('SELECT * FROM technicians ORDER BY active DESC, name')->fetchAll();

$pageTitle = 'Técnicos';
$activePage = 'tecnicos';
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="alert alert-success"><?= h($success) ?></p><?php endif; ?>

<section class="panel">
  <h2>Novo técnico</h2>
  <form method="post" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label>Nome <input type="text" name="name" required maxlength="150"></label>
    <label>Telefone <span class="optional">(opcional)</span> <input type="text" name="phone" maxlength="30"></label>
    <label>Cidade <span class="optional">(opcional)</span> <input type="text" name="city" maxlength="120"></label>
    <button type="submit" class="btn-primary">Cadastrar</button>
  </form>
</section>

<section class="panel">
  <h2>Técnicos cadastrados</h2>
  <table class="data-table">
    <thead><tr><th>Nome</th><th>Cidade</th><th>Telefone</th><th>Saldo de kits</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($technicians as $tech): ?>
        <tr>
          <td><a href="tecnico_detalhe.php?id=<?= $tech['id'] ?>"><?= h($tech['name']) ?></a></td>
          <td><?= h($tech['city'] ?? '—') ?></td>
          <td><?= h($tech['phone'] ?? '—') ?></td>
          <td><?= technician_stock_balance($pdo, (int) $tech['id']) ?> un.</td>
          <td><span class="tag <?= $tech['active'] ? 'tag-pago' : '' ?>"><?= $tech['active'] ? 'Ativo' : 'Inativo' ?></span></td>
          <td>
            <form method="post" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="id" value="<?= $tech['id'] ?>">
              <button type="submit" class="btn-small"><?= $tech['active'] ? 'Desativar' : 'Reativar' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$technicians): ?><tr><td colspan="6" class="empty">Nenhum técnico cadastrado.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
