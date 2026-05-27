<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Inhabil\InhabilService;
use PDO;

class InhabilMySqlService implements InhabilService
{
    // Días de semana MySQL (1=Dom,2=Lun…7=Sáb) → nuestra clave
    private const MYSQL_DIA_MAP = [2 => 'L', 3 => 'M', 4 => 'X', 5 => 'J', 6 => 'V', 7 => 'S'];

    public function __construct(private readonly PDO $pdo) {}

    // ── Declarar (preview o aplicar) ──────────────────────────────────────

    public function declarar(
        string  $fecha,
        string  $motivo,
        ?string $sucursalId,
        string  $usuarioId,
        bool    $soloPreview = false
    ): array {
        // Asignaciones afectadas ese día
        $afectadas = $this->obtenerAsignacionesEnFecha($fecha, $sucursalId);
        $total     = count($afectadas);

        if ($soloPreview) {
            return [
                'fecha'            => $fecha,
                'alumnosAfectados' => $total,
                'detalleAlumnos'   => array_map(fn($a) => [
                    'folio'  => $a['alumno_folio'],
                    'nombre' => $a['alumno_nombre'],
                    'slot'   => $a['slot_id'],
                ], $afectadas),
            ];
        }

        // Aplicar: crear inhábil y generar reposiciones
        $inhabilId = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO inhabiles (id, fecha, motivo, sucursal_id, declarado_por)
             VALUES (:id, :fecha, :motivo, :suc, :usr)'
        )->execute([
            ':id'    => $inhabilId,
            ':fecha' => $fecha,
            ':motivo'=> $motivo,
            ':suc'   => $sucursalId,
            ':usr'   => $usuarioId,
        ]);

        $generadas = 0;
        $pendientes = 0;

        foreach ($afectadas as $asig) {
            $repoSlot = $this->buscarSlotReposicion(
                alumnoFolio: $asig['alumno_folio'],
                modalidad:   $asig['alumno_modalidad'],
                dispDias:    json_decode($asig['disp_dias'], true),
                horaInicio:  $asig['disp_hora_inicio'],
                horaFin:     $asig['disp_hora_fin'],
                desdeFecha:  $fecha,
                excluirSlot: $asig['slot_id']
            );

            if ($repoSlot) {
                $this->pdo->prepare(
                    'INSERT INTO asignaciones (id, alumno_folio, slot_id, fecha, estado, generada_por_inhabil_id)
                     VALUES (UUID(), :folio, :slot, :fecha, "reposicion", :inhabil)'
                )->execute([
                    ':folio'   => $asig['alumno_folio'],
                    ':slot'    => $repoSlot['id'],
                    ':fecha'   => $repoSlot['fecha_repo'],
                    ':inhabil' => $inhabilId,
                ]);
                $generadas++;
            } else {
                $pendientes++;
            }
        }

        // Actualizar contadores en el registro inhábil
        $this->pdo->prepare(
            'UPDATE inhabiles SET estado = "aplicado", alumnos_afectados = :af, reposiciones_generadas = :rg WHERE id = :id'
        )->execute([':af' => $total, ':rg' => $generadas, ':id' => $inhabilId]);

        return [
            'inhabilId'             => $inhabilId,
            'fecha'                 => $fecha,
            'alumnosAfectados'      => $total,
            'reposicionesGeneradas' => $generadas,
            'pendientesDeAsignar'   => $pendientes,
        ];
    }

    // ── Listar inhábiles ──────────────────────────────────────────────────

    public function listar(): array
    {
        $stmt = $this->pdo->query(
            'SELECT i.*, u.nombre AS declarado_por_nombre
             FROM inhabiles i
             JOIN usuarios u ON u.id = i.declarado_por
             ORDER BY i.fecha DESC'
        );
        return array_map(fn($row) => [
            'id'                    => $row['id'],
            'fecha'                 => $row['fecha'],
            'motivo'                => $row['motivo'],
            'sucursalId'            => $row['sucursal_id'],
            'estado'                => $row['estado'],
            'alumnosAfectados'      => (int) $row['alumnos_afectados'],
            'reposicionesGeneradas' => (int) $row['reposiciones_generadas'],
            'declaradoPor'          => $row['declarado_por'],
            'declaradoPorNombre'    => $row['declarado_por_nombre'],
            'declaradoEn'           => $row['declarado_en'],
        ], $stmt->fetchAll());
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    private function obtenerAsignacionesEnFecha(string $fecha, ?string $sucursalId): array
    {
        $sql = 'SELECT a.id, a.alumno_folio, a.slot_id, a.estado,
                       al.nombre AS alumno_nombre, al.modalidad AS alumno_modalidad,
                       al.disp_dias, al.disp_hora_inicio, al.disp_hora_fin
                FROM asignaciones a
                JOIN alumnos al ON al.folio = a.alumno_folio
                JOIN slots s    ON s.id = a.slot_id
                WHERE a.fecha = :fecha';

        $params = [':fecha' => $fecha];

        if ($sucursalId) {
            $sql .= ' AND s.sucursal_id = :suc';
            $params[':suc'] = $sucursalId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Busca el primer slot compatible en los próximos 14 días.
     * Respeta: modalidad, días disponibles del alumno, rango horario y cupo.
     */
    private function buscarSlotReposicion(
        string  $alumnoFolio,
        string  $modalidad,
        array   $dispDias,
        ?string $horaInicio,
        ?string $horaFin,
        string  $desdeFecha,
        string  $excluirSlot
    ): ?array {
        if (empty($dispDias)) return null;

        $inicio = new \DateTimeImmutable($desdeFecha);
        $fin    = $inicio->modify('+14 days');

        for ($d = $inicio->modify('+1 day'); $d <= $fin; $d = $d->modify('+1 day')) {
            $diaSemana = self::MYSQL_DIA_MAP[(int) $d->format('N') + 1] ?? null;
            if (!$diaSemana || !in_array($diaSemana, $dispDias, true)) continue;

            $fechaStr = $d->format('Y-m-d');

            // Slots compatibles con cupo disponible ese día
            $sql = 'SELECT s.*,
                           (s.cupo_max - IFNULL((
                               SELECT COUNT(*) FROM asignaciones sub
                               WHERE sub.slot_id = s.id AND sub.fecha = :fecha
                           ),0)) AS cupo_disponible
                    FROM slots s
                    WHERE s.modalidad = :modalidad
                      AND s.dia       = :dia
                      AND s.id       != :excluir
                      AND s.activo    = 1';

            $params = [':fecha' => $fechaStr, ':modalidad' => $modalidad, ':dia' => $diaSemana, ':excluir' => $excluirSlot];

            if ($horaInicio) { $sql .= ' AND s.hora_inicio >= :hi'; $params[':hi'] = $horaInicio; }
            if ($horaFin)    { $sql .= ' AND s.hora_inicio < :hf';  $params[':hf'] = $horaFin; }

            $sql .= ' HAVING cupo_disponible > 0 ORDER BY s.hora_inicio LIMIT 1';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $slot = $stmt->fetch();

            if ($slot) {
                $slot['fecha_repo'] = $fechaStr;
                return $slot;
            }
        }

        return null;
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
