<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Horario\HorarioRepository;
use Beluga\Domain\Instructor\InstructorRepository;
use PDO;
use RuntimeException;

class HorarioMySqlRepository implements HorarioRepository
{
    private const INFINITO = '9999-12-31';

    public function __construct(
        private readonly PDO $pdo,
        private readonly InstructorRepository $instructores
    ) {}

    // ── Pizarrón de la semana ──────────────────────────────────────────────
    public function pizarron(string $sucursalId, string $lunes): array
    {
        $sabado = date('Y-m-d', strtotime($lunes . ' +5 days'));

        $stmt = $this->pdo->prepare(
            "SELECT hr.id, hr.instructor_id, hr.dia, hr.hora_inicio, hr.duracion_min,
                    hr.modalidad, hr.es_muestra, hr.alumno_folio, hr.grupo_id,
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
                 modalidad, es_muestra, alumno_folio, programa_id, grupo_id, vigente_desde, vigente_hasta)
             VALUES (:id,:suc,:inst,:dia,:hi,:dur,:mod,:muestra,:folio,:pid,:grupo,:vd,:vh)'
        )->execute([
            ':id'      => $id,
            ':suc'     => $datos['sucursalId'],
            ':inst'    => $datos['instructorId'],
            ':dia'     => $datos['dia'],
            ':hi'      => $datos['horaInicio'],
            ':dur'     => $datos['duracionMin'] ?? 30,
            ':mod'     => $datos['modalidad'],
            ':muestra' => !empty($datos['esMuestra']) ? 1 : 0,
            ':folio'   => $datos['alumnoFolio'] ?? null,
            ':pid'     => $this->programaIdDe($datos['alumnoFolio'] ?? null, $datos['modalidad']),
            ':grupo'   => $datos['grupoId'] ?? null,
            ':vd'      => $datos['vigenteDesde'],
            ':vh'      => $datos['vigenteHasta'] ?? null,
        ]);

        // Deja constancia en el alumno de cuándo vino a probar. El reporte de
        // prealumnos mide con este dato cuántos terminaron inscribiéndose, así
        // que tiene que sobrevivir a que el bloque se borre o el alumno pase a
        // activo. Si ya probó antes, se conserva la primera vez.
        if (!empty($datos['esMuestra']) && !empty($datos['alumnoFolio'])) {
            $this->pdo->prepare(
                'UPDATE alumnos
                    SET fecha_clase_muestra = LEAST(COALESCE(fecha_clase_muestra, :f1), :f2)
                  WHERE folio = :folio'
            )->execute([
                ':f1'    => $datos['vigenteDesde'],
                ':f2'    => $datos['vigenteDesde'],
                ':folio' => $datos['alumnoFolio'],
            ]);
        }

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
            // Se arrastra: si no, al mover una clase muestra perdería la marca
            // y la validación grupal la rechazaría.
            'esMuestra'    => $regla['esMuestra'] ?? false,
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

    // ── Liberar lugares al dar de baja ───────────────────────────────────────

    public function liberarDeAlumno(string $folio, string $desde, ?string $programaId = null): int
    {
        $filtro = $programaId ? 'AND programa_id = :pid' : '';
        $fin    = date('Y-m-d', strtotime($desde . ' -1 day'));

        // Las que aún no habían empezado no dejan historial que valga la pena:
        // se borran para no dejar reglas fantasma con vigencia invertida.
        $sqlBorrar = "DELETE FROM horario_recurrente
                      WHERE alumno_folio = :folio AND vigente_desde >= :desde $filtro";
        $stmt = $this->pdo->prepare($sqlBorrar);
        $stmt->bindValue(':folio', $folio);
        $stmt->bindValue(':desde', $desde);
        if ($programaId) $stmt->bindValue(':pid', $programaId);
        $stmt->execute();
        $afectadas = $stmt->rowCount();

        // Las vigentes se cierran el día anterior: conservan el historial
        $sqlCerrar = "UPDATE horario_recurrente
                      SET vigente_hasta = :fin
                      WHERE alumno_folio = :folio
                        AND vigente_desde < :desde
                        AND (vigente_hasta IS NULL OR vigente_hasta > :fin2)
                        $filtro";
        $stmt = $this->pdo->prepare($sqlCerrar);
        $stmt->bindValue(':fin', $fin);
        $stmt->bindValue(':fin2', $fin);
        $stmt->bindValue(':folio', $folio);
        $stmt->bindValue(':desde', $desde);
        if ($programaId) $stmt->bindValue(':pid', $programaId);
        $stmt->execute();

        return $afectadas + $stmt->rowCount();
    }

    public function quitarDeGrupos(string $folio, ?string $modalidad = null): int
    {
        if ($modalidad === null) {
            $stmt = $this->pdo->prepare('DELETE FROM grupo_alumnos WHERE alumno_folio = ?');
            $stmt->execute([$folio]);
            return $stmt->rowCount();
        }

        // Las clases de grupo no llevan programa_id (van por grupo_id), así que
        // el vínculo con la modalidad se resuelve por las reglas del grupo.
        $stmt = $this->pdo->prepare(
            'DELETE ga FROM grupo_alumnos ga
             WHERE ga.alumno_folio = :folio
               AND EXISTS (SELECT 1 FROM horario_recurrente hr
                            WHERE hr.grupo_id = ga.grupo_id AND hr.modalidad = :mod)'
        );
        $stmt->execute([':folio' => $folio, ':mod' => $modalidad]);
        return $stmt->rowCount();
    }

    // ── Validación de conflictos (dura) ──────────────────────────────────────
    private function validar(array $d, ?string $excludeId): void
    {
        // Una clase muestra es un visitante en una celda libre por una sola
        // fecha: no se integra al grupo, así que va por alumno aunque la
        // modalidad que viene a probar sea grupal.
        $esMuestra = !empty($d['esMuestra']);
        $esGrupal  = !$esMuestra && in_array($d['modalidad'], ['GRUPAL', 'MINI'], true);

        if ($esGrupal && empty($d['grupoId'])) {
            throw new RuntimeException('Una clase de grupo requiere un grupo', 422);
        }
        if (!$esGrupal && empty($d['alumnoFolio'])) {
            throw new RuntimeException('Selecciona un alumno', 422);
        }

        $vd = $d['vigenteDesde'];
        $vh = $d['vigenteHasta'] ?? null;
        $exclude = $excludeId ?? '';

        // 0. El instructor debe trabajar ese día y a esa hora
        $this->instructores->disponibleEn($d['instructorId'], $d['dia'], $d['horaInicio'], $d['duracionMin'] ?? 30);

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

        // 3. Ningún alumno puede superar las clases/semana de su plan EN ESTA MODALIDAD
        $this->validarLimiteSemanal($folios, $vd, $vh, $exclude, $d['modalidad']);
    }

    /**
     * Bloquea si algún alumno excedería su plan (clases por semana).
     * Cuenta las reglas vigentes en el mismo periodo donde el alumno participa
     * (directo o como miembro de un grupo), en cualquier día/hora.
     */
    private function validarLimiteSemanal(array $folios, string $vd, ?string $vh, string $excludeId, string $modalidad): void
    {
        if (empty($folios)) return;
        $vhEff = $vh ?? self::INFINITO;

        // Límite = clases/semana del PROGRAMA de esa modalidad (no se mezclan modalidades)
        $place = implode(',', array_fill(0, count($folios), '?'));
        $pStmt = $this->pdo->prepare(
            "SELECT pr.alumno_folio AS folio, a.nombre, pr.clases_semana
             FROM alumno_programas pr
             JOIN alumnos a ON a.folio = pr.alumno_folio
             WHERE pr.alumno_folio IN ($place) AND pr.modalidad = ? AND pr.status = 'activo'"
        );
        $pStmt->execute([...array_values($folios), $modalidad]);
        $planes = [];
        foreach ($pStmt->fetchAll() as $r) {
            $planes[$r['folio']] = ['n' => (int) $r['clases_semana'], 'nombre' => $r['nombre']];
        }

        // Cuenta solo las clases de ESTA modalidad en el periodo vigente
        $cnt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM horario_recurrente hr
             LEFT JOIN grupo_alumnos ga ON ga.grupo_id = hr.grupo_id
             WHERE hr.id <> :ex
               AND hr.modalidad = :mod
               AND hr.vigente_desde <= :vh
               AND COALESCE(hr.vigente_hasta, :inf) >= :vd
               AND (hr.alumno_folio = :f1 OR ga.alumno_folio = :f2)"
        );

        foreach ($folios as $folio) {
            $limite = $planes[$folio]['n'] ?? 0;
            if ($limite <= 0) continue;  // sin programa de esa modalidad: no se limita

            $cnt->execute([
                ':ex' => $excludeId, ':mod' => $modalidad, ':vh' => $vhEff, ':inf' => self::INFINITO, ':vd' => $vd,
                ':f1' => $folio, ':f2' => $folio,
            ]);
            if ((int) $cnt->fetchColumn() + 1 > $limite) {
                $nombre = $planes[$folio]['nombre'] ?? $folio;
                $suf    = $limite === 1 ? 'clase' : 'clases';
                throw new RuntimeException(
                    "$nombre ya tiene sus $limite $suf de $modalidad en la semana (plan de $limite/sem). Sube su plan para asignar más.",
                    409
                );
            }
        }
    }

    /** Resuelve el programa activo de un alumno para una modalidad (o null). */
    private function programaIdDe(?string $folio, string $modalidad): ?string
    {
        if (!$folio) return null;
        $stmt = $this->pdo->prepare(
            "SELECT id FROM alumno_programas
             WHERE alumno_folio = ? AND modalidad = ? AND status = 'activo'
             ORDER BY fecha_inicio ASC LIMIT 1"
        );
        $stmt->execute([$folio, $modalidad]);
        $id = $stmt->fetchColumn();
        return $id ?: null;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    private function crearSinValidar(array $datos): array
    {
        $id = $this->uuid();
        $this->pdo->prepare(
            'INSERT INTO horario_recurrente
                (id, sucursal_id, instructor_id, dia, hora_inicio, duracion_min,
                 modalidad, es_muestra, alumno_folio, programa_id, grupo_id, vigente_desde, vigente_hasta)
             VALUES (:id,:suc,:inst,:dia,:hi,:dur,:mod,:muestra,:folio,:pid,:grupo,:vd,:vh)'
        )->execute([
            ':id' => $id, ':suc' => $datos['sucursalId'], ':inst' => $datos['instructorId'],
            ':dia' => $datos['dia'], ':hi' => $datos['horaInicio'], ':dur' => $datos['duracionMin'] ?? 30,
            ':mod' => $datos['modalidad'],
            ':muestra' => !empty($datos['esMuestra']) ? 1 : 0,
            ':folio' => $datos['alumnoFolio'] ?? null,
            ':pid' => $this->programaIdDe($datos['alumnoFolio'] ?? null, $datos['modalidad']),
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
            // Clase de prueba de un prealumno: ocupa la celda una sola fecha
            'esMuestra'           => (bool) ($r['es_muestra'] ?? false),
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
