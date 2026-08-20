<?php
/**
 * Leitor mínimo de arquivos .xlsx (primeira aba), usando apenas extensões nativas do PHP
 * (ZipArchive + SimpleXML) — sem depender de bibliotecas externas/Composer.
 * Não suporta fórmulas, estilos ou múltiplas abas: só o necessário para importar planilhas simples.
 */

function xlsx_col_to_index(string $col): int
{
    $col = strtoupper($col);
    $index = 0;
    for ($i = 0; $i < strlen($col); $i++) {
        $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
    }
    return $index - 1;
}

/**
 * @return array<int, array<int, string>> lista de linhas, cada uma indexada por número da coluna (0-based)
 */
function xlsx_read_rows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Não foi possível abrir o arquivo .xlsx.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sharedDom = simplexml_load_string($sharedXml);
        foreach ($sharedDom->si as $si) {
            $sharedStrings[] = trim((string) $si->t ?: implode('', array_map(fn($r) => (string) $r->t, $si->r ?? [])));
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('Planilha (primeira aba) não encontrada dentro do arquivo .xlsx.');
    }

    $dom = simplexml_load_string($sheetXml);
    $rows = [];

    foreach ($dom->sheetData->row as $rowXml) {
        $row = [];
        foreach ($rowXml->c as $cellXml) {
            $ref = (string) $cellXml['r'];
            preg_match('/([A-Z]+)(\d+)/', $ref, $m);
            $colIndex = $ref !== '' ? xlsx_col_to_index($m[1]) : count($row);

            $type = (string) $cellXml['t'];
            $rawValue = (string) $cellXml->v;

            if ($type === 's') {
                $value = $sharedStrings[(int) $rawValue] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = trim((string) $cellXml->is->t);
            } else {
                $value = $rawValue;
            }

            $row[$colIndex] = $value;
        }
        if (!empty($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/**
 * Lê o .xlsx e devolve as linhas como arrays associativos usando a primeira linha como cabeçalho
 * (chave normalizada: minúsculo, sem acento, sem espaço nas pontas).
 *
 * @return array{headers: array<string, string>, rows: array<int, array<string, string>>}
 *   "headers" mapeia chave normalizada => rótulo original da coluna (para exibir no mapeamento).
 */
function xlsx_read_table(string $path): array
{
    $rows = xlsx_read_rows($path);
    if (empty($rows)) {
        return ['headers' => [], 'rows' => []];
    }

    $headerRow = array_shift($rows);
    $headers = [];
    $colToKey = [];
    foreach ($headerRow as $colIndex => $label) {
        $key = normalize_header($label);
        $headers[$key] = trim($label);
        $colToKey[$colIndex] = $key;
    }

    $table = [];
    foreach ($rows as $row) {
        $record = [];
        foreach ($colToKey as $colIndex => $key) {
            $record[$key] = trim($row[$colIndex] ?? '');
        }
        $table[] = $record;
    }

    return ['headers' => $headers, 'rows' => $table];
}

function normalize_header(string $label): string
{
    $label = mb_strtolower(trim($label), 'UTF-8');
    $translit = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label;
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $translit));
}
