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

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '') ?: null;
        $city = trim($_POST['city'] ?? '') ?: null;
        $distributorId = (int) ($_POST['distributor_id'] ?? 0);

        if ($name === '') {
            $error = 'Informe o nome do técnico.';
        } elseif ($distributorId <= 0) {
            $error = 'Selecione o distribuidor do técnico.';
        } elseif ($action === 'create') {
            $stmt = $pdo->prepare('INSERT INTO technicians (name, phone, city, distributor_id) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $phone, $city, $distributorId]);
            $success = 'Técnico cadastrado.';
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE technicians SET name = ?, phone = ?, city = ?, distributor_id = ? WHERE id = ?');
            $stmt->execute([$name, $phone, $city, $distributorId, $id]);
            header('Location: tecnicos.php');
            exit;
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE technicians SET active = NOT active WHERE id = ?')->execute([$id]);
        $success = 'Status atualizado.';
    }
}

$distributors = $pdo->query('SELECT id, name FROM distributors WHERE active = 1 ORDER BY name')->fetchAll();

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM technicians WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$technicians = $pdo->query(
    "SELECT t.*, d.name AS distributor_name FROM technicians t
     LEFT JOIN distributors d ON d.id = t.distributor_id
     ORDER BY t.active DESC, t.name"
)->fetchAll();

$pageTitle = 'Técnicos';
$activePage = 'tecnicos';
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="alert alert-success"><?= h($success) ?></p><?php endif; ?>

<section class="panel">
  <h2><?= $editing ? 'Editar técnico' : 'Novo técnico' ?></h2>
  <form method="post" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
    <label>Nome <input type="text" name="name" required maxlength="150" value="<?= h($editing['name'] ?? '') ?>"></label>
    <label>Telefone <span class="optional">(opcional)</span> <input type="text" name="phone" maxlength="30" value="<?= h($editing['phone'] ?? '') ?>"></label>
    <label>Cidade <span class="optional">(opcional)</span> <input type="text" name="city" maxlength="120" value="<?= h($editing['city'] ?? '') ?>"></label>
    <label>Distribuidor
      <select name="distributor_id" required>
        <option value="">Selecione</option>
        <?php foreach ($distributors as $d): ?>
          <option value="<?= $d['id'] ?>" <?= isset($editing['distributor_id']) && (int) $editing['distributor_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="btn-primary"><?= $editing ? 'Salvar alterações' : 'Cadastrar' ?></button>
    <?php if ($editing): ?><a href="tecnicos.php" class="btn-small">Cancelar edição</a><?php endif; ?>
  </form>
</section>

<section class="panel">
  <h2>Técnicos cadastrados</h2>
  <table class="data-table">
    <thead><tr><th>Nome</th><th>Distribuidor</th><th>Cidade</th><th>Telefone</th><th>Saldo de kits</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($technicians as $tech): ?>
        <tr>
          <td><a href="tecnico_detalhe.php?id=<?= $tech['id'] ?>"><?= h($tech['name']) ?></a></td>
          <td><?= h($tech['distributor_name'] ?? '—') ?></td>
          <td><?= h($tech['city'] ?? '—') ?></td>
          <td><?= h($tech['phone'] ?? '—') ?></td>
          <td><?= technician_stock_balance($pdo, (int) $tech['id']) ?> un.</td>
          <td><span class="tag <?= $tech['active'] ? 'tag-pago' : '' ?>"><?= $tech['active'] ? 'Ativo' : 'Inativo' ?></span></td>
          <td>
            <a href="tecnicos.php?edit=<?= $tech['id'] ?>" class="btn-small">Editar</a>
            <form method="post" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="id" value="<?= $tech['id'] ?>">
              <button type="submit" class="btn-small"><?= $tech['active'] ? 'Desativar' : 'Reativar' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$technicians): ?><tr><td colspan="7" class="empty">Nenhum técnico cadastrado.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
