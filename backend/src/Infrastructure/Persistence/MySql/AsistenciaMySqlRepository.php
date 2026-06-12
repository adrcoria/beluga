<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Asistencia\AsistenciaRepository;
use PDO;
use RuntimeException;

class AsistenciaMySqlRepository implements AsistenciaRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function registrar(string $alumnoFolio, ?string $sucursalId, ?string $usuarioId, string $metodo): array
    {
        // El alumno debe existir
        $aStmt = $this->pdo->prepare('SELECT nombre, modalidad FROM alumnos WHERE folio = ?');
        $aStmt->execute([$alumnoFolio]);
        $alumno = $aStmt->fetch();
        if (!$alumno) {
            throw new RuntimeException('Alumno no encontrado', 404);
        }

        // ¿Ya registró asistencia hoy?
        $yaStmt = $this->pdo->prepare(
            'SELECT id, hora FROM asistencias WHERE alumno_folio = ? AND fecha = CURDATE()'
        );
        $yaStmt->execute([$alumnoFolio]);
        $existente = $yaStmt->fetch();

        if ($existente) {
            return [
                'alumnoFolio'  => $alumnoFolio,
                'alumnoNombre' => $alumno['nombre'],
                'modalidad'    => $alumno['modalidad'],
                'hora'         => $existente['hora'],
                'yaRegistrado' => true,
            ];
        }

        $this->pdo->prepare(
            'INSERT INTO asistencias (id, alumno_folio, fecha, sucursal_id, registrado_por, metodo)
             VALUES (UUID(), :folio, CURDATE(), :suc, :usr, :metodo)'
        )->execute([
            ':folio'  => $alumnoFolio,
            ':suc'    => $sucursalId,
            ':usr'    => $usuarioId,
            ':metodo' => $metodo === 'manual' ? 'manual' : 'qr',
        ]);

        return [
            'alumnoFolio'  => $alumnoFolio,
            'alumnoNombre' => $alumno['nombre'],
            'modalidad'    => $alumno['modalidad'],
            'hora'         => date('Y-m-d H:i:s'),
            'yaRegistrado' => false,
        ];
    }

    public function listar(?string $sucursalId, string $fecha): array
    {
        $sql = "SELECT a.id, a.alumno_folio, a.fecha, a.hora, a.metodo,
                       al.nombre AS alumno_nombre, al.modalidad AS alumno_modalidad
                FROM asistencias a
                JOIN alumnos al ON al.folio = a.alumno_folio
                WHERE a.fecha = :fecha";
        $params = [':fecha' => $fecha];
        if ($sucursalId) {
            $sql .= ' AND (a.sucursal_id = :suc OR a.sucursal_id IS NULL)';
            $params[':suc'] = $sucursalId;
        }
        $sql .= ' ORDER BY a.hora DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(fn($r) => [
            'id'           => $r['id'],
            'alumnoFolio'  => $r['alumno_folio'],
            'alumnoNombre' => $r['alumno_nombre'],
            'modalidad'    => $r['alumno_modalidad'],
            'fecha'        => $r['fecha'],
            'hora'         => $r['hora'],
            'metodo'       => $r['metodo'],
        ], $stmt->fetchAll());
    }
}
