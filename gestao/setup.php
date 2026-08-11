<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

gestao_boot();

$pdo = gestao_db();
$userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

// Depois que já existe pelo menos 1 usuário, só quem já está logado pode cadastrar novas contas.
// IMPORTANTE: depois de cadastrar os 3 sócios, apague ou renomeie este arquivo no servidor.
if ($userCount > 0 && !current_user()) {
    header('Location: login.php');
    exit;
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Preencha todos os campos.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'E-mail inválido.';
    } elseif (strlen($password) < 6) {
        $error = 'A senha precisa ter pelo menos 6 caracteres.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'As senhas não conferem.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Já existe uma conta com esse e-mail.';
        } else {
            $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
            $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $success = 'Conta criada para ' . $name . '.';
            $userCount++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Configuração inicial · Gestão For You Solution</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
<main class="login-card">
  <h1>Configuração inicial</h1>
  <p class="eyebrow">Cadastro dos sócios (<?= $userCount ?> conta<?= $userCount === 1 ? '' : 's' ?> criada<?= $userCount === 1 ? '' : 's' ?>)</p>
  <?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>
  <?php if ($success): ?><p class="alert alert-success"><?= h($success) ?></p><?php endif; ?>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <label>Nome
      <input type="text" name="name" required>
    </label>
    <label>E-mail
      <input type="email" name="email" required>
    </label>
    <label>Senha
      <input type="password" name="password" required minlength="6">
    </label>
    <label>Confirmar senha
      <input type="password" name="password_confirm" required minlength="6">
    </label>
    <button type="submit" class="btn-primary">Criar conta</button>
  </form>
  <?php if ($userCount >= 3): ?>
    <p class="hint">Já existem <?= $userCount ?> contas. Depois de conferir que os 3 sócios conseguem entrar, apague ou renomeie este arquivo (<code>setup.php</code>) no servidor por segurança.</p>
  <?php endif; ?>
  <?php if (current_user()): ?><p><a href="dashboard.php">Voltar ao painel</a></p><?php endif; ?>
</main>
</body>
</html>
