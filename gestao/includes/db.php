<?php

function gestao_config(): array
{
    $configFile = __DIR__ . '/../config.php';
    if (!file_exists($configFile)) {
        http_response_code(500);
        die('Configuração ausente: copie gestao/config.example.php para gestao/config.php e preencha os dados do banco.');
    }
    return require $configFile;
}

function gestao_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = gestao_config();
    $host = $config['db_host'];
    $port = $config['db_port'] ?? 3306;
    if (str_contains($host, ':')) {
        [$host, $port] = explode(':', $host, 2);
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $config['db_name']);

    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
