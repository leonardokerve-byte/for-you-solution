<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$pdo = gestao_db();

$error = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $description = trim($_POST['description'] ?? '');
    $grossAmount = (float) str_replace(',', '.', $_POST['gross_amount'] ?? '0');
    $taxPercent = (float) str_replace(',', '.', $_POST['tax_percent'] ?? '15');

    if ($description === '' || $grossAmount <= 0) {
        $error = 'Informe a descrição do serviço e um valor bruto válido.';
    } elseif ($taxPercent < 0 || $taxPercent > 100) {
        $error = 'Informe um percentual de imposto entre 0 e 100.';
    } else {
        [$taxAmount, $netAmount] = calculate_tax($grossAmount, $taxPercent);

        $catStmt = $pdo->prepare("SELECT id FROM finance_categories WHERE name = 'Serviço prestado' LIMIT 1");
        $catStmt->execute();
        $categoryId = (int) $catStmt->fetchColumn();

        $pdo->beginTransaction();

        $entryStmt = $pdo->prepare(
            "INSERT INTO finance_entries (type, category_id, description, amount, status, paid_date, created_by)
             VALUES ('receita', ?, ?, ?, 'pago', CURDATE(), ?)"
        );
        $entryStmt->execute([$categoryId, $description, $netAmount, $user['id']]);
        $financeEntryId = (int) $pdo->lastInsertId();

        $taxStmt = $pdo->prepare(
            'INSERT INTO tax_calculations (description, gross_amount, tax_percent, tax_amount, net_amount, finance_entry_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $taxStmt->execute([$description, $grossAmount, $taxPercent, $taxAmount, $netAmount, $financeEntryId, $user['id']]);

        $pdo->commit();

        $result = compact('description', 'grossAmount', 'taxPercent', 'taxAmount', 'netAmount');
    }
}

$history = $pdo->query(
    'SELECT tc.*, u.name AS created_by_name FROM tax_calculations tc
     LEFT JOIN users u ON u.id = tc.created_by
     ORDER BY tc.created_at DESC LIMIT 100'
)->fetchAll();

$pageTitle = 'Impostos';
$activePage = 'impostos';
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<section class="panel">
  <h2>Calcular imposto sobre serviço prestado</h2>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <label>Descrição do serviço
      <input type="text" name="description" required maxlength="255" value="<?= h($_POST['description'] ?? '') ?>">
    </label>
    <label>Valor bruto (R$)
      <input type="text" name="gross_amount" required inputmode="decimal" placeholder="0,00" value="<?= h($_POST['gross_amount'] ?? '') ?>">
    </label>
    <label>Imposto (%)
      <input type="text" name="tax_percent" required inputmode="decimal" value="<?= h($_POST['tax_percent'] ?? '15') ?>">
    </label>
    <button type="submit" class="btn-primary">Calcular e lançar como receita</button>
  </form>

  <?php if ($result): ?>
    <div class="result-box">
      <p>Valor bruto: <strong><?= money($result['grossAmount']) ?></strong></p>
      <p>Imposto (<?= h((string) $result['taxPercent']) ?>%): <strong><?= money($result['taxAmount']) ?></strong></p>
      <p>Valor líquido lançado como receita: <strong><?= money($result['netAmount']) ?></strong></p>
    </div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Histórico de cálculos</h2>
  <table class="data-table">
    <thead><tr><th>Data</th><th>Descrição</th><th>Bruto</th><th>%</th><th>Imposto</th><th>Líquido</th><th>Por</th></tr></thead>
    <tbody>
      <?php foreach ($history as $t): ?>
        <tr>
          <td><?= h(date('d/m/Y', strtotime($t['created_at']))) ?></td>
          <td><?= h($t['description']) ?></td>
          <td><?= money((float) $t['gross_amount']) ?></td>
          <td><?= h($t['tax_percent']) ?>%</td>
          <td><?= money((float) $t['tax_amount']) ?></td>
          <td><?= money((float) $t['net_amount']) ?></td>
          <td><?= h($t['created_by_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$history): ?><tr><td colspan="7" class="empty">Nenhum cálculo registrado ainda.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
