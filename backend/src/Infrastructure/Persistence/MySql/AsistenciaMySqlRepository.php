<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Asistencia\AsistenciaRepository;
use PDO;
use RuntimeException;

class AsistenciaMySqlRepository implements AsistenciaRepository
{
    private const MIN_ANTES_POR_DEFECTO = 30;
    private const RETARDO_POR_DEFECTO   = 20;

    public function __construct(private readonly PDO $pdo) {}

    public function minutosAntes(): int
    {
        return $this->parametro('asistencia_min_antes', self::MIN_ANTES_POR_DEFECTO);
    }

    public function retardoMaximo(): int
    {
        return $this->parametro('asistencia_retardo_max', self::RETARDO_POR_DEFECTO);
    }

    /**
     * Desde cuándo la escuela lleva control de asistencia.
     *
     * Sin este piso, abrir el reporte de un mes anterior cerraría esos días
     * y marcaría como faltistas a todos: inventaría historia que nunca se
     * registró. Vacío = sin piso.
     */
    public function controlDesde(): ?string
    {
        $st = $this->pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'asistencia_desde'");
        $st->execute();
        $v = $st->fetchColumn();
        return ($v !== false && trim((string) $v) !== '') ? (string) $v : null;
    }

    private function parametro(string $clave, int $porDefecto): int
    {
        $st = $this->pdo->prepare('SELECT valor FROM configuracion WHERE clave = ?');
        $st->execute([$clave]);
        $v = $st->fetchColumn();
        return $v !== false ? max(0, (int) $v) : $porDefecto;
    }

    // ── Las clases que tocaban ese día ─────────────────────────────────────

    public function clasesDelDia(string $sucursalId, string $fecha): array
    {
        $dia = $this->diaDeFecha($fecha);

        // 1. Horario recurrente vigente ese día (incluye clases muestra, que
        //    son reglas con vigencia de una sola fecha).
        $stmt = $this->pdo->prepare(
            "SELECT hr.id, hr.hora_inicio, hr.duracion_min, hr.modalidad, hr.es_muestra,
                    hr.alumno_folio, hr.grupo_id,
                    i.id AS instructor_id, i.nombre AS instructor_nombre, i.iniciales AS instructor_iniciales,
                    g.nombre AS grupo_nombre
             FROM horario_recurrente hr
             JOIN instructores i ON i.id = hr.instructor_id
             LEFT JOIN grupos g  ON g.id = hr.grupo_id
             WHERE hr.sucursal_id = :suc
               AND hr.dia = :dia
               AND hr.vigente_desde <= :fecha
               AND (hr.vigente_hasta IS NULL OR hr.vigente_hasta >= :fecha2)
             ORDER BY hr.hora_inicio"
        );
        $stmt->execute([':suc' => $sucursalId, ':dia' => $dia, ':fecha' => $fecha, ':fecha2' => $fecha]);
        $reglas = $stmt->fetchAll();

        $miembros = $this->miembrosDeGrupos(
            array_values(array_unique(array_filter(array_column($reglas, 'grupo_id'))))
        );

        $clases = [];

        foreach ($reglas as $r) {
            $hora = substr((string) $r['hora_inicio'], 0, 5);

            // Una regla de grupo se expande a cada miembro: la asistencia es
            // de la persona, no del grupo.
            $alumnos = $r['grupo_id']
                ? ($miembros[$r['grupo_id']] ?? [])
                : ($r['alumno_folio'] ? [['folio' => $r['alumno_folio'], 'nombre' => null, 'status' => null]] : []);

            foreach ($alumnos as $al) {
                $clases[] = [
                    'alumnoFolio'  => $al['folio'],
                    'horaClase'    => $hora,
                    'horarioId'    => $r['id'],
                    'reposicionId' => null,
                    'duracionMin'  => (int) $r['duracion_min'],
                    'modalidad'    => $r['modalidad'],
                    'esMuestra'    => (bool) $r['es_muestra'],
                    'esReposicion' => false,
                    'instructorId' => $r['instructor_id'],
                    'instructorNombre'    => $r['instructor_nombre'],
                    'instructorIniciales' => $r['instructor_iniciales'],
                    'grupoNombre'  => $r['grupo_nombre'],
                ];
            }
        }

        // 2. Reposiciones agendadas para esa fecha. Cuentan como clase: el
        //    alumno debe presentarse igual que a una normal.
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.alumno_folio, r.hora_inicio, r.duracion_min,
                    a.modalidad,
                    i.id AS instructor_id, i.nombre AS instructor_nombre, i.iniciales AS instructor_iniciales
             FROM reposiciones r
             JOIN alumnos a     ON a.folio = r.alumno_folio
             JOIN instructores i ON i.id   = r.instructor_id
             WHERE r.sucursal_id = :suc
               AND r.fecha = :fecha
               AND r.estado IN ('agendada', 'tomada')
             ORDER BY r.hora_inicio"
        );
        $stmt->execute([':suc' => $sucursalId, ':fecha' => $fecha]);

        foreach ($stmt->fetchAll() as $r) {
            $clases[] = [
                'alumnoFolio'  => $r['alumno_folio'],
                'horaClase'    => substr((string) $r['hora_inicio'], 0, 5),
                'horarioId'    => null,
                'reposicionId' => $r['id'],
                'duracionMin'  => (int) $r['duracion_min'],
                'modalidad'    => $r['modalidad'],
                'esMuestra'    => false,
                'esReposicion' => true,
                'instructorId' => $r['instructor_id'],
                'instructorNombre'    => $r['instructor_nombre'],
                'instructorIniciales' => $r['instructor_iniciales'],
                'grupoNombre'  => null,
            ];
        }

        if (!$clases) {
            return [];
        }

        // 3. Datos del alumno, lo ya registrado, y los avisos de falta.
        $folios = array_values(array_unique(array_column($clases, 'alumnoFolio')));
        $datos     = $this->datosDeAlumnos($folios);
        $registros = $this->registrosDe($fecha, $folios);
        $avisos    = $this->avisosDe($fecha, $folios);

        $salida = [];
        foreach ($clases as $c) {
            $folio = $c['alumnoFolio'];
            $alumno = $datos[$folio] ?? null;

            // Un alumno dado de baja ya no debe generar faltas
            if (!$alumno || $alumno['status'] === 'baja') {
                continue;
            }

            $clave    = $folio . '|' . $c['horaClase'] . ':00';
            $registro = $registros[$clave] ?? null;
            $aviso    = $avisos[$folio . '|' . $c['horaClase'] . ':00'] ?? null;

            $salida[] = $c + [
                'alumnoNombre'   => $alumno['nombre'],
                'alumnoTelefono' => $alumno['telefono'],
                'alumnoStatus'   => $alumno['status'],
                // Sin registro el estado queda en null: "todavía no se sabe".
                // El cierre del día es quien lo convierte en 'no_asistio'.
                'estado'         => $registro['estado'] ?? null,
                'metodo'         => $registro['metodo'] ?? null,
                'horaRegistro'   => $registro['hora']   ?? null,
                // Avisó que no venía: no es lo mismo que no presentarse
                'aviso'          => $aviso,
            ];
        }

        usort($salida, fn($a, $b) => [$a['horaClase'], $a['alumnoNombre']] <=> [$b['horaClase'], $b['alumnoNombre']]);
        return $salida;
    }

    // ── Registrar llegada ──────────────────────────────────────────────────

    public function registrar(string $alumnoFolio, ?string $sucursalId, ?string $usuarioId, string $metodo): array
    {
        $alumno = $this->alumno($alumnoFolio);
        if (!$alumno) {
            throw new RuntimeException('Alumno no encontrado', 404);
        }
        if ($alumno['status'] === 'baja') {
            throw new RuntimeException('El alumno está dado de baja', 409);
        }

        $hoy    = date('Y-m-d');
        $suyas  = $sucursalId ? $this->clasesDeHoy($alumnoFolio, $sucursalId, $hoy) : [];
        $clase  = $this->claseEnVentana($suyas, $hoy);

        // Llegar fuera de la ventana de su clase no es asistir: no se
        // registra nada. Antes se guardaba igual con un aviso, y eso dejaba
        // asistencias que no correspondían a ninguna clase.
        if (!$clase) {
            throw new RuntimeException($this->porQueNoAplica($alumno, $suyas, $hoy), 409);
        }

        $horaClase = $clase['horaClase'] . ':00';

        // ¿Ya estaba registrado en esa clase?
        $ya = $this->pdo->prepare(
            'SELECT id, hora, estado FROM asistencias
             WHERE alumno_folio = ? AND fecha = ? AND hora_clase = ?'
        );
        $ya->execute([$alumnoFolio, $hoy, $horaClase]);
        $existente = $ya->fetch();

        if ($existente && $existente['estado'] === 'asistio') {
            return $this->respuesta($alumno, $alumnoFolio, $clase, $existente['hora'], true);
        }

        if ($existente) {
            // Estaba como falta (por cierre automático o marcado a mano) y
            // ahora sí llegó: se corrige.
            $this->pdo->prepare(
                "UPDATE asistencias
                    SET estado = 'asistio', metodo = :metodo, hora = CURRENT_TIMESTAMP,
                        registrado_por = :usr
                  WHERE id = :id"
            )->execute([
                ':metodo' => $this->metodoValido($metodo),
                ':usr'    => $usuarioId,
                ':id'     => $existente['id'],
            ]);
            return $this->respuesta($alumno, $alumnoFolio, $clase, date('Y-m-d H:i:s'), false);
        }

        $this->pdo->prepare(
            "INSERT INTO asistencias
                (id, alumno_folio, fecha, hora_clase, horario_id, reposicion_id,
                 estado, sucursal_id, registrado_por, metodo)
             VALUES (UUID(), :folio, :fecha, :hora, :hid, :rid,
                     'asistio', :suc, :usr, :metodo)"
        )->execute([
            ':folio'  => $alumnoFolio,
            ':fecha'  => $hoy,
            ':hora'   => $horaClase,
            ':hid'    => $clase['horarioId']    ?? null,
            ':rid'    => $clase['reposicionId'] ?? null,
            ':suc'    => $sucursalId,
            ':usr'    => $usuarioId,
            ':metodo' => $this->metodoValido($metodo),
        ]);

        return $this->respuesta($alumno, $alumnoFolio, $clase, date('Y-m-d H:i:s'), false);
    }

    private function respuesta(array $alumno, string $folio, array $clase, ?string $hora, bool $ya): array
    {
        return [
            'alumnoFolio'  => $folio,
            'alumnoNombre' => $alumno['nombre'],
            'modalidad'    => $alumno['modalidad'],
            'hora'         => $hora,
            'yaRegistrado' => $ya,
            'clase'        => [
                'horaClase'        => $clase['horaClase'],
                'instructorNombre' => $clase['instructorNombre'],
                'esReposicion'     => $clase['esReposicion'],
                'esMuestra'        => $clase['esMuestra'],
            ],
        ];
    }

    // ── Marcar a mano ──────────────────────────────────────────────────────

    public function marcar(
        string $alumnoFolio,
        string $fecha,
        string $horaClase,
        string $estado,
        ?string $sucursalId,
        ?string $usuarioId
    ): array {
        if (!in_array($estado, ['asistio', 'no_asistio'], true)) {
            throw new RuntimeException('Estado inválido', 422);
        }
        if (!$this->alumno($alumnoFolio)) {
            throw new RuntimeException('Alumno no encontrado', 404);
        }

        $hora = strlen($horaClase) === 5 ? $horaClase . ':00' : $horaClase;

        // Se busca a qué clase corresponde para dejar la referencia guardada
        $clase = $sucursalId ? $this->claseEnHora($alumnoFolio, $sucursalId, $fecha, substr($hora, 0, 5)) : null;

        $this->pdo->prepare(
            "INSERT INTO asistencias
                (id, alumno_folio, fecha, hora_clase, horario_id, reposicion_id,
                 estado, sucursal_id, registrado_por, metodo)
             VALUES (UUID(), :folio, :fecha, :hora, :hid, :rid, :estado, :suc, :usr, 'manual')
             ON DUPLICATE KEY UPDATE
                estado = VALUES(estado),
                metodo = 'manual',
                registrado_por = VALUES(registrado_por),
                hora = CURRENT_TIMESTAMP"
        )->execute([
            ':folio'  => $alumnoFolio,
            ':fecha'  => $fecha,
            ':hora'   => $hora,
            ':hid'    => $clase['horarioId']    ?? null,
            ':rid'    => $clase['reposicionId'] ?? null,
            ':estado' => $estado,
            ':suc'    => $sucursalId,
            ':usr'    => $usuarioId,
        ]);

        return [
            'alumnoFolio' => $alumnoFolio,
            'fecha'       => $fecha,
            'horaClase'   => substr($hora, 0, 5),
            'estado'      => $estado,
        ];
    }

    // ── Cierre del día ─────────────────────────────────────────────────────

    public function cierreDe(string $sucursalId, string $fecha): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha, cerrado_en, clases, asistieron, faltaron
             FROM asistencia_cierres WHERE sucursal_id = ? AND fecha = ?'
        );
        $stmt->execute([$sucursalId, $fecha]);
        $r = $stmt->fetch();
        return $r ? [
            'fecha'      => $r['fecha'],
            'cerradoEn'  => $r['cerrado_en'],
            'clases'     => (int) $r['clases'],
            'asistieron' => (int) $r['asistieron'],
            'faltaron'   => (int) $r['faltaron'],
        ] : null;
    }

    public function cerrarDia(string $sucursalId, string $fecha): array
    {
        // Un día que no ha terminado no se cierra: todavía puede llegar gente.
        if ($fecha >= date('Y-m-d')) {
            throw new RuntimeException('Solo se pueden cerrar días que ya terminaron', 409);
        }

        // Ni días anteriores a que la escuela empezara a llevar control: no
        // hubo registro, y marcarlos como faltas sería inventar historia.
        $desde = $this->controlDesde();
        if ($desde !== null && $fecha < $desde) {
            throw new RuntimeException(
                "El control de asistencia empieza el $desde; los días anteriores no se cierran",
                409
            );
        }

        if ($ya = $this->cierreDe($sucursalId, $fecha)) {
            return $ya + ['yaEstaba' => true];
        }

        $clases     = $this->clasesDelDia($sucursalId, $fecha);
        $faltaron   = 0;
        $asistieron = 0;

        // Las faltas se insertan de un solo golpe. Una sentencia por alumno
        // significaba cientos de viajes a la base, y con la base en otro
        // servidor eso tardaba más de lo que dura la petición.
        $filas  = [];
        $params = [];

        foreach ($clases as $c) {
            if ($c['estado'] === 'asistio')    { $asistieron++; continue; }
            if ($c['estado'] === 'no_asistio') { $faltaron++;   continue; }

            $filas[]  = "(UUID(), ?, ?, ?, ?, ?, 'no_asistio', ?, NULL, 'auto')";
            $params[] = $c['alumnoFolio'];
            $params[] = $fecha;
            $params[] = $c['horaClase'] . ':00';
            $params[] = $c['horarioId'];
            $params[] = $c['reposicionId'];
            $params[] = $sucursalId;
            $faltaron++;
        }

        $this->pdo->beginTransaction();
        try {
            // En lotes, para no armar una sentencia descomunal un día cargado
            foreach (array_chunk($filas, 200, true) as $lote) {
                $desde = array_key_first($lote) * 6;
                $this->pdo->prepare(
                    'INSERT INTO asistencias
                        (id, alumno_folio, fecha, hora_clase, horario_id, reposicion_id,
                         estado, sucursal_id, registrado_por, metodo)
                     VALUES ' . implode(',', $lote) . '
                     ON DUPLICATE KEY UPDATE id = id'
                )->execute(array_slice($params, $desde, count($lote) * 6));
            }

            $this->pdo->prepare(
                'INSERT INTO asistencia_cierres (sucursal_id, fecha, clases, asistieron, faltaron)
                 VALUES (:suc, :fecha, :clases, :asis, :faltas)
                 ON DUPLICATE KEY UPDATE clases = VALUES(clases),
                                         asistieron = VALUES(asistieron),
                                         faltaron = VALUES(faltaron)'
            )->execute([
                ':suc'    => $sucursalId,
                ':fecha'  => $fecha,
                ':clases' => count($clases),
                ':asis'   => $asistieron,
                ':faltas' => $faltaron,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // Se relee lo guardado en vez de devolver una hora calculada aquí:
        // la marca de tiempo la pone MySQL, y las dos no tienen por qué
        // coincidir. Así lo que responde el API es lo que de verdad quedó.
        return ($this->cierreDe($sucursalId, $fecha) ?? [
            'fecha'      => $fecha,
            'cerradoEn'  => null,
            'clases'     => count($clases),
            'asistieron' => $asistieron,
            'faltaron'   => $faltaron,
        ]) + ['yaEstaba' => false];
    }

    // ── Listado plano de registros ─────────────────────────────────────────

    public function listar(?string $sucursalId, string $fecha): array
    {
        $sql = "SELECT a.id, a.alumno_folio, a.fecha, a.hora_clase, a.hora, a.metodo, a.estado,
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
            'horaClase'    => substr((string) $r['hora_clase'], 0, 5),
            'hora'         => $r['hora'],
            'metodo'       => $r['metodo'],
            'estado'       => $r['estado'],
        ], $stmt->fetchAll());
    }

    // ── Apoyos ─────────────────────────────────────────────────────────────

    /** Las clases que ese alumno tiene hoy, ordenadas por hora. */
    private function clasesDeHoy(string $folio, string $sucursalId, string $fecha): array
    {
        return array_values(array_filter(
            $this->clasesDelDia($sucursalId, $fecha),
            fn($c) => $c['alumnoFolio'] === $folio
        ));
    }

    /**
     * La clase cuya ventana contiene este momento.
     *
     * La ventana va desde unos minutos antes de que empiece hasta cierto
     * retardo después de la hora de inicio: llegar a media clase sigue
     * contando, pero llegar cuando ya casi termina no. Si el alumno tiene
     * dos clases solapadas, gana la que empieza más cerca de ahora.
     */
    private function claseEnVentana(array $suyas, string $fecha): ?array
    {
        if (!$suyas) return null;

        $ahora   = time();
        $antes   = $this->minutosAntes()   * 60;
        $retardo = $this->retardoMaximo()  * 60;

        $mejor = null;
        $mejorDist = PHP_INT_MAX;

        foreach ($suyas as $c) {
            $inicio = strtotime("$fecha {$c['horaClase']}");

            if ($ahora < $inicio - $antes || $ahora > $inicio + $retardo) {
                continue;
            }
            $dist = abs($inicio - $ahora);
            if ($dist < $mejorDist) { $mejorDist = $dist; $mejor = $c; }
        }

        return $mejor;
    }

    /** Mensaje que explica al de recepción por qué no se registró. */
    private function porQueNoAplica(array $alumno, array $suyas, string $fecha): string
    {
        $nombre = $alumno['nombre'];

        if (!$suyas) {
            return "$nombre no tiene clases programadas hoy.";
        }

        $ahora = time();
        $proxima = null;
        foreach ($suyas as $c) {
            if (strtotime("$fecha {$c['horaClase']}") > $ahora) { $proxima = $c; break; }
        }

        if ($proxima) {
            $min = $this->minutosAntes();
            return "$nombre todavía no puede registrarse. Su clase de hoy es a las "
                 . "{$proxima['horaClase']}; puede llegar desde $min minutos antes.";
        }

        $ultima  = end($suyas);
        $retardo = $this->retardoMaximo();
        return "$nombre llegó demasiado tarde: su clase era a las {$ultima['horaClase']} "
             . "y solo se puede registrar hasta $retardo minutos después.";
    }

    private function claseEnHora(string $folio, string $sucursalId, string $fecha, string $hora): ?array
    {
        foreach ($this->clasesDelDia($sucursalId, $fecha) as $c) {
            if ($c['alumnoFolio'] === $folio && $c['horaClase'] === $hora) {
                return $c;
            }
        }
        return null;
    }

    private function alumno(string $folio): ?array
    {
        $stmt = $this->pdo->prepare('SELECT nombre, modalidad, status, telefono FROM alumnos WHERE folio = ?');
        $stmt->execute([$folio]);
        return $stmt->fetch() ?: null;
    }

    /** @param string[] $folios */
    private function datosDeAlumnos(array $folios): array
    {
        if (!$folios) return [];
        $place = implode(',', array_fill(0, count($folios), '?'));
        $stmt  = $this->pdo->prepare("SELECT folio, nombre, status, telefono FROM alumnos WHERE folio IN ($place)");
        $stmt->execute($folios);
        $out = [];
        foreach ($stmt->fetchAll() as $r) $out[$r['folio']] = $r;
        return $out;
    }

    /** Registros de ese día indexados por folio|hora_clase */
    private function registrosDe(string $fecha, array $folios): array
    {
        if (!$folios) return [];
        $place = implode(',', array_fill(0, count($folios), '?'));
        $stmt  = $this->pdo->prepare(
            "SELECT alumno_folio, hora_clase, estado, metodo, hora
             FROM asistencias WHERE fecha = ? AND alumno_folio IN ($place)"
        );
        $stmt->execute([$fecha, ...$folios]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['alumno_folio'] . '|' . $r['hora_clase']] = $r;
        }
        return $out;
    }

    /** Avisos de «no viene» de ese día, indexados por folio|hora_clase */
    private function avisosDe(string $fecha, array $folios): array
    {
        if (!$folios) return [];
        $place = implode(',', array_fill(0, count($folios), '?'));
        $stmt  = $this->pdo->prepare(
            "SELECT alumno_folio, hora_clase, con_derecho, motivo
             FROM ausencias WHERE fecha = ? AND alumno_folio IN ($place)"
        );
        $stmt->execute([$fecha, ...$folios]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['alumno_folio'] . '|' . $r['hora_clase']] = [
                'conDerecho' => (bool) $r['con_derecho'],
                'motivo'     => $r['motivo'],
            ];
        }
        return $out;
    }

    private function miembrosDeGrupos(array $grupoIds): array
    {
        if (!$grupoIds) return [];
        $place = implode(',', array_fill(0, count($grupoIds), '?'));
        $stmt  = $this->pdo->prepare(
            "SELECT ga.grupo_id, ga.alumno_folio, a.nombre, a.status
             FROM grupo_alumnos ga JOIN alumnos a ON a.folio = ga.alumno_folio
             WHERE ga.grupo_id IN ($place)"
        );
        $stmt->execute($grupoIds);
        $out = [];
        foreach ($stmt->fetchAll() as $m) {
            $out[$m['grupo_id']][] = [
                'folio'  => $m['alumno_folio'],
                'nombre' => $m['nombre'],
                'status' => $m['status'],
            ];
        }
        return $out;
    }

    private function metodoValido(string $metodo): string
    {
        return in_array($metodo, ['qr', 'manual', 'auto'], true) ? $metodo : 'qr';
    }

    private function diaDeFecha(string $fecha): string
    {
        $n = (int) date('N', strtotime($fecha));
        return ['L', 'M', 'X', 'J', 'V', 'S', 'D'][$n - 1] ?? 'L';
    }
}
