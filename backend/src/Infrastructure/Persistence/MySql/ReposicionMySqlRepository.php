<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Reposicion\ReposicionRepository;
use PDO;
use RuntimeException;

class ReposicionMySqlRepository implements ReposicionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function listar(string $sucursalId, ?string $estado = null): array
    {
        $this->vencerCaducados();

        $sql = "SELECT r.*, a.nombre AS alumno_nombre, a.modalidad AS alumno_modalidad,
                       i.nombre AS instructor_nombre, i.iniciales AS instructor_iniciales
                FROM reposiciones r
                JOIN alumnos a ON a.folio = r.alumno_folio
                LEFT JOIN instructores i ON i.id = r.instructor_id
                WHERE r.sucursal_id = :suc";
        $params = [':suc' => $sucursalId];
        if ($estado) {
            $sql .= ' AND r.estado = :estado';
            $params[':estado'] = $estado;
        }
        $sql .= ' ORDER BY FIELD(r.estado,\'pendiente\',\'agendada\',\'vencida\',\'cancelada\'), r.creada_en DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map($this->mapear(...), $stmt->fetchAll());
    }

    public function porId(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.*, a.nombre AS alumno_nombre, a.modalidad AS alumno_modalidad,
                    i.nombre AS instructor_nombre, i.iniciales AS instructor_iniciales
             FROM reposiciones r
             JOIN alumnos a ON a.folio = r.alumno_folio
             LEFT JOIN instructores i ON i.id = r.instructor_id
             WHERE r.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->mapear($row) : null;
    }

    public function crearManual(array $datos): array
    {
        $dias  = $this->diasVigencia();
        $vence = date('Y-m-d', strtotime("+$dias days"));
        $id    = $this->uuid();

        $this->pdo->prepare(
            'INSERT INTO reposiciones (id, alumno_folio, sucursal_id, motivo, origen, estado, vence)
             VALUES (:id, :folio, :suc, :motivo, \'manual\', \'pendiente\', :vence)'
        )->execute([
            ':id'     => $id,
            ':folio'  => $datos['alumnoFolio'],
            ':suc'    => $datos['sucursalId'],
            ':motivo' => $datos['motivo'] ?? null,
            ':vence'  => $vence,
        ]);

        return $this->porId($id);
    }

    public function agendar(string $id, array $cambios): array
    {
        $rep = $this->porId($id);
        if (!$rep) {
            throw new RuntimeException('Reposición no encontrada', 404);
        }
        if ($rep['estado'] === 'cancelada' || $rep['estado'] === 'vencida') {
            throw new RuntimeException('Esta reposición ya no está disponible', 409);
        }

        $fecha = $cambios['fecha'];
        $datos = [
            'instructorId' => $cambios['instructorId'],
            'fecha'        => $fecha,
            'dia'          => $this->diaDeFecha($fecha),
            'horaInicio'   => $cambios['horaInicio'],
            'duracionMin'  => (int) ($cambios['duracionMin'] ?? 30),
            'alumnoFolio'  => $rep['alumnoFolio'],
        ];

        $this->validarColocacion($datos, $id);

        $this->pdo->prepare(
            'UPDATE reposiciones
             SET estado = \'agendada\', fecha = :fecha, instructor_id = :inst,
                 hora_inicio = :hi, duracion_min = :dur, agendada_en = NOW()
             WHERE id = :id'
        )->execute([
            ':fecha' => $fecha,
            ':inst'  => $datos['instructorId'],
            ':hi'    => $datos['horaInicio'],
            ':dur'   => $datos['duracionMin'],
            ':id'    => $id,
        ]);

        return $this->porId($id);
    }

    public function desagendar(string $id): array
    {
        $this->pdo->prepare(
            'UPDATE reposiciones
             SET estado = \'pendiente\', fecha = NULL, instructor_id = NULL,
                 hora_inicio = NULL, duracion_min = NULL, agendada_en = NULL
             WHERE id = :id'
        )->execute([':id' => $id]);
        return $this->porId($id);
    }

    public function cancelar(string $id): void
    {
        $this->pdo->prepare('UPDATE reposiciones SET estado = \'cancelada\' WHERE id = ?')->execute([$id]);
    }

    public function agendadasEnSemana(string $sucursalId, string $lunes, string $sabado): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.alumno_folio, r.fecha, r.hora_inicio, r.duracion_min, r.motivo,
                    r.instructor_id, a.nombre AS alumno_nombre, a.modalidad AS alumno_modalidad,
                    i.nombre AS instructor_nombre, i.iniciales AS instructor_iniciales
             FROM reposiciones r
             JOIN alumnos a ON a.folio = r.alumno_folio
             JOIN instructores i ON i.id = r.instructor_id
             WHERE r.sucursal_id = :suc AND r.estado = 'agendada'
               AND r.fecha BETWEEN :lunes AND :sabado
             ORDER BY r.fecha, r.hora_inicio"
        );
        $stmt->execute([':suc' => $sucursalId, ':lunes' => $lunes, ':sabado' => $sabado]);

        return array_map(function (array $r): array {
            $hi = substr((string) $r['hora_inicio'], 0, 5);
            return [
                'id'                  => $r['id'],
                'instructorId'        => $r['instructor_id'],
                'instructorNombre'    => $r['instructor_nombre'],
                'instructorIniciales' => $r['instructor_iniciales'],
                'dia'                 => $this->diaDeFecha($r['fecha']),
                'horaInicio'          => $hi,
                'duracionMin'         => (int) $r['duracion_min'],
                'modalidad'           => $r['alumno_modalidad'],
                'alumnoFolio'         => $r['alumno_folio'],
                'alumnoNombre'        => $r['alumno_nombre'],
                'grupoId'             => null,
                'grupoNombre'         => null,
                'grupoAlumnos'        => [],
                'esReposicion'        => true,
                'reposicionId'        => $r['id'],
                'fecha'               => $r['fecha'],
                'motivo'              => $r['motivo'],
                'vigenteDesde'        => $r['fecha'],
                'vigenteHasta'        => $r['fecha'],
            ];
        }, $stmt->fetchAll());
    }

    public function vencerCaducados(): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE reposiciones SET estado = 'vencida'
             WHERE estado = 'pendiente' AND vence IS NOT NULL AND vence < CURDATE()"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    // ── Validación de colocación (date-specific) ─────────────────────────────
    private function validarColocacion(array $d, string $excludeId): void
    {
        $dur = $d['duracionMin'];

        // 0. El instructor debe estar disponible en esa franja
        $disp = $this->pdo->prepare('SELECT disp_hora_inicio, disp_hora_fin FROM instructores WHERE id = ?');
        $disp->execute([$d['instructorId']]);
        $rango = $disp->fetch();
        if ($rango && $rango['disp_hora_inicio'] && $rango['disp_hora_fin']) {
            $ini = substr((string) $rango['disp_hora_inicio'], 0, 5);
            $fin = substr((string) $rango['disp_hora_fin'], 0, 5);
            $hf  = date('H:i', (int) strtotime($d['horaInicio']) + $dur * 60);
            if ($d['horaInicio'] < $ini || $hf > $fin) {
                throw new RuntimeException("El instructor solo está disponible de $ini a $fin", 409);
            }
        }

        // 1a. Instructor ocupado por una clase recurrente vigente ese día/fecha
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM horario_recurrente hr
             WHERE hr.instructor_id = :inst AND hr.dia = :dia
               AND hr.vigente_desde <= :f1
               AND (hr.vigente_hasta IS NULL OR hr.vigente_hasta >= :f2)
               AND hr.hora_inicio < ADDTIME(TIME(:hi1), SEC_TO_TIME(:dur1 * 60))
               AND TIME(:hi2) < ADDTIME(hr.hora_inicio, SEC_TO_TIME(hr.duracion_min * 60))"
        );
        $stmt->execute([
            ':inst' => $d['instructorId'], ':dia' => $d['dia'],
            ':f1' => $d['fecha'], ':f2' => $d['fecha'],
            ':hi1' => $d['horaInicio'], ':hi2' => $d['horaInicio'], ':dur1' => $dur,
        ]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('El instructor ya tiene una clase en ese horario', 409);
        }

        // 1b. Instructor ocupado por otra reposición ese día
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM reposiciones r
             WHERE r.estado = 'agendada' AND r.instructor_id = :inst AND r.fecha = :fecha AND r.id <> :ex
               AND r.hora_inicio < ADDTIME(TIME(:hi1), SEC_TO_TIME(:dur1 * 60))
               AND TIME(:hi2) < ADDTIME(r.hora_inicio, SEC_TO_TIME(r.duracion_min * 60))"
        );
        $stmt->execute([
            ':inst' => $d['instructorId'], ':fecha' => $d['fecha'], ':ex' => $excludeId,
            ':hi1' => $d['horaInicio'], ':hi2' => $d['horaInicio'], ':dur1' => $dur,
        ]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('El instructor ya tiene otra reposición en ese horario', 409);
        }

        // 2a. Alumno ya tiene clase recurrente ese día/fecha
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM horario_recurrente hr
             LEFT JOIN grupo_alumnos ga ON ga.grupo_id = hr.grupo_id
             WHERE hr.dia = :dia
               AND hr.vigente_desde <= :f1
               AND (hr.vigente_hasta IS NULL OR hr.vigente_hasta >= :f2)
               AND (hr.alumno_folio = :fol1 OR ga.alumno_folio = :fol2)
               AND hr.hora_inicio < ADDTIME(TIME(:hi1), SEC_TO_TIME(:dur1 * 60))
               AND TIME(:hi2) < ADDTIME(hr.hora_inicio, SEC_TO_TIME(hr.duracion_min * 60))"
        );
        $stmt->execute([
            ':dia' => $d['dia'], ':f1' => $d['fecha'], ':f2' => $d['fecha'],
            ':fol1' => $d['alumnoFolio'], ':fol2' => $d['alumnoFolio'],
            ':hi1' => $d['horaInicio'], ':hi2' => $d['horaInicio'], ':dur1' => $dur,
        ]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('El alumno ya tiene clase en ese horario', 409);
        }

        // 2b. Alumno ya tiene otra reposición esa fecha
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM reposiciones r
             WHERE r.estado = 'agendada' AND r.fecha = :fecha AND r.alumno_folio = :fol AND r.id <> :ex
               AND r.hora_inicio < ADDTIME(TIME(:hi1), SEC_TO_TIME(:dur1 * 60))
               AND TIME(:hi2) < ADDTIME(r.hora_inicio, SEC_TO_TIME(r.duracion_min * 60))"
        );
        $stmt->execute([
            ':fecha' => $d['fecha'], ':fol' => $d['alumnoFolio'], ':ex' => $excludeId,
            ':hi1' => $d['horaInicio'], ':hi2' => $d['horaInicio'], ':dur1' => $dur,
        ]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('El alumno ya tiene otra reposición en ese horario', 409);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    private function diasVigencia(): int
    {
        $stmt = $this->pdo->query("SELECT valor FROM configuracion WHERE clave = 'reposicion_dias_vigencia'");
        $v = $stmt->fetchColumn();
        return $v !== false ? (int) $v : 30;
    }

    private function diaDeFecha(string $fecha): string
    {
        $n = (int) date('N', strtotime($fecha)); // 1=Lun … 7=Dom
        return ['L', 'M', 'X', 'J', 'V', 'S', 'D'][$n - 1] ?? 'L';
    }

    private function mapear(array $r): array
    {
        return [
            'id'                  => $r['id'],
            'alumnoFolio'         => $r['alumno_folio'],
            'alumnoNombre'        => $r['alumno_nombre'] ?? null,
            'alumnoModalidad'     => $r['alumno_modalidad'] ?? null,
            'sucursalId'          => $r['sucursal_id'],
            'motivo'              => $r['motivo'],
            'origen'              => $r['origen'],
            'estado'              => $r['estado'],
            'vence'               => $r['vence'],
            'fecha'               => $r['fecha'],
            'instructorId'        => $r['instructor_id'],
            'instructorNombre'    => $r['instructor_nombre'] ?? null,
            'instructorIniciales' => $r['instructor_iniciales'] ?? null,
            'horaInicio'          => $r['hora_inicio'] ? substr((string) $r['hora_inicio'], 0, 5) : null,
            'duracionMin'         => $r['duracion_min'] !== null ? (int) $r['duracion_min'] : null,
            'creadaEn'            => $r['creada_en'],
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
