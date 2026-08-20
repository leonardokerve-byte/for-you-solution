<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/xlsx_reader.php';

$user = require_login();
$pdo = gestao_db();

$error = null;
$success = null;

const MAPPING_FIELDS = [
    'os_number' => ['label' => 'Nº OS', 'required' => true, 'guess' => ['n os', 'nro os', 'numero os', 'os']],
    'install_date' => ['label' => 'Data finalização', 'required' => false, 'guess' => ['data finalizacao', 'data']],
    'technician' => ['label' => 'Técnico', 'required' => true, 'guess' => ['tecnico']],
    'city' => ['label' => 'Cidade', 'required' => false, 'guess' => ['cidade']],
    'uf' => ['label' => 'UF', 'required' => false, 'guess' => ['uf']],
];

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

/**
 * @return array{headers: array<string,string>, rows: array<int, array<string,string>>}
 */
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
        $rawRows = array_map(fn($line) => str_getcsv($line, $delimiter), $lines);
        $headerRow = array_shift($rawRows);

        $headers = [];
        $colToKey = [];
        foreach ($headerRow as $i => $label) {
            $key = normalize_header((string) $label);
            $headers[$key] = trim((string) $label);
            $colToKey[$i] = $key;
        }

        $table = [];
        foreach ($rawRows as $row) {
            if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $record = [];
            foreach ($colToKey as $i => $key) {
                $record[$key] = trim((string) ($row[$i] ?? ''));
            }
            $table[] = $record;
        }
        return ['headers' => $headers, 'rows' => $table];
    }
    throw new RuntimeException('Formato de arquivo não suportado. Envie um arquivo .xlsx ou .csv.');
}

function saved_column_mappings(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT field_key, source_header FROM import_column_mappings');
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[$row['field_key']] = $row['source_header'];
    }
    return $map;
}

/**
 * Monta as linhas prontas para confirmação a partir da tabela lida e do mapeamento de colunas escolhido.
 *
 * @param array<string, string|null> $cols field_key => chave de coluna normalizada (ou null)
 */
function build_pending_rows(PDO $pdo, array $tableRows, array $cols): array
{
    $techStmt = $pdo->query('SELECT id, name FROM technicians');
    $techByName = [];
    foreach ($techStmt->fetchAll() as $t) {
        $techByName[normalize_header($t['name'])] = (int) $t['id'];
    }

    $existingStmt = $pdo->query('SELECT os_number FROM work_orders');
    $existingOs = array_flip(array_column($existingStmt->fetchAll(), 'os_number'));

    $rows = [];
    $skippedExisting = 0;

    foreach ($tableRows as $record) {
        $osNumber = trim($record[$cols['os_number']] ?? '');
        if ($osNumber === '') {
            continue;
        }
        if (isset($existingOs[$osNumber])) {
            $skippedExisting++;
            continue;
        }

        $techName = trim($record[$cols['technician']] ?? '');
        $techId = $techByName[normalize_header($techName)] ?? null;

        $rows[] = [
            'os_number' => $osNumber,
            'install_date' => $cols['install_date'] ? parse_flexible_date($record[$cols['install_date']] ?? '') : null,
            'technician_name' => $techName,
            'technician_id' => $techId,
            'city' => $cols['city'] ? trim($record[$cols['city']] ?? '') : null,
            'uf' => $cols['uf'] ? strtoupper(trim($record[$cols['uf']] ?? '')) : null,
        ];
    }

    return ['rows' => $rows, 'skipped_existing' => $skippedExisting];
}

gestao_boot();

// Fase 1: upload do arquivo — tenta usar o mapeamento de colunas já salvo; se não bater, pede o de-para.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    csrf_verify();

    if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Selecione um arquivo válido para enviar.';
    } else {
        try {
            $result = read_uploaded_table($_FILES['file']['tmp_name'], $_FILES['file']['name']);
            if (empty($result['rows'])) {
                $error = 'A planilha está vazia ou não foi possível ler as linhas.';
            } else {
                $headerKeys = array_keys($result['headers']);
                $savedMap = saved_column_mappings($pdo);

                $cols = [];
                $allRequiredResolved = true;
                foreach (MAPPING_FIELDS as $field => $info) {
                    $saved = $savedMap[$field] ?? null;
                    $cols[$field] = ($saved !== null && in_array($saved, $headerKeys, true)) ? $saved : null;
                    if ($info['required'] && $cols[$field] === null) {
                        $allRequiredResolved = false;
                    }
                }

                if ($allRequiredResolved) {
                    $built = build_pending_rows($pdo, $result['rows'], $cols);
                    if (empty($built['rows'])) {
                        $error = 'Nenhuma linha nova para importar (todas as OS já foram importadas antes, ou o arquivo está vazio).';
                    } else {
                        $_SESSION['pending_import'] = [
                            'filename' => $_FILES['file']['name'],
                            'rows' => $built['rows'],
                            'skipped_existing' => $built['skipped_existing'],
                        ];
                        if ($built['skipped_existing'] > 0) {
                            $success = "{$built['skipped_existing']} OS já importadas anteriormente foram ignoradas automaticamente.";
                        }
                    }
                } else {
                    $_SESSION['pending_mapping'] = [
                        'filename' => $_FILES['file']['name'],
                        'headers' => $result['headers'],
                        'rows' => $result['rows'],
                    ];
                }
            }
        } catch (Throwable $e) {
            $error = 'Erro ao ler o arquivo: ' . $e->getMessage();
        }
    }
}

// Fase 1b: usuário confirma o de-para de colunas, mapeamento é salvo e as linhas são montadas.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_mapping') {
    csrf_verify();
    $pendingMapping = $_SESSION['pending_mapping'] ?? null;

    if (!$pendingMapping) {
        $error = 'Nenhum arquivo pendente de mapeamento. Envie o arquivo novamente.';
    } else {
        $chosen = $_POST['mapping'] ?? [];
        $headerKeys = array_keys($pendingMapping['headers']);
        $cols = [];
        $missingRequired = [];

        foreach (MAPPING_FIELDS as $field => $info) {
            $value = trim($chosen[$field] ?? '');
            $cols[$field] = ($value !== '' && in_array($value, $headerKeys, true)) ? $value : null;
            if ($info['required'] && $cols[$field] === null) {
                $missingRequired[] = $info['label'];
            }
        }

        if ($missingRequired) {
            $error = 'Selecione a coluna correspondente para: ' . implode(', ', $missingRequired) . '.';
        } else {
            $upsert = $pdo->prepare(
                'INSERT INTO import_column_mappings (field_key, source_header) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE source_header = VALUES(source_header)'
            );
            $delete = $pdo->prepare('DELETE FROM import_column_mappings WHERE field_key = ?');
            foreach ($cols as $field => $key) {
                if ($key !== null) {
                    $upsert->execute([$field, $key]);
                } else {
                    $delete->execute([$field]);
                }
            }

            $built = build_pending_rows($pdo, $pendingMapping['rows'], $cols);
            unset($_SESSION['pending_mapping']);

            if (empty($built['rows'])) {
                $error = 'Nenhuma linha nova para importar (todas as OS já foram importadas antes, ou o arquivo está vazio).';
            } else {
                $_SESSION['pending_import'] = [
                    'filename' => $pendingMapping['filename'],
                    'rows' => $built['rows'],
                    'skipped_existing' => $built['skipped_existing'],
                ];
                $success = 'Mapeamento de colunas salvo. Ele será usado automaticamente nos próximos envios.';
                if ($built['skipped_existing'] > 0) {
                    $success .= " {$built['skipped_existing']} OS já importadas anteriormente foram ignoradas.";
                }
            }
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

// Fase mapeamento: se o usuário cancelar, limpa o estado pendente.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_mapping') {
    csrf_verify();
    unset($_SESSION['pending_mapping']);
}

$pendingMapping = $_SESSION['pending_mapping'] ?? null;
$pending = $_SESSION['pending_import'] ?? null;
$technicians = $pdo->query('SELECT id, name FROM technicians ORDER BY name')->fetchAll();
$savedMap = saved_column_mappings($pdo);

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

<?php if (!$pendingMapping): ?>
<section class="panel">
  <h2>Importar instalações</h2>
  <p class="hint">Colunas esperadas: Nº OS · Data finalização · Técnico · Cidade · UF. Cada linha debita 1 kit do saldo do técnico correspondente.</p>
  <form method="post" enctype="multipart/form-data" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <input type="file" name="file" accept=".xlsx,.csv" required>
    <button type="submit" class="btn-primary">Enviar arquivo</button>
  </form>
  <p class="hint">
    Mapeamento de colunas salvo:
    <?php foreach (MAPPING_FIELDS as $field => $info): ?>
      <?= h($info['label']) ?> → <strong><?= h($savedMap[$field] ?? 'não definido') ?></strong><?= $field !== array_key_last(MAPPING_FIELDS) ? ' · ' : '' ?>
    <?php endforeach; ?>
  </p>
</section>
<?php endif; ?>

<?php if ($pendingMapping): ?>
<section class="panel">
  <h2>Mapeamento de colunas — <?= h($pendingMapping['filename']) ?></h2>
  <p class="alert alert-warning">Não reconheci algumas colunas dessa planilha automaticamente. Indique abaixo qual coluna corresponde a cada campo — isso só precisa ser feito uma vez; o mapeamento fica salvo para os próximos envios.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_mapping">
    <table class="data-table">
      <thead><tr><th>Campo do sistema</th><th>Obrigatório</th><th>Coluna na sua planilha</th></tr></thead>
      <tbody>
        <?php foreach (MAPPING_FIELDS as $field => $info): ?>
          <?php
            $guess = $savedMap[$field] ?? resolve_field(array_keys($pendingMapping['headers']), $info['guess']);
          ?>
          <tr>
            <td><?= h($info['label']) ?></td>
            <td><span class="tag <?= $info['required'] ? 'tag-despesa' : '' ?>"><?= $info['required'] ? 'Obrigatório' : 'Opcional' ?></span></td>
            <td>
              <select name="mapping[<?= h($field) ?>]">
                <option value="">— Não mapear —</option>
                <?php foreach ($pendingMapping['headers'] as $key => $label): ?>
                  <option value="<?= h($key) ?>" <?= $guess === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="form-inline" style="margin-top:1rem">
      <button type="submit" class="btn-primary">Salvar mapeamento e continuar</button>
    </div>
  </form>
  <form method="post" class="inline-form" style="margin-top:0.5rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cancel_mapping">
    <button type="submit" class="btn-small">Cancelar</button>
  </form>
</section>
<?php endif; ?>

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
