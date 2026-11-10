<?php
declare(strict_types=1);
namespace Taller;

function text(string $value, int $maximum): string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > $maximum || preg_match('//u', $value) !== 1) {
        throw new \InvalidArgumentException('Escribe un texto válido y más breve.');
    }
    return $value;
}
function cents(string $value): int
{
    if (!preg_match('/^([0-9]{1,8})(?:[.,]([0-9]{1,2}))?$/D', trim($value), $match)) {
        throw new \InvalidArgumentException('Usa un importe no negativo con hasta dos decimales.');
    }
    return (int)$match[1] * 100 + (int)str_pad($match[2] ?? '', 2, '0');
}
function money(int $cents): string
{
    return number_format(intdiv($cents, 100), 0, ',', '.') . ',' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT) . ' €';
}
function record(array $input): array
{
    $type = $input['type'] ?? '';
    if (!in_array($type, ['tarea','gasto','inventario'], true)) throw new \InvalidArgumentException('Tipo incorrecto.');
    $date = $input['date'] ?? date('Y-m-d');
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new \InvalidArgumentException('Fecha incorrecta.');
    $quantity = $type === 'inventario' ? filter_var($input['quantity'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>1000000]]) : 0;
    if ($quantity === false) throw new \InvalidArgumentException('Cantidad entre 0 y 1000000.');
    return ['id'=>bin2hex(random_bytes(12)), 'type'=>$type, 'title'=>text($input['title'] ?? '', 240),
        'category'=>text($input['category'] ?? '', 100), 'date'=>$date, 'quantity'=>$quantity,
        'cents'=>$type === 'gasto' ? cents($input['amount'] ?? '') : 0,
        'done'=>false, 'archived'=>false];
}
function apply(array $data, array $input): array
{
    $action = $input['action'] ?? '';
    if ($action === 'add') { $data['records'][] = record($input); return $data; }
    if (!in_array($action, ['done','archive'], true)) throw new \InvalidArgumentException('Acción incorrecta.');
    foreach ($data['records'] as &$row) {
        if ($row['id'] === ($input['id'] ?? '')) {
            if ($action === 'done' && $row['type'] !== 'tarea') throw new \InvalidArgumentException('Solo las tareas tienen estado completado.');
            $key = $action === 'done' ? 'done' : 'archived'; $row[$key] = !$row[$key]; return $data;
        }
    }
    throw new \InvalidArgumentException('Registro no encontrado.');
}
function csrf_valid(string $expected, mixed $received): bool
{
    return is_string($received) && $expected !== '' && hash_equals($expected, $received);
}
function csv_cell(string $value): string
{
    // Evita que lectores de hojas de cálculo interpreten texto como fórmulas.
    if (preg_match('/^[\s]*[=+\-@]/u', $value) || str_starts_with($value, "\t") || str_starts_with($value, "\r")) $value = "'" . $value;
    return $value;
}
