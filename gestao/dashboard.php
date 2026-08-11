<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$user = require_login();
$pdo = gestao_db();

$allowedPeriods = ['diario', '7dias', 'mensal', 'total'];
$period = $_GET['periodo'] ?? '7dias';
if (!in_array($period, $allowedPeriods, true)) {
    $period = '7dias';
}

[$start, $end] = period_range($period);

$where = ["status = 'pago'"];
$params = [];
if ($start) {
    $where[] = 'paid_date >= ?';
    $params[] = $start->format('Y-m-d');
}
if ($end) {
    $where[] = 'paid_date <= ?';
    $params[] = $end->format('Y-m-d');
}
$whereSql = implode(' AND ', $where);

$totalsStmt = $pdo->prepare(
    "SELECT type, COALESCE(SUM(amount), 0) AS total FROM finance_entries WHERE $whereSql GROUP BY type"
);
$totalsStmt->execute($params);
$totals = ['receita' => 0.0, 'despesa' => 0.0];
foreach ($totalsStmt->fetchAll() as $row) {
    $totals[$row['type']] = (float) $row['total'];
}
$faturamento = $totals['receita'];
$custos = $totals['despesa'];
$lucro = $faturamento - $custos;

$groupExpr = $period === 'total' ? "DATE_FORMAT(paid_date, '%Y-%m')" : 'paid_date';
$seriesStmt = $pdo->prepare(
    "SELECT $groupExpr AS bucket, type, COALESCE(SUM(amount), 0) AS total
     FROM finance_entries WHERE $whereSql GROUP BY bucket, type ORDER BY bucket"
);
$seriesStmt->execute($params);

$series = [];
foreach ($seriesStmt->fetchAll() as $row) {
    $bucket = $row['bucket'];
    $series[$bucket] ??= ['receita' => 0.0, 'despesa' => 0.0];
    $series[$bucket][$row['type']] = (float) $row['total'];
}
ksort($series);

$labels = array_keys($series);
$receitaSeries = array_map(fn($v) => $v['receita'], $series);
$despesaSeries = array_map(fn($v) => $v['despesa'], $series);

$stockBalance = distributor_stock_balance($pdo);

$periodLabels = ['diario' => 'Hoje', '7dias' => 'Últimos 7 dias', 'mensal' => 'Este mês', 'total' => 'Total (tudo)'];

$pageTitle = 'Dashboard';
$activePage = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<?php if ($stockBalance < 300): ?>
<p class="alert alert-warning">Atenção: saldo de kits na distribuidora está em <strong><?= $stockBalance ?></strong> unidades (abaixo de 300). <a href="estoque.php">Ver estoque</a>.</p>
<?php endif; ?>

<nav class="period-tabs">
  <?php foreach ($periodLabels as $key => $label): ?>
    <a href="dashboard.php?periodo=<?= $key ?>" class="<?= $period === $key ? 'active' : '' ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>

<section class="cards">
  <div class="card">
    <p class="card-label">Faturamento total (líquido)</p>
    <p class="card-value"><?= money($faturamento) ?></p>
  </div>
  <div class="card">
    <p class="card-label">Custos</p>
    <p class="card-value"><?= money($custos) ?></p>
  </div>
  <div class="card <?= $lucro >= 0 ? 'card-positive' : 'card-negative' ?>">
    <p class="card-label">Lucro líquido</p>
    <p class="card-value"><?= money($lucro) ?></p>
  </div>
</section>

<section class="panel">
  <h2>Faturamento x Custos</h2>
  <canvas id="dashboardChart" height="90"></canvas>
</section>

<script src="assets/chart.min.js"></script>
<script>
new Chart(document.getElementById('dashboardChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($labels) ?>,
    datasets: [
      { label: 'Faturamento', data: <?= json_encode(array_values($receitaSeries)) ?>, backgroundColor: '#2f7d4f' },
      { label: 'Custos', data: <?= json_encode(array_values($despesaSeries)) ?>, backgroundColor: '#b3452f' }
    ]
  },
  options: { responsive: true, scales: { y: { beginAtZero: true } } }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
