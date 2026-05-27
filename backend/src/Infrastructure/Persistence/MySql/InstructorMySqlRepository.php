<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Instructor\InstructorRepository;
use PDO;

class InstructorMySqlRepository implements InstructorRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function listar(?string $sucursalId = null): array
    {
        if ($sucursalId) {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM instructores WHERE activo = 1 AND JSON_CONTAINS(sucursales, :sid) ORDER BY nombre"
            );
            $stmt->execute([':sid' => json_encode($sucursalId)]);
        } else {
            $stmt = $this->pdo->query("SELECT * FROM instructores WHERE activo = 1 ORDER BY nombre");
        }

        return array_map($this->mapear(...), $stmt->fetchAll());
    }

    public function porId(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM instructores WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->mapear($row) : null;
    }

    public function crear(array $datos): array
    {
        $id = $datos['id'] ?? null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO instructores (id, nombre, iniciales, telefono, email, niveles, sucursales)
             VALUES (:id, :nombre, :iniciales, :telefono, :email, :niveles, :sucursales)'
        );
        $stmt->execute([
            ':id'        => $id,
            ':nombre'    => $datos['nombre'],
            ':iniciales' => $datos['iniciales'],
            ':telefono'  => $datos['telefono'] ?? '',
            ':email'     => $datos['email']    ?? null,
            ':niveles'   => json_encode($datos['niveles']   ?? []),
            ':sucursales'=> json_encode($datos['sucursales'] ?? []),
        ]);

        if (!$id) {
            $id = $this->pdo->lastInsertId();
        }

        return $this->porId($id);
    }

    private function mapear(array $row): array
    {
        return [
            'id'         => $row['id'],
            'nombre'     => $row['nombre'],
            'iniciales'  => $row['iniciales'],
            'telefono'   => $row['telefono'],
            'email'      => $row['email'],
            'niveles'    => json_decode($row['niveles'],    true),
            'sucursales' => json_decode($row['sucursales'], true),
            'activo'     => (bool) $row['activo'],
        ];
    }
}
