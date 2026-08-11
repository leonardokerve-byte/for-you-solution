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
        $type = $_POST['type'] === 'receita' ? 'receita' : 'despesa';
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $amount = (float) str_replace(',', '.', $_POST['amount'] ?? '0');
        $dueDate = $_POST['due_date'] ?: null;
        $pixRef = trim($_POST['pix_ref'] ?? '') ?: null;

        if ($description === '' || $amount <= 0 || $categoryId <= 0) {
            $error = 'Preencha descrição, categoria e um valor válido.';
        } elseif ($action === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO finance_entries (type, category_id, description, amount, due_date, pix_ref, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$type, $categoryId, $description, $amount, $dueDate, $pixRef, $user['id']]);
            $success = 'Lançamento criado.';
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare(
                'UPDATE finance_entries SET type = ?, category_id = ?, description = ?, amount = ?, due_date = ?, pix_ref = ? WHERE id = ?'
            );
            $stmt->execute([$type, $categoryId, $description, $amount, $dueDate, $pixRef, $id]);
            header('Location: financeiro.php');
            exit;
        }
    } elseif ($action === 'mark_paid') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE finance_entries SET status = 'pago', paid_date = CURDATE() WHERE id = ?");
        $stmt->execute([$id]);
        $success = 'Lançamento marcado como pago.';
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM finance_entries WHERE id = ?')->execute([$id]);
        header('Location: financeiro.php');
        exit;
    }
}

$categories = $pdo->query('SELECT id, name, kind FROM finance_categories ORDER BY kind, name')->fetchAll();

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM finance_entries WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$filterType = $_GET['tipo'] ?? 'todos';
$filterStatus = $_GET['status'] ?? 'todos';
$filterCategory = (int) ($_GET['categoria'] ?? 0);

$where = [];
$params = [];
if (in_array($filterType, ['receita', 'despesa'], true)) {
    $where[] = 'fe.type = ?';
    $params[] = $filterType;
}
if (in_array($filterStatus, ['pendente', 'pago'], true)) {
    $where[] = 'fe.status = ?';
    $params[] = $filterStatus;
}
if ($filterCategory > 0) {
    $where[] = 'fe.category_id = ?';
    $params[] = $filterCategory;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT fe.*, fc.name AS category_name
     FROM finance_entries fe
     JOIN finance_categories fc ON fc.id = fe.category_id
     $whereSql
     ORDER BY (fe.status = 'pendente') DESC, fe.due_date IS NULL, fe.due_date ASC, fe.created_at DESC
     LIMIT 300"
);
$stmt->execute($params);
$entries = $stmt->fetchAll();

$pageTitle = 'Financeiro';
$activePage = 'financeiro';
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="alert alert-success"><?= h($success) ?></p><?php endif; ?>

<section class="panel">
  <h2><?= $editing ? 'Editar lançamento' : 'Novo lançamento' ?></h2>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
    <label>Tipo
      <select name="type" required>
        <option value="despesa" <?= ($editing['type'] ?? '') === 'despesa' ? 'selected' : '' ?>>Despesa (a pagar)</option>
        <option value="receita" <?= ($editing['type'] ?? '') === 'receita' ? 'selected' : '' ?>>Receita (a receber)</option>
      </select>
    </label>
    <label>Categoria
      <select name="category_id" required>
        <option value="">Selecione</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>" data-kind="<?= h($cat['kind']) ?>" <?= isset($editing['category_id']) && (int) $editing['category_id'] === (int) $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?> (<?= $cat['kind'] === 'receita' ? 'receita' : 'despesa' ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Descrição
      <input type="text" name="description" required maxlength="255" value="<?= h($editing['description'] ?? '') ?>">
    </label>
    <label>Valor (R$)
      <input type="text" name="amount" required inputmode="decimal" placeholder="0,00" value="<?= h($editing['amount'] ?? '') ?>">
    </label>
    <label>Vencimento
      <input type="date" name="due_date" value="<?= h($editing['due_date'] ?? '') ?>">
    </label>
    <label>Referência PIX <span class="optional">(opcional)</span>
      <input type="text" name="pix_ref" maxlength="190" value="<?= h($editing['pix_ref'] ?? '') ?>">
    </label>
    <button type="submit" class="btn-primary"><?= $editing ? 'Salvar alterações' : 'Adicionar' ?></button>
    <?php if ($editing): ?><a href="financeiro.php" class="btn-small">Cancelar edição</a><?php endif; ?>
  </form>
</section>

<section class="panel">
  <h2>Lançamentos</h2>
  <form method="get" class="filter-bar">
    <select name="tipo" onchange="this.form.submit()">
      <option value="todos" <?= $filterType === 'todos' ? 'selected' : '' ?>>Todos os tipos</option>
      <option value="receita" <?= $filterType === 'receita' ? 'selected' : '' ?>>Receita</option>
      <option value="despesa" <?= $filterType === 'despesa' ? 'selected' : '' ?>>Despesa</option>
    </select>
    <select name="status" onchange="this.form.submit()">
      <option value="todos" <?= $filterStatus === 'todos' ? 'selected' : '' ?>>Todos os status</option>
      <option value="pendente" <?= $filterStatus === 'pendente' ? 'selected' : '' ?>>Pendente</option>
      <option value="pago" <?= $filterStatus === 'pago' ? 'selected' : '' ?>>Pago</option>
    </select>
    <select name="categoria" onchange="this.form.submit()">
      <option value="0">Todas as categorias</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= $cat['id'] ?>" <?= $filterCategory === (int) $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <table class="data-table">
    <thead>
      <tr><th>Tipo</th><th>Categoria</th><th>Descrição</th><th>Valor</th><th>Vencimento</th><th>Status</th><th>PIX</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($entries as $entry): ?>
        <tr>
          <td><span class="tag tag-<?= $entry['type'] ?>"><?= $entry['type'] === 'receita' ? 'Receita' : 'Despesa' ?></span></td>
          <td><?= h($entry['category_name']) ?></td>
          <td><?= h($entry['description']) ?></td>
          <td><?= money((float) $entry['amount']) ?></td>
          <td><?= h($entry['due_date'] ?? '—') ?></td>
          <td>
            <?php if ($entry['status'] === 'pago'): ?>
              <span class="tag tag-pago">Pago em <?= h($entry['paid_date']) ?></span>
            <?php else: ?>
              <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="id" value="<?= $entry['id'] ?>">
                <button type="submit" class="btn-small">Marcar como pago</button>
              </form>
            <?php endif; ?>
          </td>
          <td><?= h($entry['pix_ref'] ?? '—') ?></td>
          <td>
            <a href="financeiro.php?edit=<?= $entry['id'] ?>" class="btn-small">Editar</a>
            <form method="post" class="inline-form" data-confirm="Remover este lançamento? Essa ação não pode ser desfeita.">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $entry['id'] ?>">
              <button type="submit" class="btn-small">Remover</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$entries): ?>
        <tr><td colspan="8" class="empty">Nenhum lançamento encontrado.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
