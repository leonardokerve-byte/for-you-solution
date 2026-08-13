<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$pdo = gestao_db();

$error = null;
$result = null;

function faturamento_category_id(PDO $pdo, string $name): int
{
    $stmt = $pdo->prepare('SELECT id FROM finance_categories WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    return (int) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM tax_calculations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM tax_calculations WHERE id = ?')->execute([$id]);
            if ($row['finance_entry_id']) {
                $pdo->prepare('DELETE FROM finance_entries WHERE id = ?')->execute([$row['finance_entry_id']]);
            }
            if ($row['labor_finance_entry_id']) {
                $pdo->prepare('DELETE FROM finance_entries WHERE id = ?')->execute([$row['labor_finance_entry_id']]);
            }
            $pdo->commit();
        }
        header('Location: faturamento.php');
        exit;
    }

    $description = trim($_POST['description'] ?? '');
    $grossAmount = (float) str_replace(',', '.', $_POST['gross_amount'] ?? '0');
    $taxPercent = (float) str_replace(',', '.', $_POST['tax_percent'] ?? '15');
    $laborCostRaw = trim($_POST['labor_cost'] ?? '');
    $laborCost = $laborCostRaw === '' ? 0.0 : (float) str_replace(',', '.', $laborCostRaw);
    $laborTechnicianId = (int) ($_POST['labor_technician_id'] ?? 0);

    if ($description === '' || $grossAmount <= 0) {
        $error = 'Informe a descrição do serviço e um valor bruto válido.';
    } elseif ($taxPercent < 0 || $taxPercent > 100) {
        $error = 'Informe um percentual de imposto entre 0 e 100.';
    } elseif ($laborCost > 0 && $laborTechnicianId <= 0) {
        $error = 'Selecione o técnico que vai receber o custo de mão de obra.';
    } else {
        [$taxAmount, $netAmount] = calculate_tax($grossAmount, $taxPercent);
        $receitaCategoryId = faturamento_category_id($pdo, 'Serviço prestado');
        $laborCategoryId = faturamento_category_id($pdo, 'Mão de obra');

        $pdo->beginTransaction();

        if ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $existingStmt = $pdo->prepare('SELECT * FROM tax_calculations WHERE id = ?');
            $existingStmt->execute([$id]);
            $existing = $existingStmt->fetch();

            $entryStmt = $pdo->prepare(
                "UPDATE finance_entries SET description = ?, amount = ? WHERE id = ?"
            );
            $entryStmt->execute([$description, $netAmount, $existing['finance_entry_id']]);
            $financeEntryId = $existing['finance_entry_id'];

            $laborFinanceEntryId = $existing['labor_finance_entry_id'];
            if ($laborCost > 0) {
                $techStmt = $pdo->prepare('SELECT name FROM technicians WHERE id = ?');
                $techStmt->execute([$laborTechnicianId]);
                $techName = $techStmt->fetchColumn() ?: '';
                $laborDescription = 'Mão de obra — ' . $techName . ' (' . $description . ')';

                if ($laborFinanceEntryId) {
                    $pdo->prepare('UPDATE finance_entries SET description = ?, amount = ? WHERE id = ?')
                        ->execute([$laborDescription, $laborCost, $laborFinanceEntryId]);
                } else {
                    $laborStmt = $pdo->prepare(
                        "INSERT INTO finance_entries (type, category_id, description, amount, status, paid_date, created_by)
                         VALUES ('despesa', ?, ?, ?, 'pago', CURDATE(), ?)"
                    );
                    $laborStmt->execute([$laborCategoryId, $laborDescription, $laborCost, $user['id']]);
                    $laborFinanceEntryId = (int) $pdo->lastInsertId();
                }
            } elseif ($laborFinanceEntryId) {
                $pdo->prepare('DELETE FROM finance_entries WHERE id = ?')->execute([$laborFinanceEntryId]);
                $laborFinanceEntryId = null;
            }

            $updateStmt = $pdo->prepare(
                'UPDATE tax_calculations
                 SET description = ?, gross_amount = ?, tax_percent = ?, tax_amount = ?, net_amount = ?,
                     labor_cost = ?, labor_technician_id = ?, labor_finance_entry_id = ?
                 WHERE id = ?'
            );
            $updateStmt->execute([
                $description, $grossAmount, $taxPercent, $taxAmount, $netAmount,
                $laborCost > 0 ? $laborCost : null, $laborCost > 0 ? $laborTechnicianId : null, $laborFinanceEntryId,
                $id,
            ]);

            $pdo->commit();
            header('Location: faturamento.php');
            exit;
        }

        $entryStmt = $pdo->prepare(
            "INSERT INTO finance_entries (type, category_id, description, amount, status, paid_date, created_by)
             VALUES ('receita', ?, ?, ?, 'pago', CURDATE(), ?)"
        );
        $entryStmt->execute([$receitaCategoryId, $description, $netAmount, $user['id']]);
        $financeEntryId = (int) $pdo->lastInsertId();

        $laborFinanceEntryId = null;
        if ($laborCost > 0) {
            $techStmt = $pdo->prepare('SELECT name FROM technicians WHERE id = ?');
            $techStmt->execute([$laborTechnicianId]);
            $techName = $techStmt->fetchColumn() ?: '';
            $laborDescription = 'Mão de obra — ' . $techName . ' (' . $description . ')';

            $laborStmt = $pdo->prepare(
                "INSERT INTO finance_entries (type, category_id, description, amount, status, paid_date, created_by)
                 VALUES ('despesa', ?, ?, ?, 'pago', CURDATE(), ?)"
            );
            $laborStmt->execute([$laborCategoryId, $laborDescription, $laborCost, $user['id']]);
            $laborFinanceEntryId = (int) $pdo->lastInsertId();
        }

        $taxStmt = $pdo->prepare(
            'INSERT INTO tax_calculations
                (description, gross_amount, tax_percent, tax_amount, net_amount, labor_cost, labor_technician_id, finance_entry_id, labor_finance_entry_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $taxStmt->execute([
            $description, $grossAmount, $taxPercent, $taxAmount, $netAmount,
            $laborCost > 0 ? $laborCost : null, $laborCost > 0 ? $laborTechnicianId : null,
            $financeEntryId, $laborFinanceEntryId, $user['id'],
        ]);

        $pdo->commit();

        $result = compact('description', 'grossAmount', 'taxPercent', 'taxAmount', 'netAmount', 'laborCost');
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM tax_calculations WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$technicians = $pdo->query('SELECT id, name FROM technicians WHERE active = 1 ORDER BY name')->fetchAll();

$history = $pdo->query(
    'SELECT tc.*, u.name AS created_by_name, lt.name AS labor_technician_name
     FROM tax_calculations tc
     LEFT JOIN users u ON u.id = tc.created_by
     LEFT JOIN technicians lt ON lt.id = tc.labor_technician_id
     ORDER BY tc.created_at DESC LIMIT 100'
)->fetchAll();

$pageTitle = 'Faturamento';
$activePage = 'faturamento';
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<section class="panel">
  <h2><?= $editing ? 'Editar lançamento' : 'Calcular faturamento de serviço prestado' ?></h2>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
    <label>Descrição do serviço
      <input type="text" name="description" required maxlength="255" value="<?= h($editing['description'] ?? ($_POST['description'] ?? '')) ?>">
    </label>
    <label>Valor bruto (R$)
      <input type="text" name="gross_amount" required inputmode="decimal" placeholder="0,00" value="<?= h($editing['gross_amount'] ?? ($_POST['gross_amount'] ?? '')) ?>">
    </label>
    <label>Imposto (%)
      <input type="text" name="tax_percent" required inputmode="decimal" value="<?= h($editing['tax_percent'] ?? ($_POST['tax_percent'] ?? '15')) ?>">
    </label>
    <label>Custo mão de obra (R$) <span class="optional">(opcional)</span>
      <input type="text" name="labor_cost" inputmode="decimal" placeholder="0,00" value="<?= h($editing['labor_cost'] ?? ($_POST['labor_cost'] ?? '')) ?>">
    </label>
    <label>Técnico (mão de obra)
      <select name="labor_technician_id">
        <option value="">— Nenhum —</option>
        <?php foreach ($technicians as $tech): ?>
          <option value="<?= $tech['id'] ?>" <?= isset($editing['labor_technician_id']) && (int) $editing['labor_technician_id'] === (int) $tech['id'] ? 'selected' : '' ?>><?= h($tech['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="btn-primary"><?= $editing ? 'Salvar alterações' : 'Calcular e lançar' ?></button>
    <?php if ($editing): ?><a href="faturamento.php" class="btn-small">Cancelar edição</a><?php endif; ?>
  </form>
  <p class="hint">O custo de mão de obra é lançado automaticamente como despesa no Financeiro (categoria "Mão de obra") e já entra no cálculo de Custos/Lucro líquido do Dashboard.</p>

  <?php if ($result): ?>
    <div class="result-box">
      <p>Valor bruto: <strong><?= money($result['grossAmount']) ?></strong></p>
      <p>Imposto (<?= h((string) $result['taxPercent']) ?>%): <strong><?= money($result['taxAmount']) ?></strong></p>
      <p>Valor líquido lançado como receita: <strong><?= money($result['netAmount']) ?></strong></p>
      <?php if ($result['laborCost'] > 0): ?>
        <p>Custo de mão de obra lançado como despesa: <strong><?= money($result['laborCost']) ?></strong></p>
        <p>Resultado líquido desta operação (receita − mão de obra): <strong><?= money($result['netAmount'] - $result['laborCost']) ?></strong></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Histórico</h2>
  <table class="data-table">
    <thead><tr><th>Data</th><th>Descrição</th><th>Bruto</th><th>%</th><th>Imposto</th><th>Líquido</th><th>Mão de obra</th><th>Resultado</th><th>Por</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($history as $t): ?>
        <?php $resultado = (float) $t['net_amount'] - (float) ($t['labor_cost'] ?? 0); ?>
        <tr>
          <td><?= h(date('d/m/Y', strtotime($t['created_at']))) ?></td>
          <td><?= h($t['description']) ?></td>
          <td><?= money((float) $t['gross_amount']) ?></td>
          <td><?= h($t['tax_percent']) ?>%</td>
          <td><?= money((float) $t['tax_amount']) ?></td>
          <td><?= money((float) $t['net_amount']) ?></td>
          <td><?= $t['labor_cost'] ? money((float) $t['labor_cost']) . ' — ' . h($t['labor_technician_name']) : '—' ?></td>
          <td><strong><?= money($resultado) ?></strong></td>
          <td><?= h($t['created_by_name'] ?? '—') ?></td>
          <td>
            <a href="faturamento.php?edit=<?= $t['id'] ?>" class="btn-small">Editar</a>
            <form method="post" class="inline-form" data-confirm="Cancelar este lançamento? Isso remove também a receita (e o custo de mão de obra, se houver) lançados no financeiro.">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $t['id'] ?>">
              <button type="submit" class="btn-small">Cancelar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$history): ?><tr><td colspan="10" class="empty">Nenhum lançamento registrado ainda.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
