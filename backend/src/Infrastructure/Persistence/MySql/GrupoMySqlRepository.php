<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Grupo\GrupoRepository;
use PDO;

class GrupoMySqlRepository implements GrupoRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function listar(string $sucursalId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nombre, sucursal_id, activo FROM grupos
             WHERE sucursal_id = :suc AND activo = 1 ORDER BY nombre'
        );
        $stmt->execute([':suc' => $sucursalId]);
        $grupos = $stmt->fetchAll();
        if (empty($grupos)) return [];

        $ids   = array_column($grupos, 'id');
        $place = implode(',', array_fill(0, count($ids), '?'));
        $mStmt = $this->pdo->prepare(
            "SELECT ga.grupo_id, ga.alumno_folio, a.nombre
             FROM grupo_alumnos ga JOIN alumnos a ON a.folio = ga.alumno_folio
             WHERE ga.grupo_id IN ($place) ORDER BY a.nombre"
        );
        $mStmt->execute($ids);
        $porGrupo = [];
        foreach ($mStmt->fetchAll() as $m) {
            $porGrupo[$m['grupo_id']][] = ['folio' => $m['alumno_folio'], 'nombre' => $m['nombre']];
        }

        return array_map(fn($g) => $this->mapear($g, $porGrupo[$g['id']] ?? []), $grupos);
    }

    public function porId(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, nombre, sucursal_id, activo FROM grupos WHERE id = ?');
        $stmt->execute([$id]);
        $g = $stmt->fetch();
        if (!$g) return null;

        $mStmt = $this->pdo->prepare(
            'SELECT ga.alumno_folio, a.nombre FROM grupo_alumnos ga
             JOIN alumnos a ON a.folio = ga.alumno_folio
             WHERE ga.grupo_id = ? ORDER BY a.nombre'
        );
        $mStmt->execute([$id]);
        $miembros = array_map(
            fn($m) => ['folio' => $m['alumno_folio'], 'nombre' => $m['nombre']],
            $mStmt->fetchAll()
        );
        return $this->mapear($g, $miembros);
    }

    public function crear(array $datos): array
    {
        $id = $this->uuid();
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO grupos (id, nombre, sucursal_id) VALUES (:id,:nombre,:suc)')
                      ->execute([':id' => $id, ':nombre' => $datos['nombre'], ':suc' => $datos['sucursalId']]);
            $this->sincronizarMiembros($id, $datos['alumnos'] ?? []);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->porId($id);
    }

    public function actualizar(string $id, array $datos): ?array
    {
        $this->pdo->beginTransaction();
        try {
            if (array_key_exists('nombre', $datos)) {
                $this->pdo->prepare('UPDATE grupos SET nombre = :n WHERE id = :id')
                          ->execute([':n' => $datos['nombre'], ':id' => $id]);
            }
            if (array_key_exists('alumnos', $datos)) {
                $this->sincronizarMiembros($id, $datos['alumnos']);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $this->porId($id);
    }

    public function eliminar(string $id): void
    {
        // Borrado lógico: conserva historial de horario_recurrente
        $this->pdo->prepare('UPDATE grupos SET activo = 0 WHERE id = ?')->execute([$id]);
    }

    private function sincronizarMiembros(string $grupoId, array $folios): void
    {
        $this->pdo->prepare('DELETE FROM grupo_alumnos WHERE grupo_id = ?')->execute([$grupoId]);
        if (empty($folios)) return;
        $stmt = $this->pdo->prepare('INSERT INTO grupo_alumnos (grupo_id, alumno_folio) VALUES (?, ?)');
        foreach (array_unique($folios) as $folio) {
            $stmt->execute([$grupoId, $folio]);
        }
    }

    private function mapear(array $g, array $miembros): array
    {
        return [
            'id'         => $g['id'],
            'nombre'     => $g['nombre'],
            'sucursalId' => $g['sucursal_id'],
            'activo'     => (bool) $g['activo'],
            'alumnos'    => $miembros,
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
