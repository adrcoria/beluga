<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Instructor\InstructorRepository;
use PDO;

class InstructorMySqlRepository implements InstructorRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function listar(?string $sucursalId = null, bool $todos = false): array
    {
        $where  = [];
        $params = [];

        if (!$todos) {
            $where[] = 'i.activo = 1';
        }
        if ($sucursalId) {
            $where[] = 'JSON_CONTAINS(i.sucursales, :sid)';
            $params[':sid'] = json_encode($sucursalId);
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT i.id, i.nombre, i.iniciales, i.telefono, i.email, i.sucursales, i.activo,
                       i.disp_hora_inicio, i.disp_hora_fin,
                       GROUP_CONCAT(im.modalidad_clave ORDER BY im.modalidad_clave SEPARATOR ',') AS modalidades_raw
                FROM instructores i
                LEFT JOIN instructor_modalidades im ON im.instructor_id = i.id
                {$whereClause}
                GROUP BY i.id
                ORDER BY i.nombre";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map($this->mapear(...), $stmt->fetchAll());
    }

    public function porId(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.id, i.nombre, i.iniciales, i.telefono, i.email, i.sucursales, i.activo,
                    i.disp_hora_inicio, i.disp_hora_fin,
                    GROUP_CONCAT(im.modalidad_clave ORDER BY im.modalidad_clave SEPARATOR ',') AS modalidades_raw
             FROM instructores i
             LEFT JOIN instructor_modalidades im ON im.instructor_id = i.id
             WHERE i.id = ?
             GROUP BY i.id"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->mapear($row) : null;
    }

    public function crear(array $datos): array
    {
        $id = $this->uuid();

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO instructores (id, nombre, iniciales, telefono, email, sucursales, disp_hora_inicio, disp_hora_fin)
                 VALUES (:id, :nombre, :iniciales, :telefono, :email, :sucursales, :dhi, :dhf)'
            )->execute([
                ':id'         => $id,
                ':nombre'     => $datos['nombre'],
                ':iniciales'  => $datos['iniciales'],
                ':telefono'   => $datos['telefono']  ?? '',
                ':email'      => $datos['email']     ?? null,
                ':sucursales' => json_encode($datos['sucursales'] ?? []),
                ':dhi'        => $datos['dispHoraInicio'] ?: null,
                ':dhf'        => $datos['dispHoraFin']    ?: null,
            ]);

            $this->sincronizarModalidades($id, $datos['niveles'] ?? []);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->porId($id);
    }

    public function actualizar(string $id, array $datos): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE instructores
                 SET nombre=:nombre, iniciales=:iniciales, telefono=:telefono,
                     email=:email, sucursales=:sucursales, activo=:activo,
                     disp_hora_inicio=:dhi, disp_hora_fin=:dhf
                 WHERE id=:id'
            )->execute([
                ':id'         => $id,
                ':nombre'     => $datos['nombre'],
                ':iniciales'  => $datos['iniciales'],
                ':telefono'   => $datos['telefono']  ?? '',
                ':email'      => $datos['email']     ?? null,
                ':sucursales' => json_encode($datos['sucursales'] ?? []),
                ':activo'     => ($datos['activo'] ?? true) ? 1 : 0,
                ':dhi'        => $datos['dispHoraInicio'] ?: null,
                ':dhf'        => $datos['dispHoraFin']    ?: null,
            ]);

            $this->sincronizarModalidades($id, $datos['niveles'] ?? []);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->porId($id);
    }

    public function eliminar(string $id): void
    {
        $this->pdo->prepare('DELETE FROM instructor_modalidades WHERE instructor_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM instructores WHERE id = ?')->execute([$id]);
    }

    private function sincronizarModalidades(string $instructorId, array $modalidades): void
    {
        $this->pdo->prepare('DELETE FROM instructor_modalidades WHERE instructor_id = ?')
                  ->execute([$instructorId]);

        if (empty($modalidades)) return;

        $stmt = $this->pdo->prepare(
            'INSERT INTO instructor_modalidades (instructor_id, modalidad_clave) VALUES (?, ?)'
        );
        foreach ($modalidades as $clave) {
            $stmt->execute([$instructorId, $clave]);
        }
    }

    private function mapear(array $row): array
    {
        return [
            'id'         => $row['id'],
            'nombre'     => $row['nombre'],
            'iniciales'  => $row['iniciales'],
            'telefono'   => $row['telefono'],
            'email'      => $row['email'],
            'niveles'    => $row['modalidades_raw'] ? explode(',', $row['modalidades_raw']) : [],
            'sucursales' => json_decode($row['sucursales'], true),
            'activo'     => (bool) $row['activo'],
            'dispHoraInicio' => $row['disp_hora_inicio'] ? substr((string) $row['disp_hora_inicio'], 0, 5) : null,
            'dispHoraFin'    => $row['disp_hora_fin']    ? substr((string) $row['disp_hora_fin'], 0, 5)    : null,
        ];
    }

    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
