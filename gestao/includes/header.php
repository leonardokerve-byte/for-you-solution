<?php
/** @var string $pageTitle */
/** @var string $activePage */
$user = current_user();
$pageTitle = $pageTitle ?? 'Gestão';
$activePage = $activePage ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= h($pageTitle) ?> · Gestão For You Solution</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-brand">For You Solution <span>· Gestão</span></div>
  <?php if ($user): ?>
  <nav class="topnav">
    <a href="dashboard.php" class="<?= $activePage === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
    <a href="financeiro.php" class="<?= $activePage === 'financeiro' ? 'active' : '' ?>">Financeiro</a>
    <a href="estoque.php" class="<?= $activePage === 'estoque' ? 'active' : '' ?>">Estoque</a>
    <a href="tecnicos.php" class="<?= $activePage === 'tecnicos' ? 'active' : '' ?>">Técnicos</a>
    <a href="execucoes.php" class="<?= $activePage === 'execucoes' ? 'active' : '' ?>">Execuções</a>
    <a href="faturamento.php" class="<?= $activePage === 'faturamento' ? 'active' : '' ?>">Faturamento</a>
  </nav>
  <div class="topbar-user">
    <span><?= h($user['name']) ?></span>
    <a href="backup.php" class="btn-ghost">Backup</a>
    <a href="logout.php" class="btn-ghost">Sair</a>
  </div>
  <?php endif; ?>
</header>
<main class="wrap">
<h1><?= h($pageTitle) ?></h1>
