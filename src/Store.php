<?php
declare(strict_types=1);
namespace Taller;

final class Store
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('No se pudo crear la carpeta privada de datos.');
        }
    }

    private function locked(int $mode, callable $action): mixed
    {
        $lock = fopen($this->directory . '/store.lock', 'c');
        if ($lock === false) throw new \RuntimeException('No se pudo abrir el bloqueo.');
        try {
            if (!flock($lock, $mode)) throw new \RuntimeException('No se pudo bloquear el almacén.');
            return $action();
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function load(): array
    {
        $path = $this->directory . '/datos.json';
        if (!is_file($path)) return ['version' => 1, 'records' => []];
        $size = filesize($path);
        if ($size === false || $size > 5_000_000) throw new \RuntimeException('El almacén supera 5 MB; exporta y archiva datos.');
        $text = file_get_contents($path);
        if ($text === false) throw new \RuntimeException('Error de lectura.');
        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['records'] ?? null)) {
            throw new \RuntimeException('Almacén inválido; se conserva para recuperación manual.');
        }
        foreach ($data['records'] as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !in_array($row['type'] ?? '', ['tarea','gasto','inventario'], true)
                || !is_string($row['date'] ?? null) || !is_string($row['title'] ?? null) || !is_string($row['category'] ?? null) || !is_bool($row['archived'] ?? null)
                || !is_int($row['cents'] ?? null) || !is_int($row['quantity'] ?? null) || !is_bool($row['done'] ?? null)) {
                throw new \RuntimeException('Registro inválido. No se reemplazará el almacén.');
            }
        }
        return $data;
    }

    public function read(): array { return $this->locked(LOCK_SH, fn() => $this->load()); }

    public function update(callable $change): array
    {
        return $this->locked(LOCK_EX, function () use ($change): array {
            $data = $this->load();
            $data = $change($data);
            if (count($data['records']) > 10000) throw new \RuntimeException('Límite de 10000 registros.');
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($json) > 5_000_000) throw new \RuntimeException('Límite de 5 MB de datos.');
            $temp = tempnam($this->directory, '.nuevo-');
            if ($temp === false) throw new \RuntimeException('No se pudo preparar el archivo.');
            try {
                if (file_put_contents($temp, $json) !== strlen($json)) throw new \RuntimeException('Escritura incompleta.');
                if (!rename($temp, $this->directory . '/datos.json')) throw new \RuntimeException('No se pudo confirmar el guardado.');
            } finally { if (is_file($temp)) unlink($temp); }
            return $data;
        });
    }
}
