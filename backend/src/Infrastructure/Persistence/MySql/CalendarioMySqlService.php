<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Calendario\CalendarioService;
use PDO;
use Ramsey\Uuid\Uuid;

class CalendarioMySqlService implements CalendarioService
{
    public function __construct(private readonly PDO $pdo) {}

    // ── Pizarrón: slots del día con sus asignaciones ───────────────────────

    public function obtenerPizarron(string $sucursalId, string $dia, string $fecha): array
    {
        // Todos los slots activos para ese día/sucursal
        $stmt = $this->pdo->prepare(
            'SELECT s.*, i.nombre AS instructor_nombre, i.iniciales AS instructor_iniciales
             FROM slots s
             JOIN instructores i ON i.id = s.instructor_id
             WHERE s.sucursal_id = :suc AND s.dia = :dia AND s.activo = 1
             ORDER BY s.hora_inicio, i.nombre'
        );
        $stmt->execute([':suc' => $sucursalId, ':dia' => $dia]);
        $slots = $stmt->fetchAll();

        if (empty($slots)) return [];

        // Asignaciones de esa fecha para esos slots
        $slotIds    = array_column($slots, 'id');
        $inClause   = implode(',', array_fill(0, count($slotIds), '?'));
        $aStmt      = $this->pdo->prepare(
            "SELECT a.*, al.nombre AS alumno_nombre, al.modalidad AS alumno_modalidad,
                    al.vencimiento AS alumno_vencimiento
             FROM asignaciones a
             JOIN alumnos al ON al.folio = a.alumno_folio
             WHERE a.slot_id IN ($inClause) AND a.fecha = ?
             ORDER BY al.nombre"
        );
        $aStmt->execute([...$slotIds, $fecha]);
        $asignaciones = $aStmt->fetchAll();

        // Agrupar asignaciones por slot_id
        $porSlot = [];
        foreach ($asignaciones as $a) {
            $porSlot[$a['slot_id']][] = $this->mapearAsignacion($a);
        }

        // Calcular cupo disponible real
        return array_map(function (array $slot) use ($porSlot): array {
            $asigs   = $porSlot[$slot['id']] ?? [];
            $ocupado = count($asigs);
            return [
                'slotId'               => $slot['id'],
                'instructorId'         => $slot['instructor_id'],
                'instructorNombre'     => $slot['instructor_nombre'],
                'instructorIniciales'  => $slot['instructor_iniciales'],
                'dia'                  => $slot['dia'],
                'horaInicio'           => $slot['hora_inicio'],
                'horaFin'              => $slot['hora_fin'],
                'modalidad'            => $slot['modalidad'],
                'cupoMax'              => (int) $slot['cupo_max'],
                'cupoDisponible'       => max(0, (int)$slot['cupo_max'] - $ocupado),
                'asignaciones'         => $asigs,
            ];
        }, $slots);
    }

    // ── Asignar alumno a slot ─────────────────────────────────────────────

    public function asignar(string $folio, string $slotId, string $fecha, string $estado): array
    {
        // Validar que el slot existe y tiene cupo
        $slot = $this->obtenerSlot($slotId);
        if (!$slot) {
            throw new \RuntimeException('Slot no encontrado', 404);
        }

        // Validar cupo disponible
        $ocupado = $this->contarAsignaciones($slotId, $fecha);
        if ($ocupado >= $slot['cupo_max']) {
            throw new \RuntimeException('El slot no tiene cupo disponible', 409);
        }

        // Validar modalidad del alumno
        $alumno = $this->obtenerAlumno($folio);
        if (!$alumno) {
            throw new \RuntimeException('Alumno no encontrado', 404);
        }
        if ($alumno['modalidad'] !== $slot['modalidad']) {
            throw new \RuntimeException(
                "Modalidad incompatible: alumno={$alumno['modalidad']}, slot={$slot['modalidad']}",
                422
            );
        }

        $id = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO asignaciones (id, alumno_folio, slot_id, fecha, estado)
             VALUES (:id, :folio, :slot, :fecha, :estado)'
        )->execute([
            ':id'    => $id,
            ':folio' => $folio,
            ':slot'  => $slotId,
            ':fecha' => $fecha,
            ':estado'=> $estado,
        ]);

        return $this->obtenerAsignacion($id);
    }

    // ── Actualizar estado / nota de asignación ────────────────────────────

    public function actualizarAsignacion(string $id, array $cambios): ?array
    {
        $set    = [];
        $params = [':id' => $id];

        if (array_key_exists('estado', $cambios)) {
            $set[] = 'estado = :estado';
            $params[':estado'] = $cambios['estado'];
        }
        if (array_key_exists('nota', $cambios)) {
            $set[] = 'nota = :nota';
            $params[':nota'] = $cambios['nota'];
        }

        if (empty($set)) return $this->obtenerAsignacion($id);

        $this->pdo->prepare('UPDATE asignaciones SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);

        return $this->obtenerAsignacion($id);
    }

    // ── Slots sugeridos para asignación inteligente ───────────────────────

    public function slotsSugeridos(string $folio, string $fechaInicio): array
    {
        $alumno = $this->obtenerAlumno($folio);
        if (!$alumno) return [];

        $dias        = json_decode($alumno['disp_dias'], true);
        $horaInicio  = $alumno['disp_hora_inicio'];
        $horaFin     = $alumno['disp_hora_fin'];
        $modalidad   = $alumno['modalidad'];

        if (empty($dias)) return [];

        $inDias  = implode(',', array_fill(0, count($dias), '?'));

        $sql = "SELECT s.*, i.nombre AS instructor_nombre, i.iniciales AS instructor_iniciales,
                       (s.cupo_max - IFNULL(
                            (SELECT COUNT(*) FROM asignaciones a WHERE a.slot_id = s.id AND a.fecha = :fecha),
                            0)) AS cupo_disponible
                FROM slots s
                JOIN instructores i ON i.id = s.instructor_id
                WHERE s.modalidad = :modalidad
                  AND s.dia IN ($inDias)
                  AND s.hora_inicio >= :hora_inicio
                  AND s.hora_inicio < :hora_fin
                  AND s.activo = 1
                HAVING cupo_disponible > 0
                ORDER BY s.dia, s.hora_inicio";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':fecha' => $fechaInicio, ':modalidad' => $modalidad, ...$dias, ':hora_inicio' => $horaInicio, ':hora_fin' => $horaFin]);

        return $stmt->fetchAll();
    }

    // ── Horario semanal de un alumno ──────────────────────────────────────

    public function horarioPorAlumno(string $folio): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT s.id AS slot_id, s.dia, s.hora_inicio, s.hora_fin,
                    s.modalidad, i.nombre AS instructor_nombre, i.iniciales AS instructor_iniciales
             FROM asignaciones a
             JOIN slots s ON s.id = a.slot_id
             JOIN instructores i ON i.id = s.instructor_id
             WHERE a.alumno_folio = :folio
               AND a.estado IN ('regular','reposicion')
             ORDER BY FIELD(s.dia,'L','M','X','J','V','S'), s.hora_inicio"
        );
        $stmt->execute([':folio' => $folio]);

        return array_map(fn(array $r) => [
            'slotId'              => $r['slot_id'],
            'dia'                 => $r['dia'],
            'horaInicio'          => $r['hora_inicio'],
            'horaFin'             => $r['hora_fin'],
            'modalidad'           => $r['modalidad'],
            'instructorNombre'    => $r['instructor_nombre'],
            'instructorIniciales' => $r['instructor_iniciales'],
        ], $stmt->fetchAll());
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    private function obtenerSlot(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM slots WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function obtenerAlumno(string $folio): ?array
    {
        $stmt = $this->pdo->prepare('SELECT folio, nombre, modalidad, vencimiento, disp_dias, disp_hora_inicio, disp_hora_fin FROM alumnos WHERE folio = ?');
        $stmt->execute([$folio]);
        return $stmt->fetch() ?: null;
    }

    private function contarAsignaciones(string $slotId, string $fecha): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM asignaciones WHERE slot_id = ? AND fecha = ?');
        $stmt->execute([$slotId, $fecha]);
        return (int) $stmt->fetchColumn();
    }

    private function obtenerAsignacion(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, al.nombre AS alumno_nombre, al.modalidad AS alumno_modalidad, al.vencimiento AS alumno_vencimiento
             FROM asignaciones a JOIN alumnos al ON al.folio = a.alumno_folio WHERE a.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->mapearAsignacion($row) : null;
    }

    private function mapearAsignacion(array $row): array
    {
        return [
            'id'                  => $row['id'],
            'alumnoFolio'         => $row['alumno_folio'],
            'alumnoNombre'        => $row['alumno_nombre']     ?? null,
            'alumnoModalidad'     => $row['alumno_modalidad']  ?? null,
            'alumnoVencimiento'   => $row['alumno_vencimiento'] ?? null,
            'slotId'              => $row['slot_id'],
            'fecha'               => $row['fecha'],
            'estado'              => $row['estado'],
            'nota'                => $row['nota'],
            'generadaPorInhabilId'=> $row['generada_por_inhabil_id'],
            'registradaEn'        => $row['registrada_en'],
            'tieneNota'           => !empty($row['nota']),
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
