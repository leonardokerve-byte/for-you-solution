<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/xlsx_reader.php';

$user = require_login();
$pdo = gestao_db();

$error = null;
$success = null;

function resolve_field(array $rowKeys, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        foreach ($rowKeys as $key) {
            if ($key === $candidate || str_contains($key, $candidate)) {
                return $key;
            }
        }
    }
    return null;
}

function parse_flexible_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    // Data como número serial do Excel (célula sem formatação de texto).
    if (is_numeric($raw) && $raw > 20000 && $raw < 60000) {
        $date = (new DateTimeImmutable('1899-12-30'))->modify('+' . (int) $raw . ' days');
        return $date->format('Y-m-d');
    }
    foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $raw);
        if ($date !== false) {
            return $date->format('Y-m-d');
        }
    }
    return null;
}

function read_uploaded_table(string $tmpPath, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return xlsx_read_table($tmpPath);
    }
    if ($ext === 'csv') {
        $content = file_get_contents($tmpPath);
        $delimiter = substr_count($content, ';') > substr_count($content, ',') ? ';' : ',';
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $rows = array_map(fn($line) => str_getcsv($line, $delimiter), $lines);
        $headerRow = array_map('normalize_header', array_shift($rows));
        $table = [];
        foreach ($rows as $row) {
            if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $record = [];
            foreach ($headerRow as $i => $header) {
                $record[$header] = trim((string) ($row[$i] ?? ''));
            }
            $table[] = $record;
        }
        return $table;
    }
    throw new RuntimeException('Formato de arquivo não suportado. Envie um arquivo .xlsx ou .csv.');
}

gestao_boot();

// Fase 1: upload e leitura do arquivo, gera prévia com nomes de técnico não reconhecidos.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    csrf_verify();

    if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Selecione um arquivo válido para enviar.';
    } else {
        try {
            $table = read_uploaded_table($_FILES['file']['tmp_name'], $_FILES['file']['name']);
            if (empty($table)) {
                $error = 'A planilha está vazia ou não foi possível ler as linhas.';
            } else {
                $headerKeys = array_keys($table[0]);
                $colOs = resolve_field($headerKeys, ['n os', 'nro os', 'numero os', 'os']);
                $colDate = resolve_field($headerKeys, ['data finalizacao', 'data']);
                $colTech = resolve_field($headerKeys, ['tecnico']);
                $colCity = resolve_field($headerKeys, ['cidade']);
                $colUf = resolve_field($headerKeys, ['uf']);

                if (!$colOs || !$colTech) {
                    $error = 'Não encontrei as colunas de "Nº OS" e "Técnico" no arquivo. Confira o cabeçalho da planilha.';
                } else {
                    $techStmt = $pdo->query('SELECT id, name FROM technicians');
                    $techByName = [];
                    foreach ($techStmt->fetchAll() as $t) {
                        $techByName[normalize_header($t['name'])] = (int) $t['id'];
                    }

                    $existingStmt = $pdo->query('SELECT os_number FROM work_orders');
                    $existingOs = array_flip(array_column($existingStmt->fetchAll(), 'os_number'));

                    $rows = [];
                    $unresolvedNames = [];
                    $skippedExisting = 0;

                    foreach ($table as $record) {
                        $osNumber = trim($record[$colOs] ?? '');
                        if ($osNumber === '') {
                            continue;
                        }
                        if (isset($existingOs[$osNumber])) {
                            $skippedExisting++;
                            continue;
                        }

                        $techName = trim($record[$colTech] ?? '');
                        $techId = $techByName[normalize_header($techName)] ?? null;
                        if ($techId === null && $techName !== '') {
                            $unresolvedNames[$techName] = true;
                        }

                        $rows[] = [
                            'os_number' => $osNumber,
                            'install_date' => $colDate ? parse_flexible_date($record[$colDate] ?? '') : null,
                            'technician_name' => $techName,
                            'technician_id' => $techId,
                            'city' => $colCity ? trim($record[$colCity] ?? '') : null,
                            'uf' => $colUf ? strtoupper(trim($record[$colUf] ?? '')) : null,
                        ];
                    }

                    if (empty($rows)) {
                        $error = 'Nenhuma linha nova para importar (todas as OS já foram importadas antes, ou o arquivo está vazio).';
                    } else {
                        $_SESSION['pending_import'] = [
                            'filename' => $_FILES['file']['name'],
                            'rows' => $rows,
                            'skipped_existing' => $skippedExisting,
                        ];
                    }
                    if ($skippedExisting > 0 && empty($rows) === false) {
                        $success = "$skippedExisting OS já importadas anteriormente foram ignoradas automaticamente.";
                    }
                }
            }
        } catch (Throwable $e) {
            $error = 'Erro ao ler o arquivo: ' . $e->getMessage();
        }
    }
}

// Fase 2: confirmação — usuário resolve manualmente técnicos não reconhecidos e grava no banco.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    csrf_verify();
    $pending = $_SESSION['pending_import'] ?? null;

    if (!$pending) {
        $error = 'Nenhuma importação pendente. Envie o arquivo novamente.';
    } else {
        $manualMap = $_POST['technician_map'] ?? [];
        $imported = 0;
        $skipped = 0;

        $batchStmt = $pdo->prepare('INSERT INTO import_batches (filename, imported_by, row_count) VALUES (?, ?, 0)');
        $batchStmt->execute([$pending['filename'], $user['id']]);
        $batchId = (int) $pdo->lastInsertId();

        $insert = $pdo->prepare(
            'INSERT INTO work_orders (os_number, install_date, technician_id, city, uf, import_batch_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $checkExists = $pdo->prepare('SELECT 1 FROM work_orders WHERE os_number = ?');

        foreach ($pending['rows'] as $row) {
            $techId = $row['technician_id'];
            if (!$techId && $row['technician_name'] !== '') {
                $mapped = $manualMap[$row['technician_name']] ?? '';
                $techId = $mapped !== '' ? (int) $mapped : null;
            }
            if (!$techId) {
                $skipped++;
                continue;
            }

            $checkExists->execute([$row['os_number']]);
            if ($checkExists->fetch()) {
                $skipped++;
                continue;
            }

            $insert->execute([$row['os_number'], $row['install_date'], $techId, $row['city'], $row['uf'], $batchId]);
            $imported++;
        }

        $pdo->prepare('UPDATE import_batches SET row_count = ?, skipped_count = ? WHERE id = ?')
            ->execute([$imported, $skipped, $batchId]);

        unset($_SESSION['pending_import']);
        $success = "$imported OS importadas com sucesso." . ($skipped > 0 ? " $skipped linha(s) ignorada(s) (técnico não resolvido ou OS duplicada)." : '');
    }
}

$pending = $_SESSION['pending_import'] ?? null;
$technicians = $pdo->query('SELECT id, name FROM technicians ORDER BY name')->fetchAll();

// Filtros de acompanhamento
$filterCity = trim($_GET['cidade'] ?? '');
$filterTech = (int) ($_GET['tecnico'] ?? 0);
$filterFrom = $_GET['de'] ?? '';
$filterTo = $_GET['ate'] ?? '';

$where = [];
$params = [];
if ($filterCity !== '') {
    $where[] = 'wo.city LIKE ?';
    $params[] = '%' . $filterCity . '%';
}
if ($filterTech > 0) {
    $where[] = 'wo.technician_id = ?';
    $params[] = $filterTech;
}
if ($filterFrom !== '') {
    $where[] = 'wo.install_date >= ?';
    $params[] = $filterFrom;
}
if ($filterTo !== '') {
    $where[] = 'wo.install_date <= ?';
    $params[] = $filterTo;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$listStmt = $pdo->prepare(
    "SELECT wo.*, t.name AS technician_name FROM work_orders wo
     JOIN technicians t ON t.id = wo.technician_id
     $whereSql ORDER BY wo.install_date DESC, wo.created_at DESC LIMIT 300"
);
$listStmt->execute($params);
$orders = $listStmt->fetchAll();

$byCityStmt = $pdo->prepare(
    "SELECT COALESCE(wo.city, '—') AS city, COUNT(*) AS total FROM work_orders wo
     JOIN technicians t ON t.id = wo.technician_id
     $whereSql GROUP BY wo.city ORDER BY total DESC"
);
$byCityStmt->execute($params);
$byCity = $byCityStmt->fetchAll();

$byTechStmt = $pdo->prepare(
    "SELECT t.name AS technician_name, COUNT(*) AS total FROM work_orders wo
     JOIN technicians t ON t.id = wo.technician_id
     $whereSql GROUP BY t.name ORDER BY total DESC"
);
$byTechStmt->execute($params);
$byTech = $byTechStmt->fetchAll();

$pageTitle = 'Execuções';
$activePage = 'execucoes';
require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="alert alert-success"><?= h($success) ?></p><?php endif; ?>

<section class="panel">
  <h2>Importar instalações</h2>
  <p class="hint">Colunas esperadas: Nº OS · Data finalização · Técnico · Cidade · UF. Cada linha debita 1 kit do saldo do técnico correspondente.</p>
  <form method="post" enctype="multipart/form-data" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <input type="file" name="file" accept=".xlsx,.csv" required>
    <button type="submit" class="btn-primary">Enviar arquivo</button>
  </form>
</section>

<?php if ($pending): ?>
<section class="panel">
  <h2>Confirmar importação — <?= h($pending['filename']) ?></h2>
  <p><?= count($pending['rows']) ?> linha(s) prontas para importar.</p>
  <?php
    $unresolved = [];
    foreach ($pending['rows'] as $row) {
        if (!$row['technician_id'] && $row['technician_name'] !== '') {
            $unresolved[$row['technician_name']] = true;
        }
    }
  ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="confirm">
    <?php if ($unresolved): ?>
      <p class="alert alert-warning">Alguns nomes de técnico na planilha não foram encontrados no cadastro. Selecione o técnico correspondente (ou deixe em branco para ignorar essas linhas):</p>
      <table class="data-table">
        <thead><tr><th>Nome na planilha</th><th>Técnico cadastrado</th></tr></thead>
        <tbody>
        <?php foreach (array_keys($unresolved) as $name): ?>
          <tr>
            <td><?= h($name) ?></td>
            <td>
              <select name="technician_map[<?= h($name) ?>]">
                <option value="">Ignorar linhas deste nome</option>
                <?php foreach ($technicians as $tech): ?>
                  <option value="<?= $tech['id'] ?>"><?= h($tech['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <button type="submit" class="btn-primary">Confirmar e importar</button>
  </form>
</section>
<?php endif; ?>

<section class="panel">
  <h2>Acompanhamento de execuções</h2>
  <form method="get" class="filter-bar">
    <input type="text" name="cidade" placeholder="Cidade" value="<?= h($filterCity) ?>">
    <select name="tecnico">
      <option value="0">Todos os técnicos</option>
      <?php foreach ($technicians as $tech): ?>
        <option value="<?= $tech['id'] ?>" <?= $filterTech === (int) $tech['id'] ? 'selected' : '' ?>><?= h($tech['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label>De <input type="date" name="de" value="<?= h($filterFrom) ?>"></label>
    <label>Até <input type="date" name="ate" value="<?= h($filterTo) ?>"></label>
    <button type="submit" class="btn-small">Filtrar</button>
  </form>

  <div class="split">
    <div>
      <h3>OS por cidade</h3>
      <table class="data-table">
        <thead><tr><th>Cidade</th><th>OS</th></tr></thead>
        <tbody>
          <?php foreach ($byCity as $row): ?>
            <tr><td><?= h($row['city']) ?></td><td><?= $row['total'] ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$byCity): ?><tr><td colspan="2" class="empty">Sem dados.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div>
      <h3>OS por técnico</h3>
      <table class="data-table">
        <thead><tr><th>Técnico</th><th>OS</th></tr></thead>
        <tbody>
          <?php foreach ($byTech as $row): ?>
            <tr><td><?= h($row['technician_name']) ?></td><td><?= $row['total'] ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$byTech): ?><tr><td colspan="2" class="empty">Sem dados.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <h3>Todas as OS (<?= count($orders) ?>)</h3>
  <table class="data-table">
    <thead><tr><th>Nº OS</th><th>Data</th><th>Técnico</th><th>Cidade</th><th>UF</th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><?= h($o['os_number']) ?></td>
          <td><?= h($o['install_date'] ?? '—') ?></td>
          <td><a href="tecnico_detalhe.php?id=<?= $o['technician_id'] ?>"><?= h($o['technician_name']) ?></a></td>
          <td><?= h($o['city'] ?? '—') ?></td>
          <td><?= h($o['uf'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="5" class="empty">Nenhuma OS encontrada.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
