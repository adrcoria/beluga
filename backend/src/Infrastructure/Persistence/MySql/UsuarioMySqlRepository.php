<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Usuario\UsuarioRepository;
use PDO;

class UsuarioMySqlRepository implements UsuarioRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function listar(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, nombre, email, rol, sucursales, activo, creado_en
             FROM usuarios WHERE activo = 1 ORDER BY nombre'
        );
        return array_map(fn($r) => $this->mapear($r), $stmt->fetchAll());
    }

    public function crear(array $datos): array
    {
        $id = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO usuarios (id, nombre, email, password_hash, rol, sucursales, activo, debe_cambiar_password)
             VALUES (:id, :nombre, :email, :ph, :rol, :suc, 1, 1)'
        )->execute([
            ':id'     => $id,
            ':nombre' => $datos['nombre'],
            ':email'  => $datos['email'],
            ':ph'     => password_hash('12345678', PASSWORD_BCRYPT),
            ':rol'    => $datos['rol'],
            ':suc'    => json_encode($datos['sucursales'] ?? ['*']),
        ]);

        return $this->porId($id);
    }

    public function actualizar(string $id, array $datos): ?array
    {
        $set    = [];
        $params = [':id' => $id];

        foreach (['nombre', 'email', 'rol'] as $campo) {
            if (isset($datos[$campo])) {
                $set[] = "$campo = :$campo";
                $params[":$campo"] = $datos[$campo];
            }
        }
        if (!empty($datos['sucursales'])) {
            $set[] = 'sucursales = :suc';
            $params[':suc'] = json_encode($datos['sucursales']);
        }
        if (isset($datos['activo'])) {
            $set[] = 'activo = :activo';
            $params[':activo'] = (int) $datos['activo'];
        }
        if (!empty($datos['password'])) {
            $set[] = 'password_hash = :ph';
            $params[':ph'] = password_hash($datos['password'], PASSWORD_BCRYPT);
        }

        if (!empty($set)) {
            $this->pdo->prepare(
                'UPDATE usuarios SET ' . implode(', ', $set) . ' WHERE id = :id'
            )->execute($params);
        }

        return $this->porId($id);
    }

    private function porId(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nombre, email, rol, sucursales, activo, creado_en FROM usuarios WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->mapear($row) : null;
    }

    private function mapear(array $r): array
    {
        return [
            'id'         => $r['id'],
            'nombre'     => $r['nombre'],
            'email'      => $r['email'],
            'rol'        => $r['rol'],
            'sucursales' => json_decode($r['sucursales'], true),
            'activo'     => (bool) $r['activo'],
            'creadoEn'   => $r['creado_en'],
        ];
    }

    private function uuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
