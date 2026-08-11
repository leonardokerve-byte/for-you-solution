<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
$pdo = gestao_db();

$filename = 'backup-gestao-' . date('Y-m-d-His') . '.sql';

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Robots-Tag: noindex, nofollow');

echo "-- Backup do Sistema de Gestão - For You Solution\n";
echo "-- Gerado em " . date('d/m/Y H:i:s') . "\n\n";
echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

echo "-- Estrutura das tabelas --\n\n";
echo file_get_contents(__DIR__ . '/schema.sql');
echo "\n\n-- Dados --\n\n";

// Ordem respeita as dependências de chave estrangeira entre as tabelas.
$tables = [
    'users', 'finance_categories', 'technicians', 'finance_entries',
    'stock_movements', 'import_batches', 'work_orders', 'tax_calculations',
];

foreach ($tables as $table) {
    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll();
    if (empty($rows)) {
        continue;
    }

    $columns = array_keys($rows[0]);
    $columnList = '`' . implode('`, `', $columns) . '`';

    echo "-- Tabela: $table\n";
    foreach ($rows as $row) {
        $values = array_map(function ($value) use ($pdo) {
            return $value === null ? 'NULL' : $pdo->quote((string) $value);
        }, $row);
        echo "INSERT INTO `$table` ($columnList) VALUES (" . implode(', ', $values) . ");\n";
    }
    echo "\n";
}

echo "SET FOREIGN_KEY_CHECKS=1;\n";
