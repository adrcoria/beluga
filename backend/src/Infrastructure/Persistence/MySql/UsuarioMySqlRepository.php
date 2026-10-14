<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Usuario\UsuarioRepository;
use PDO;
use RuntimeException;

class UsuarioMySqlRepository implements UsuarioRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function listar(bool $incluirInactivos = false): array
    {
        $where = $incluirInactivos ? '' : 'WHERE activo = 1';

        // Los activos primero: en el listado normal lo que importa es quién
        // tiene acceso hoy, y los de baja quedan al final como referencia.
        $stmt = $this->pdo->query(
            "SELECT id, nombre, email, rol, sucursales, activo, creado_en
             FROM usuarios $where
             ORDER BY activo DESC, nombre"
        );

        $usuarios = array_map(fn($r) => $this->mapear($r), $stmt->fetchAll());

        // A los inactivos se les calcula la huella para saber si se pueden
        // borrar, y así la pantalla no ofrece un botón que va a fallar.
        foreach ($usuarios as &$u) {
            $u['puedeEliminarse'] = $u['activo'] ? false : array_sum($this->huella($u['id'])) === 0;
        }

        return $usuarios;
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

    public function huella(string $id): array
    {
        $contar = function (string $sql) use ($id): int {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$id]);
            return (int) $stmt->fetchColumn();
        };

        return [
            'pagos'         => $contar('SELECT COUNT(*) FROM pagos WHERE usuario_id = ?'),
            'inhabiles'     => $contar('SELECT COUNT(*) FROM inhabiles WHERE declarado_por = ?'),
            'condonaciones' => $contar('SELECT COUNT(*) FROM cobros WHERE condonado_por = ?'),
        ];
    }

    public function eliminar(string $id): void
    {
        $u = $this->porId($id);
        if (!$u) {
            throw new RuntimeException('Usuario no encontrado');
        }
        if ($u['activo']) {
            throw new RuntimeException(
                'Primero da de baja al usuario. Solo se pueden eliminar los que ya están inactivos.'
            );
        }

        $huella = $this->huella($id);
        if (array_sum($huella) > 0) {
            // pagos e inhabiles lo referencian con ON DELETE RESTRICT: la base
            // lo rechazaría de todos modos. Mejor explicarlo que mostrar el
            // error de la llave foránea.
            $partes = [];
            if ($huella['pagos'])         $partes[] = $huella['pagos'] . ' pago(s) cobrado(s)';
            if ($huella['inhabiles'])     $partes[] = $huella['inhabiles'] . ' día(s) inhábil(es) declarado(s)';
            if ($huella['condonaciones']) $partes[] = $huella['condonaciones'] . ' cobro(s) condonado(s)';

            throw new RuntimeException(
                'No se puede eliminar: tiene ' . implode(' y ', $partes) . ' a su nombre. '
                . 'Esos movimientos deben seguir diciendo quién los hizo, así que el usuario se queda como inactivo.'
            );
        }

        // Los OTP caen solos por ON DELETE CASCADE
        $this->pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
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
