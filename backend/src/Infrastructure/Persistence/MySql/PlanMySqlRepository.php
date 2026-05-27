<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Plan\PlanRepository;
use PDO;

class PlanMySqlRepository implements PlanRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function listar(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM planes_catalogo WHERE activo = 1 ORDER BY modalidad, clases_semana'
        );
        return array_map($this->mapear(...), $stmt->fetchAll());
    }

    public function crear(array $datos): array
    {
        // Verificar si ya existe esa combinación
        $check = $this->pdo->prepare(
            'SELECT id FROM planes_catalogo WHERE modalidad = ? AND clases_semana = ? AND activo = 1'
        );
        $check->execute([$datos['modalidad'], $datos['clasesSemana']]);
        if ($check->fetch()) {
            throw new \RuntimeException('Ya existe un plan con esa modalidad y número de clases.');
        }

        $id = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO planes_catalogo (id, modalidad, clases_semana, mensualidad, label, activo)
             VALUES (:id, :modalidad, :clases, :monto, :label, 1)'
        )->execute([
            ':id'       => $id,
            ':modalidad'=> $datos['modalidad'],
            ':clases'   => $datos['clasesSemana'],
            ':monto'    => $datos['mensualidad'],
            ':label'    => $datos['label'] ?? "{$datos['modalidad']} · {$datos['clasesSemana']} clase/sem",
        ]);

        return $this->porId($id);
    }

    public function actualizarPorId(string $id, array $datos): ?array
    {
        $set = []; $params = [':id' => $id];

        if (isset($datos['label'])) {
            $set[] = 'label = :label';
            $params[':label'] = $datos['label'];
        }
        if (isset($datos['mensualidad'])) {
            $set[] = 'mensualidad = :monto';
            $params[':monto'] = $datos['mensualidad'];
        }
        if (isset($datos['clasesSemana'])) {
            $set[] = 'clases_semana = :clases';
            $params[':clases'] = $datos['clasesSemana'];
        }

        if (!empty($set)) {
            $this->pdo->prepare(
                'UPDATE planes_catalogo SET ' . implode(', ', $set) . ' WHERE id = :id'
            )->execute($params);
        }

        return $this->porId($id);
    }

    public function eliminar(string $id): void
    {
        $this->pdo->prepare(
            'UPDATE planes_catalogo SET activo = 0 WHERE id = ?'
        )->execute([$id]);
    }

    private function porId(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM planes_catalogo WHERE id = ?');
        $stmt->execute([$id]);
        return $this->mapear($stmt->fetch());
    }

    private function mapear(array $row): array
    {
        return [
            'id'           => $row['id'],
            'modalidad'    => $row['modalidad'],
            'clasesSemana' => (int)   $row['clases_semana'],
            'mensualidad'  => (float) $row['mensualidad'],
            'label'        => $row['label'],
            'activo'       => (bool)  $row['activo'],
        ];
    }

    private function uuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
        );
    }
}
