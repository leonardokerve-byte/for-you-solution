<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

gestao_boot();

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Preencha e-mail e senha.';
    } else {
        try {
            $pdo = gestao_db();
            if (attempt_login($pdo, $email, $password)) {
                header('Location: dashboard.php');
                exit;
            }
            $error = 'E-mail ou senha inválidos.';
        } catch (Throwable $e) {
            $error = 'Erro ao conectar ao banco de dados.';
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
<title>Entrar · Gestão For You Solution</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
<main class="login-card">
  <h1>For You Solution</h1>
  <p class="eyebrow">Painel de gestão</p>
  <?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <label>E-mail
      <input type="email" name="email" required autofocus value="<?= h($_POST['email'] ?? '') ?>">
    </label>
    <label>Senha
      <input type="password" name="password" required>
    </label>
    <button type="submit" class="btn-primary">Entrar</button>
  </form>
</main>
</body>
</html>
