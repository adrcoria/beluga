<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Alumno\AlumnoRepository;
use Beluga\Domain\Horario\HorarioRepository;
use PDO;
use RuntimeException;

class AlumnoMySqlRepository implements AlumnoRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly HorarioRepository $horarios
    ) {}

    // ── Listar con filtros ────────────────────────────────────────────────

    public function listar(array $filtros = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['busqueda'])) {
            $where[]       = '(a.nombre LIKE :q1 OR a.folio LIKE :q2)';
            $q             = '%' . $filtros['busqueda'] . '%';
            $params[':q1'] = $q;
            $params[':q2'] = $q;
        }
        if (!empty($filtros['status'])) {
            $where[]           = 'a.status = :status';
            $params[':status'] = $filtros['status'];
        }
        if (!empty($filtros['sucursal'])) {
            $where[]             = 'a.sucursal_id = :sucursal';
            $params[':sucursal'] = $filtros['sucursal'];
        }
        if (!empty($filtros['modalidad'])) {
            $where[] = "EXISTS (SELECT 1 FROM alumno_programas pp
                                WHERE pp.alumno_folio = a.folio
                                  AND pp.modalidad = :modalidad AND pp.status = 'activo')";
            $params[':modalidad'] = $filtros['modalidad'];
        }
        if (!empty($filtros['toca_pago'])) {
            $where[] = "EXISTS (SELECT 1 FROM alumno_programas pp
                                WHERE pp.alumno_folio = a.folio AND pp.status = 'activo'
                                  AND pp.vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY))";
        }

        $sql = 'SELECT ' . $this->columnasPersona('a') . '
                FROM alumnos a
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY a.nombre';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $alumnos = $stmt->fetchAll();
        if (!$alumnos) return [];

        $programas = $this->programasDe(array_column($alumnos, 'folio'));
        return array_map(fn($a) => $this->mapear($a, $programas[$a['folio']] ?? []), $alumnos);
    }

    // ── Por folio ─────────────────────────────────────────────────────────

    public function porFolio(string $folio): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->columnasPersona('a') . ' FROM alumnos a WHERE a.folio = ?'
        );
        $stmt->execute([$folio]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $programas = $this->programasDe([$folio]);
        return $this->mapear($row, $programas[$folio] ?? []);
    }

    // ── Crear (persona + primer programa) ──────────────────────────────────

    public function crear(array $datos): array
    {
        $folio       = $this->siguienteFolio();
        $fechaInicio = $datos['fechaInicio'];
        $diaPago     = (int) date('j', strtotime($fechaInicio));
        // La primera mensualidad se debe desde la fecha de inicio (no se regala el 1er mes).
        $vencimiento = $fechaInicio;

        $contacto       = $datos['contacto']       ?? [];
        $plan           = $datos['plan']           ?? [];
        $descuento      = $datos['descuento']      ?? null;
        $disponibilidad = $datos['disponibilidad'] ?? [];

        $this->pdo->beginTransaction();
        try {
            // alumnos conserva un snapshot del plan principal (columnas NOT NULL legacy)
            $this->pdo->prepare(
                'INSERT INTO alumnos (
                    folio, nombre, fecha_nacimiento, foto, tutor, telefono, email,
                    modalidad, clases_semana, mensualidad, fecha_inicio, dia_pago, vencimiento,
                    inscripcion_pagada, descuento_pct, descuento_motivo,
                    disp_dias, disp_hora_inicio, disp_hora_fin, status, sucursal_id, notas
                 ) VALUES (
                    :folio, :nombre, :fecha_nacimiento, :foto, :tutor, :telefono, :email,
                    :modalidad, :clases_semana, :mensualidad, :fecha_inicio, :dia_pago, :vencimiento,
                    :inscripcion_pagada, :descuento_pct, :descuento_motivo,
                    :disp_dias, :disp_hora_inicio, :disp_hora_fin, :status, :sucursal_id, :notas
                 )'
            )->execute([
                ':folio'              => $folio,
                ':nombre'             => $datos['nombre'],
                ':fecha_nacimiento'   => $datos['fechaNacimiento'] ?? null,
                ':foto'               => $datos['foto'] ?? null,
                ':tutor'              => $contacto['tutor'] ?? null,
                ':telefono'           => $contacto['telefono'] ?? '',
                ':email'              => $contacto['email'] ?? null,
                ':modalidad'          => $datos['modalidad'],
                ':clases_semana'      => $plan['clasesSemana'] ?? 1,
                ':mensualidad'        => $plan['mensualidad']  ?? 0,
                ':fecha_inicio'       => $fechaInicio,
                ':dia_pago'           => $diaPago,
                ':vencimiento'        => $vencimiento,
                ':inscripcion_pagada' => (int) ($datos['inscripcionPagada'] ?? false),
                ':descuento_pct'      => $descuento['pct']    ?? null,
                ':descuento_motivo'   => $descuento['motivo'] ?? null,
                ':disp_dias'          => json_encode($disponibilidad['dias'] ?? []),
                ':disp_hora_inicio'   => $disponibilidad['horaInicio'] ?? null,
                ':disp_hora_fin'      => $disponibilidad['horaFin']    ?? null,
                ':status'             => $datos['status'] ?? 'activo',
                ':sucursal_id'        => $datos['sucursalId'],
                // Para un prealumno esto es de dónde llegó y qué se le ofreció
                ':notas'              => $datos['notas'] ?? null,
            ]);

            $this->insertarPrograma($folio, $datos['modalidad'], $plan, $descuento, $fechaInicio, $diaPago, $vencimiento);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->porFolio($folio);
    }

    // ── Actualizar datos de persona ────────────────────────────────────────
    // (el plan/modalidad/descuento ahora se editan por programa)

    public function actualizar(string $folio, array $datos): ?array
    {
        $set    = [];
        $params = [':folio' => $folio];

        $mapa = [
            'nombre'          => 'nombre',
            'fechaNacimiento' => 'fecha_nacimiento',
            'foto'            => 'foto',
            'status'          => 'status',
            // Prealumnos: de dónde llegó, qué se le ofreció, y cuándo tomó
            // su clase de prueba. Alimentan el reporte de seguimiento.
            'notas'             => 'notas',
            'fechaClaseMuestra' => 'fecha_clase_muestra',
        ];
        foreach ($mapa as $campo => $col) {
            if (array_key_exists($campo, $datos)) {
                $set[]           = "$col = :$col";
                $params[":$col"] = $datos[$campo];
            }
        }

        if (isset($datos['contacto'])) {
            foreach (['tutor', 'telefono', 'email'] as $c) {
                if (array_key_exists($c, $datos['contacto'])) {
                    $set[]         = "$c = :$c";
                    $params[":$c"] = $datos['contacto'][$c];
                }
            }
        }

        if (isset($datos['disponibilidad'])) {
            $disp = $datos['disponibilidad'];
            if (isset($disp['dias']))       { $set[] = 'disp_dias = :disp_dias';               $params[':disp_dias']        = json_encode($disp['dias']); }
            if (isset($disp['horaInicio'])) { $set[] = 'disp_hora_inicio = :disp_hora_inicio'; $params[':disp_hora_inicio'] = $disp['horaInicio']; }
            if (isset($disp['horaFin']))    { $set[] = 'disp_hora_fin = :disp_hora_fin';       $params[':disp_hora_fin']    = $disp['horaFin']; }
        }

        // Compatibilidad: si todavía mandan plan/modalidad/descuento, actualizan el programa principal
        if (isset($datos['modalidad']) || isset($datos['plan']) || isset($datos['descuento']) || array_key_exists('fechaInicio', $datos)) {
            $primarioId = $this->programaPrincipalId($folio);
            if ($primarioId) {
                $this->actualizarPrograma($primarioId, $datos);
            }
        }

        if (!empty($set)) {
            $this->pdo->prepare('UPDATE alumnos SET ' . implode(', ', $set) . ' WHERE folio = :folio')->execute($params);
        }

        // Baja del alumno completo: se va de todos lados. Los programas también
        // se marcan de baja para que dejen de generar cobros; si solo se tocara
        // `alumnos.status`, la generación mensual seguiría corriendo por ellos.
        if (($datos['status'] ?? null) === 'baja') {
            $this->liberarTodo($folio);
        }

        return $this->porFolio($folio);
    }

    /**
     * Suelta todo lo que el alumno ocupaba: sus clases del pizarrón desde hoy,
     * sus grupos, sus reposiciones por usar y sus programas.
     * Baja lógica en todos los casos: no se borra ni un registro histórico.
     */
    private function liberarTodo(string $folio): void
    {
        $hoy = date('Y-m-d');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE alumno_programas SET status = 'baja' WHERE alumno_folio = ? AND status = 'activo'")
                      ->execute([$folio]);
            $this->horarios->liberarDeAlumno($folio, $hoy);
            $this->horarios->quitarDeGrupos($folio);
            $this->cancelarReposiciones($folio);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function eliminar(string $folio): void
    {
        // alumno_programas y cobros caen por FK ON DELETE CASCADE / limpieza previa
        $this->pdo->prepare('DELETE FROM alumnos WHERE folio = ?')->execute([$folio]);
    }

    // ── Programas (multimodalidad) ─────────────────────────────────────────

    /** Crea un programa adicional para un alumno. Devuelve el programa creado (mapeado). */
    public function crearPrograma(string $folio, array $datos): array
    {
        $fechaInicio = $datos['fechaInicio'] ?? date('Y-m-d');
        $diaPago     = (int) date('j', strtotime($fechaInicio));
        $id = $this->insertarPrograma(
            $folio,
            $datos['modalidad'],
            $datos['plan'] ?? [],
            $datos['descuento'] ?? null,
            $fechaInicio,
            $diaPago,
            $fechaInicio   // vencimiento = inicio (primera mensualidad cobrable de inmediato)
        );
        return $this->programaPorId($id);
    }

    /** Actualiza modalidad/plan/descuento/fechaInicio de un programa. */
    public function actualizarPrograma(string $programaId, array $datos): array
    {
        $set    = [];
        $params = [':id' => $programaId];

        if (isset($datos['modalidad'])) { $set[] = 'modalidad = :mod'; $params[':mod'] = $datos['modalidad']; }
        if (isset($datos['plan']['clasesSemana'])) { $set[] = 'clases_semana = :cs'; $params[':cs'] = $datos['plan']['clasesSemana']; }
        if (isset($datos['plan']['mensualidad']))  { $set[] = 'mensualidad = :mens'; $params[':mens'] = $datos['plan']['mensualidad']; }
        if (array_key_exists('descuento', $datos)) {
            $set[] = 'descuento_pct = :dpct';    $params[':dpct'] = $datos['descuento']['pct']    ?? null;
            $set[] = 'descuento_motivo = :dmot'; $params[':dmot'] = $datos['descuento']['motivo'] ?? null;
        }
        if (array_key_exists('fechaInicio', $datos)) {
            $set[] = 'fecha_inicio = :fi'; $params[':fi'] = $datos['fechaInicio'];
            $set[] = 'dia_pago = :dp';     $params[':dp'] = (int) date('j', strtotime($datos['fechaInicio']));
        }
        if (isset($datos['status'])) { $set[] = 'status = :st'; $params[':st'] = $datos['status']; }

        if ($set) {
            $this->pdo->prepare('UPDATE alumno_programas SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
        }
        return $this->programaPorId($programaId);
    }

    /**
     * Da de baja un programa. Además de marcarlo, libera hoy mismo lo que el
     * alumno ocupaba por ese programa: si no, seguiría apareciendo en el
     * pizarrón y el coordinador no podría reasignar la celda.
     *
     * No borra historial: los cobros quedan y las semanas anteriores del
     * pizarrón siguen mostrándolo, porque las reglas se cierran por vigencia.
     */
    public function bajaPrograma(string $programaId): array
    {
        $stmt = $this->pdo->prepare('SELECT alumno_folio, modalidad FROM alumno_programas WHERE id = ?');
        $stmt->execute([$programaId]);
        $prog = $stmt->fetch();
        if (!$prog) {
            throw new RuntimeException('Programa no encontrado');
        }

        $hoy = date('Y-m-d');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE alumno_programas SET status = 'baja' WHERE id = ?")->execute([$programaId]);

            $this->horarios->liberarDeAlumno($prog['alumno_folio'], $hoy, $programaId);
            $this->horarios->quitarDeGrupos($prog['alumno_folio'], $prog['modalidad']);
            $this->cancelarReposiciones($prog['alumno_folio']);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->programaPorId($programaId);
    }

    /**
     * Cancela los comodines de reposición que el alumno tenía por usar. Un
     * alumno que se va no puede seguir con crédito de clases, y si estaban
     * agendados ocupaban una celda del pizarrón.
     *
     * Va en SQL directo y no vía ReposicionRepository: es una sola sentencia y
     * meter otra dependencia al repositorio de alumnos no lo compensa.
     */
    private function cancelarReposiciones(string $folio): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE reposiciones SET estado = 'cancelada'
             WHERE alumno_folio = ? AND estado IN ('pendiente','agendada')"
        );
        $stmt->execute([$folio]);
        return $stmt->rowCount();
    }

    /**
     * Cambia el día de pago. El cambio se ve en la SIGUIENTE mensualidad: se
     * mueve el día del vencimiento vigente al nuevo, conservando el mes, y no
     * se toca ningún cobro ya generado (el periodo en curso se respeta tal como
     * se cobró).
     *
     * El periodo de transición queda más corto o más largo que un mes y se
     * cobra completo: no hay prorrateo.
     */
    public function cambiarDiaPago(string $programaId, int $diaPago): array
    {
        if ($diaPago < 1 || $diaPago > 31) {
            throw new RuntimeException('El día de pago debe estar entre 1 y 31');
        }

        $stmt = $this->pdo->prepare('SELECT vencimiento FROM alumno_programas WHERE id = ?');
        $stmt->execute([$programaId]);
        $vencimiento = $stmt->fetchColumn();
        if ($vencimiento === false) {
            throw new RuntimeException('Programa no encontrado');
        }

        $this->pdo->prepare(
            'UPDATE alumno_programas SET dia_pago = :dp, vencimiento = :venc WHERE id = :id'
        )->execute([
            ':dp'   => $diaPago,
            ':venc' => $this->conDia((string) $vencimiento, $diaPago),
            ':id'   => $programaId,
        ]);

        return $this->programaPorId($programaId);
    }

    /**
     * Reactiva un programa dado de baja. La fecha de pago confirmada ancla el
     * ciclo: de ahí sale el día de pago y el próximo vencimiento. `fecha_inicio`
     * no se toca —es historia— y los cobros viejos siguen ligados al programa.
     */
    public function reactivarPrograma(string $programaId, string $fechaPago): array
    {
        $ts = strtotime($fechaPago);
        if ($ts === false) {
            throw new RuntimeException('Fecha de pago inválida');
        }

        $stmt = $this->pdo->prepare('SELECT alumno_folio, status FROM alumno_programas WHERE id = ?');
        $stmt->execute([$programaId]);
        $prog = $stmt->fetch();
        if (!$prog) {
            throw new RuntimeException('Programa no encontrado');
        }
        if ($prog['status'] === 'activo') {
            throw new RuntimeException('Este programa ya está activo');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE alumno_programas
                 SET status = 'activo', dia_pago = :dp, vencimiento = :venc
                 WHERE id = :id"
            )->execute([
                ':dp'   => (int) date('j', $ts),
                ':venc' => date('Y-m-d', $ts),
                ':id'   => $programaId,
            ]);

            // El alumno vuelve a activo: sin esto quedaría con un programa
            // vigente pero él marcado de baja, y no aparecería en los listados.
            $this->pdo->prepare("UPDATE alumnos SET status = 'activo' WHERE folio = ? AND status = 'baja'")
                      ->execute([$prog['alumno_folio']]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->programaPorId($programaId);
    }

    /**
     * Devuelve la fecha con el día cambiado, topando en meses cortos:
     * el 31 cae al 28 en febrero (o 29 en bisiesto), no se desborda al mes
     * siguiente como haría un simple str_replace de la fecha.
     */
    private function conDia(string $fecha, int $dia): string
    {
        $ts       = strtotime($fecha);
        $ultimo   = (int) date('t', $ts);          // días que tiene ese mes
        $efectivo = min($dia, $ultimo);
        return date('Y-m-', $ts) . str_pad((string) $efectivo, 2, '0', STR_PAD_LEFT);
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    private function insertarPrograma(string $folio, string $modalidad, array $plan, ?array $descuento, string $fechaInicio, int $diaPago, string $vencimiento): string
    {
        $id = $this->uuid();
        $this->pdo->prepare(
            "INSERT INTO alumno_programas
                (id, alumno_folio, modalidad, clases_semana, mensualidad,
                 descuento_pct, descuento_motivo, fecha_inicio, dia_pago, vencimiento, status)
             VALUES (:id, :folio, :mod, :cs, :mens, :dpct, :dmot, :fi, :dp, :venc, 'activo')"
        )->execute([
            ':id'   => $id,    ':folio' => $folio, ':mod' => $modalidad,
            ':cs'   => $plan['clasesSemana'] ?? 1, ':mens' => $plan['mensualidad'] ?? 0,
            ':dpct' => $descuento['pct'] ?? null,  ':dmot' => $descuento['motivo'] ?? null,
            ':fi'   => $fechaInicio, ':dp' => $diaPago, ':venc' => $vencimiento,
        ]);
        return $id;
    }

    private function programaPorId(string $id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT pr.*, pc.label AS plan_label
             FROM alumno_programas pr
             LEFT JOIN planes_catalogo pc ON pc.modalidad = pr.modalidad AND pc.clases_semana = pr.clases_semana AND pc.activo = 1
             WHERE pr.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Programa no encontrado');
        return $this->mapearPrograma($row);
    }

    private function programaPrincipalId(string $folio): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM alumno_programas WHERE alumno_folio = ?
             ORDER BY (status = 'activo') DESC, fecha_inicio ASC, creado_en ASC LIMIT 1"
        );
        $stmt->execute([$folio]);
        $id = $stmt->fetchColumn();
        return $id ?: null;
    }

    /** @return array<string, array<int, array>> programas agrupados por folio */
    private function programasDe(array $folios): array
    {
        if (!$folios) return [];
        $place = implode(',', array_fill(0, count($folios), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT pr.*, pc.label AS plan_label
             FROM alumno_programas pr
             LEFT JOIN planes_catalogo pc ON pc.modalidad = pr.modalidad AND pc.clases_semana = pr.clases_semana AND pc.activo = 1
             WHERE pr.alumno_folio IN ($place)
             ORDER BY pr.fecha_inicio ASC, pr.creado_en ASC"
        );
        $stmt->execute(array_values($folios));
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['alumno_folio']][] = $r;
        }
        return $out;
    }

    private function siguienteFolio(): string
    {
        $this->pdo->exec('INSERT INTO folio_seq (reservado) VALUES (1)');
        $id = (int) $this->pdo->lastInsertId();
        return str_pad((string) $id, 3, '0', STR_PAD_LEFT);
    }

    private function columnasPersona(string $a): string
    {
        return "$a.folio, $a.nombre, $a.fecha_nacimiento, $a.foto,
                $a.tutor, $a.telefono, $a.email, $a.inscripcion_pagada,
                $a.disp_dias, $a.disp_hora_inicio, $a.disp_hora_fin,
                $a.status, $a.sucursal_id, $a.creado_en,
                $a.fecha_inicio, $a.dia_pago,
                $a.notas, $a.fecha_clase_muestra";
    }

    private function mapearPrograma(array $r): array
    {
        return [
            'id'                => $r['id'],
            'modalidad'         => $r['modalidad'],
            'plan'              => [
                'clasesSemana' => (int) $r['clases_semana'],
                'mensualidad'  => (float) $r['mensualidad'],
                'label'        => $r['plan_label'] ?? null,
            ],
            'fechaInicio'       => $r['fecha_inicio'],
            'diaPago'           => (int) $r['dia_pago'],
            'ultimoPago'        => $r['ultimo_pago'],
            'vencimiento'       => $r['vencimiento'],
            'descuento'         => $r['descuento_pct'] !== null ? [
                'pct'    => (float) $r['descuento_pct'],
                'motivo' => $r['descuento_motivo'],
            ] : null,
            'status'            => $r['status'],
            'tocaPago'          => $this->calcularTocaPago($r['vencimiento']),
            'diasMora'          => $this->calcularDiasMora($r['vencimiento']),
            'montoConDescuento' => (float) $r['mensualidad'],
        ];
    }

    private function mapear(array $row, array $programasRows): array
    {
        $programas = array_map($this->mapearPrograma(...), $programasRows);
        $activos   = array_values(array_filter($programas, fn($p) => $p['status'] === 'activo'));
        $primario  = $activos[0] ?? ($programas[0] ?? null);

        // Vencimiento del alumno = el más próximo entre programas activos
        $vencs      = array_map(fn($p) => $p['vencimiento'], $activos);
        $vencAlumno = $vencs ? min($vencs) : ($primario['vencimiento'] ?? null);
        $sumaMens   = array_sum(array_map(fn($p) => $p['plan']['mensualidad'], $activos));

        return [
            'folio'            => $row['folio'],
            'nombre'           => $row['nombre'],
            'fechaNacimiento'  => $row['fecha_nacimiento'],
            'foto'             => $row['foto'],
            'contacto'         => [
                'tutor'    => $row['tutor'],
                'telefono' => $row['telefono'],
                'email'    => $row['email'],
            ],
            // Campos compat derivados del programa principal
            'modalidad'        => $primario['modalidad'] ?? null,
            'plan'             => $primario['plan'] ?? ['clasesSemana' => 0, 'mensualidad' => 0, 'label' => null],
            'fechaInicio'      => $primario['fechaInicio'] ?? $row['fecha_inicio'],
            'diaPago'          => $primario['diaPago'] ?? (int) $row['dia_pago'],
            'ultimoPago'       => $primario['ultimoPago'] ?? null,
            'vencimiento'      => $vencAlumno,
            'inscripcionPagada'=> (bool) $row['inscripcion_pagada'],
            // Prealumnos: notas de seguimiento y cuándo tomó su clase de prueba
            'notas'            => $row['notas'] ?? null,
            'fechaClaseMuestra'=> $row['fecha_clase_muestra'] ?? null,
            'descuento'        => $primario['descuento'] ?? null,
            'disponibilidad'   => [
                'dias'       => json_decode($row['disp_dias'] ?? '[]', true),
                'horaInicio' => $row['disp_hora_inicio'],
                'horaFin'    => $row['disp_hora_fin'],
            ],
            'status'           => $row['status'],
            'sucursalId'       => $row['sucursal_id'],
            'creadoEn'         => $row['creado_en'],
            // Campos calculados de UI (nivel alumno)
            'tocaPago'         => $this->calcularTocaPago($vencAlumno),
            'diasMora'         => $this->calcularDiasMora($vencAlumno),
            'montoConDescuento'=> $sumaMens ?: ($primario['plan']['mensualidad'] ?? 0),
            // Multimodalidad
            'programas'        => $programas,
        ];
    }

    private function calcularTocaPago(?string $vencimiento): bool
    {
        if (!$vencimiento) return false;
        $hoy  = new \DateTimeImmutable();
        $venc = new \DateTimeImmutable($vencimiento);
        $diff = (int) $hoy->diff($venc)->format('%r%a');
        return $diff >= 0 && $diff <= 7;
    }

    private function calcularDiasMora(?string $vencimiento): int
    {
        if (!$vencimiento) return 0;
        $hoy  = new \DateTimeImmutable();
        $venc = new \DateTimeImmutable($vencimiento);
        $diff = (int) $hoy->diff($venc)->format('%r%a');
        return $diff < 0 ? abs($diff) : 0;
    }

    private function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
