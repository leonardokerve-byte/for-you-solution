<?php
require_once __DIR__ . '/db.php';

function gestao_boot(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    header('X-Robots-Tag: noindex, nofollow', true);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    gestao_boot();
    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function attempt_login(PDO $pdo, string $email, string $password): bool
{
    $stmt = $pdo->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
    ];
    return true;
}

function logout(): void
{
    gestao_boot();
    $_SESSION = [];
    session_destroy();
}
