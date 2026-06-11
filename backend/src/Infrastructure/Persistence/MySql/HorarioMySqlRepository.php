<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Horario\HorarioRepository;
use PDO;
use RuntimeException;

class HorarioMySqlRepository implements HorarioRepository
{
    private const INFINITO = '9999-12-31';

    public function __construct(private readonly PDO $pdo) {}

    // ── Pizarrón de la semana ──────────────────────────────────────────────
    public function pizarron(string $sucursalId, string $lunes): array
    {
        $sabado = date('Y-m-d', strtotime($lunes . ' +5 days'));

        $stmt = $this->pdo->prepare(
            "SELECT hr.id, hr.instructor_id, hr.dia, hr.hora_inicio, hr.duracion_min,
                    hr.modalidad, hr.alumno_folio, hr.grupo_id,
                    hr.vigente_desde, hr.vigente_hasta,
                    al.nombre AS alumno_nombre,
                    g.nombre  AS grupo_nombre,
                    i.nombre  AS instructor_nombre, i.iniciales AS instructor_iniciales
             FROM horario_recurrente hr
             JOIN instructores i ON i.id = hr.instructor_id
             LEFT JOIN alumnos al ON al.folio = hr.alumno_folio
             LEFT JOIN grupos  g  ON g.id    = hr.grupo_id
             WHERE hr.sucursal_id = :suc
               AND hr.vigente_desde <= :sabado
               AND (hr.vigente_hasta IS NULL OR hr.vigente_hasta >= :lunes)
             ORDER BY hr.dia, hr.hora_inicio"
        );
        $stmt->execute([':suc' => $sucursalId, ':sabado' => $sabado, ':lunes' => $lunes]);
        $filas = $stmt->fetchAll();

        // Miembros de los grupos presentes
        $miembros = $this->miembrosDeGrupos(array_values(array_filter(array_column($filas, 'grupo_id'))));

        return array_map(fn($r) => $this->mapear($r, $miembros), $filas);
    }

    // ── Crear ──────────────────────────────────────────────────────────────
    public function crear(array $datos): array
    {
        $this->validar($datos, null);

        $id = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO horario_recurrente
                (id, sucursal_id, instructor_id, dia, hora_inicio, duracion_min,
                 modalidad, alumno_folio, grupo_id, vigente_desde, vigente_hasta)
             VALUES (:id,:suc,:inst,:dia,:hi,:dur,:mod,:folio,:grupo,:vd,:vh)'
        )->execute([
            ':id'    => $id,
            ':suc'   => $datos['sucursalId'],
            ':inst'  => $datos['instructorId'],
            ':dia'   => $datos['dia'],
            ':hi'    => $datos['horaInicio'],
            ':dur'   => $datos['duracionMin'] ?? 30,
            ':mod'   => $datos['modalidad'],
            ':folio' => $datos['alumnoFolio'] ?? null,
            ':grupo' => $datos['grupoId'] ?? null,
            ':vd'    => $datos['vigenteDesde'],
            ':vh'    => $datos['vigenteHasta'] ?? null,
        ]);

        return $this->porId($id);
    }

    // ── Mover / editar con vigencia ─────────────────────────────────────────
    public function mover(string $id, array $cambios, string $desdeLunes): array
    {
        $regla = $this->porId($id);
        if (!$regla) {
            throw new RuntimeException('Asignación no encontrada', 404);
        }

        // Merge de los valores nuevos sobre los actuales
        $nuevo = [
            'sucursalId'   => $regla['sucursalId'],
            'instructorId' => $cambios['instructorId'] ?? $regla['instructorId'],
            'dia'          => $cambios['dia']          ?? $regla['dia'],
            'horaInicio'   => $cambios['horaInicio']   ?? $regla['horaInicio'],
            'duracionMin'  => $cambios['duracionMin']  ?? $regla['duracionMin'],
            'modalidad'    => $regla['modalidad'],
            'alumnoFolio'  => $regla['alumnoFolio'],
            'grupoId'      => $regla['grupoId'],
            'vigenteDesde' => $desdeLunes,
            'vigenteHasta' => $regla['vigenteHasta'],
        ];

        // Validar conflictos del destino, ignorando la propia regla
        $this->validar($nuevo, $id);

        // Si el cambio aplica desde el inicio (o antes) de la regla, se edita en sitio
        if ($desdeLunes <= $regla['vigenteDesde']) {
            $this->pdo->prepare(
                'UPDATE horario_recurrente
                 SET instructor_id=:inst, dia=:dia, hora_inicio=:hi, duracion_min=:dur
                 WHERE id=:id'
            )->execute([
                ':inst' => $nuevo['instructorId'],
                ':dia'  => $nuevo['dia'],
                ':hi'   => $nuevo['horaInicio'],
                ':dur'  => $nuevo['duracionMin'],
                ':id'   => $id,
            ]);
            return $this->porId($id);
        }

        // Si no: cerrar la regla vieja el día anterior y abrir una nueva desde desdeLunes
        $finVieja = date('Y-m-d', strtotime($desdeLunes . ' -1 day'));
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE horario_recurrente SET vigente_hasta=:vh WHERE id=:id')
                      ->execute([':vh' => $finVieja, ':id' => $id]);
            $nueva = $this->crearSinValidar($nuevo);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $nueva;
    }

    // ── Eliminar ─────────────────────────────────────────────────────────────
    public function eliminar(string $id, ?string $desdeLunes = null): void
    {
        $regla = $this->porId($id);
        if (!$regla) {
            return;
        }

        // Sin fecha, o si la regla aún no había tomado efecto: borrado total
        if ($desdeLunes === null || $desdeLunes <= $regla['vigenteDesde']) {
            $this->pdo->prepare('DELETE FROM horario_recurrente WHERE id=?')->execute([$id]);
            return;
        }

        // Si ya tuvo vigencia previa: se cierra desde el lunes dado (conserva historial)
        $fin = date('Y-m-d', strtotime($desdeLunes . ' -1 day'));
        $this->pdo->prepare('UPDATE horario_recurrente SET vigente_hasta=:vh WHERE id=:id')
                  ->execute([':vh' => $fin, ':id' => $id]);
    }

    public function porId(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT hr.*, al.nombre AS alumno_nombre, g.nombre AS grupo_nombre,
                    i.nombre AS instructor_nombre, i.iniciales AS instructor_iniciales
             FROM horario_recurrente hr
             JOIN instructores i ON i.id = hr.instructor_id
             LEFT JOIN alumnos al ON al.folio = hr.alumno_folio
             LEFT JOIN grupos  g  ON g.id    = hr.grupo_id
             WHERE hr.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $miembros = $this->miembrosDeGrupos(array_filter([$row['grupo_id']]));
        return $this->mapear($row, $miembros);
    }

    // ── Horario vigente de un alumno ─────────────────────────────────────────
    public function porAlumno(string $folio): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT hr.id, hr.dia, hr.hora_inicio, hr.duracion_min, hr.modalidad,
                    hr.grupo_id, g.nombre AS grupo_nombre,
                    i.nombre AS instructor_nombre, i.iniciales AS instructor_iniciales
             FROM horario_recurrente hr
             JOIN instructores i ON i.id = hr.instructor_id
             LEFT JOIN grupos g ON g.id = hr.grupo_id
             LEFT JOIN grupo_alumnos ga ON ga.grupo_id = hr.grupo_id
             WHERE (hr.alumno_folio = :f1 OR ga.alumno_folio = :f2)
               AND hr.vigente_desde <= CURDATE()
               AND (hr.vigente_hasta IS NULL OR hr.vigente_hasta >= CURDATE())
             ORDER BY FIELD(hr.dia,'L','M','X','J','V','S'), hr.hora_inicio"
        );
        $stmt->execute([':f1' => $folio, ':f2' => $folio]);

        return array_map(function (array $r): array {
            $hi = substr((string) $r['hora_inicio'], 0, 5);
            $hf = date('H:i', (int) strtotime($hi) + ((int) $r['duracion_min']) * 60);
            return [
                'slotId'              => $r['id'],
                'dia'                 => $r['dia'],
                'horaInicio'          => $hi,
                'horaFin'             => $hf,
                'modalidad'           => $r['modalidad'],
                'instructorNombre'    => $r['instructor_nombre'],
                'instructorIniciales' => $r['instructor_iniciales'],
                'grupoNombre'         => $r['grupo_nombre'],
            ];
        }, $stmt->fetchAll());
    }

    // ── Validación de conflictos (dura) ──────────────────────────────────────
    private function validar(array $d, ?string $excludeId): void
    {
        $esGrupal = ($d['modalidad'] === 'GRUPAL');

        if ($esGrupal && empty($d['grupoId'])) {
            throw new RuntimeException('Una clase grupal requiere un grupo', 422);
        }
        if (!$esGrupal && empty($d['alumnoFolio'])) {
            throw new RuntimeException('Selecciona un alumno', 422);
        }

        $vd = $d['vigenteDesde'];
        $vh = $d['vigenteHasta'] ?? null;
        $exclude = $excludeId ?? '';

        // 0. El instructor debe estar disponible en esa franja
        $this->validarDisponibilidad($d['instructorId'], $d['horaInicio'], $d['duracionMin'] ?? 30);

        // 1. Instructor ocupado en esa franja
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM horario_recurrente hr
             WHERE hr.instructor_id = :inst
               AND hr.dia = :dia
               AND hr.id <> :ex
               AND hr.vigente_desde <= :vh
               AND COALESCE(hr.vigente_hasta, :inf1) >= :vd
               AND hr.hora_inicio < ADDTIME(TIME(:hi1), SEC_TO_TIME(:dur1 * 60))
               AND TIME(:hi2) < ADDTIME(hr.hora_inicio, SEC_TO_TIME(hr.duracion_min * 60))"
        );
        $stmt->execute([
            ':inst' => $d['instructorId'], ':dia' => $d['dia'], ':ex' => $exclude,
            ':vh' => $vh ?? self::INFINITO, ':inf1' => self::INFINITO, ':vd' => $vd,
            ':hi1' => $d['horaInicio'], ':hi2' => $d['horaInicio'], ':dur1' => $d['duracionMin'] ?? 30,
        ]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('El instructor ya tiene una clase en ese horario', 409);
        }

        // 2. Alumno(s) ya asignado(s) en esa franja (directo o como miembro de un grupo)
        $folios = $esGrupal ? $this->foliosDeGrupo($d['grupoId']) : [$d['alumnoFolio']];
        if (empty($folios)) return;

        $place = implode(',', array_fill(0, count($folios), '?'));
        $sql = "SELECT COUNT(*) FROM horario_recurrente hr
                LEFT JOIN grupo_alumnos ga ON ga.grupo_id = hr.grupo_id
                WHERE hr.dia = ?
                  AND hr.id <> ?
                  AND hr.vigente_desde <= ?
                  AND COALESCE(hr.vigente_hasta, ?) >= ?
                  AND hr.hora_inicio < ADDTIME(TIME(?), SEC_TO_TIME(? * 60))
                  AND TIME(?) < ADDTIME(hr.hora_inicio, SEC_TO_TIME(hr.duracion_min * 60))
                  AND (hr.alumno_folio IN ($place) OR ga.alumno_folio IN ($place))";
        $params = array_merge(
            [$d['dia'], $exclude, $vh ?? self::INFINITO, self::INFINITO, $vd,
             $d['horaInicio'], $d['duracionMin'] ?? 30, $d['horaInicio']],
            $folios, $folios
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('Un alumno ya tiene clase en ese horario', 409);
        }

        // 3. Ningún alumno puede superar las clases/semana de su plan
        $this->validarLimiteSemanal($folios, $vd, $vh, $exclude);
    }

    /**
     * Bloquea si algún alumno excedería su plan (clases por semana).
     * Cuenta las reglas vigentes en el mismo periodo donde el alumno participa
     * (directo o como miembro de un grupo), en cualquier día/hora.
     */
    private function validarLimiteSemanal(array $folios, string $vd, ?string $vh, string $excludeId): void
    {
        if (empty($folios)) return;
        $vhEff = $vh ?? self::INFINITO;

        $place = implode(',', array_fill(0, count($folios), '?'));
        $pStmt = $this->pdo->prepare("SELECT folio, nombre, clases_semana FROM alumnos WHERE folio IN ($place)");
        $pStmt->execute($folios);
        $planes = [];
        foreach ($pStmt->fetchAll() as $r) {
            $planes[$r['folio']] = ['n' => (int) $r['clases_semana'], 'nombre' => $r['nombre']];
        }

        $cnt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM horario_recurrente hr
             LEFT JOIN grupo_alumnos ga ON ga.grupo_id = hr.grupo_id
             WHERE hr.id <> :ex
               AND hr.vigente_desde <= :vh
               AND COALESCE(hr.vigente_hasta, :inf) >= :vd
               AND (hr.alumno_folio = :f1 OR ga.alumno_folio = :f2)"
        );

        foreach ($folios as $folio) {
            $limite = $planes[$folio]['n'] ?? 0;
            if ($limite <= 0) continue;  // sin plan definido: no se limita

            $cnt->execute([
                ':ex' => $excludeId, ':vh' => $vhEff, ':inf' => self::INFINITO, ':vd' => $vd,
                ':f1' => $folio, ':f2' => $folio,
            ]);
            if ((int) $cnt->fetchColumn() + 1 > $limite) {
                $nombre = $planes[$folio]['nombre'] ?? $folio;
                $suf    = $limite === 1 ? 'clase' : 'clases';
                throw new RuntimeException(
                    "$nombre ya tiene sus $limite $suf de la semana (plan de $limite/sem). Sube su plan para asignar más.",
                    409
                );
            }
        }
    }

    /** El instructor debe estar disponible (su rango horario cubre la franja). */
    private function validarDisponibilidad(string $instructorId, string $horaInicio, int $durMin): void
    {
        $stmt = $this->pdo->prepare('SELECT disp_hora_inicio, disp_hora_fin FROM instructores WHERE id = ?');
        $stmt->execute([$instructorId]);
        $r = $stmt->fetch();
        if (!$r || !$r['disp_hora_inicio'] || !$r['disp_hora_fin']) {
            return; // sin rango definido = sin restricción
        }
        $ini = substr((string) $r['disp_hora_inicio'], 0, 5);
        $fin = substr((string) $r['disp_hora_fin'], 0, 5);
        $hf  = date('H:i', (int) strtotime($horaInicio) + $durMin * 60);
        if ($horaInicio < $ini || $hf > $fin) {
            throw new RuntimeException("El instructor solo está disponible de $ini a $fin", 409);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    private function crearSinValidar(array $datos): array
    {
        $id = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO horario_recurrente
                (id, sucursal_id, instructor_id, dia, hora_inicio, duracion_min,
                 modalidad, alumno_folio, grupo_id, vigente_desde, vigente_hasta)
             VALUES (:id,:suc,:inst,:dia,:hi,:dur,:mod,:folio,:grupo,:vd,:vh)'
        )->execute([
            ':id' => $id, ':suc' => $datos['sucursalId'], ':inst' => $datos['instructorId'],
            ':dia' => $datos['dia'], ':hi' => $datos['horaInicio'], ':dur' => $datos['duracionMin'] ?? 30,
            ':mod' => $datos['modalidad'], ':folio' => $datos['alumnoFolio'] ?? null,
            ':grupo' => $datos['grupoId'] ?? null, ':vd' => $datos['vigenteDesde'],
            ':vh' => $datos['vigenteHasta'] ?? null,
        ]);
        return $this->porId($id);
    }

    private function foliosDeGrupo(string $grupoId): array
    {
        $stmt = $this->pdo->prepare('SELECT alumno_folio FROM grupo_alumnos WHERE grupo_id = ?');
        $stmt->execute([$grupoId]);
        return array_column($stmt->fetchAll(), 'alumno_folio');
    }

    private function miembrosDeGrupos(array $grupoIds): array
    {
        if (empty($grupoIds)) return [];
        $grupoIds = array_values(array_unique($grupoIds));
        $place = implode(',', array_fill(0, count($grupoIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT ga.grupo_id, ga.alumno_folio, a.nombre
             FROM grupo_alumnos ga JOIN alumnos a ON a.folio = ga.alumno_folio
             WHERE ga.grupo_id IN ($place) ORDER BY a.nombre"
        );
        $stmt->execute($grupoIds);
        $out = [];
        foreach ($stmt->fetchAll() as $m) {
            $out[$m['grupo_id']][] = ['folio' => $m['alumno_folio'], 'nombre' => $m['nombre']];
        }
        return $out;
    }

    private function mapear(array $r, array $miembros): array
    {
        return [
            'id'                  => $r['id'],
            'sucursalId'          => $r['sucursal_id'] ?? null,
            'instructorId'        => $r['instructor_id'],
            'instructorNombre'    => $r['instructor_nombre'] ?? null,
            'instructorIniciales' => $r['instructor_iniciales'] ?? null,
            'dia'                 => $r['dia'],
            'horaInicio'          => substr((string) $r['hora_inicio'], 0, 5),
            'duracionMin'         => (int) $r['duracion_min'],
            'modalidad'           => $r['modalidad'],
            'alumnoFolio'         => $r['alumno_folio'],
            'alumnoNombre'        => $r['alumno_nombre'] ?? null,
            'grupoId'             => $r['grupo_id'],
            'grupoNombre'         => $r['grupo_nombre'] ?? null,
            'grupoAlumnos'        => $r['grupo_id'] ? ($miembros[$r['grupo_id']] ?? []) : [],
            'vigenteDesde'        => $r['vigente_desde'],
            'vigenteHasta'        => $r['vigente_hasta'],
            'esReposicion'        => false,
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
