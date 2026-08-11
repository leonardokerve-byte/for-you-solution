<?php

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function money(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(400);
        die('Sessão expirada ou requisição inválida. Volte e tente novamente.');
    }
}

/**
 * Saldo atual de kits de um distribuidor específico: entradas - saídas para técnicos.
 */
function distributor_stock_balance(PDO $pdo, int $distributorId): int
{
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN type = 'entrada_distribuidora' THEN quantity ELSE 0 END), 0)
            - COALESCE(SUM(CASE WHEN type = 'saida_para_tecnico' THEN quantity ELSE 0 END), 0) AS balance
         FROM stock_movements WHERE distributor_id = ?"
    );
    $stmt->execute([$distributorId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Saldo de kits com um técnico: o que recebeu da distribuidora menos o que já foi baixado por OS executadas.
 */
function technician_stock_balance(PDO $pdo, int $technicianId): int
{
    $received = $pdo->prepare(
        "SELECT COALESCE(SUM(quantity), 0) FROM stock_movements
         WHERE type = 'saida_para_tecnico' AND technician_id = ?"
    );
    $received->execute([$technicianId]);

    $consumed = $pdo->prepare('SELECT COUNT(*) FROM work_orders WHERE technician_id = ?');
    $consumed->execute([$technicianId]);

    return (int) $received->fetchColumn() - (int) $consumed->fetchColumn();
}

/**
 * Intervalo de datas [inicio, fim] para os filtros de período do dashboard/execuções.
 */
function period_range(string $period): array
{
    $today = new DateTimeImmutable('today');

    switch ($period) {
        case 'diario':
            return [$today, $today];
        case '7dias':
            return [$today->modify('-6 days'), $today];
        case 'mensal':
            return [$today->modify('first day of this month'), $today];
        case 'total':
        default:
            return [null, null];
    }
}

function calculate_tax(float $grossAmount, float $taxPercent): array
{
    $taxAmount = round($grossAmount * ($taxPercent / 100), 2);
    $netAmount = round($grossAmount - $taxAmount, 2);
    return [$taxAmount, $netAmount];
}
