<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Alumno\AlumnoRepository;
use PDO;

class AlumnoMySqlRepository implements AlumnoRepository
{
    public function __construct(private readonly PDO $pdo) {}

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
        if (!empty($filtros['modalidad'])) {
            $where[]             = 'a.modalidad = :modalidad';
            $params[':modalidad'] = $filtros['modalidad'];
        }
        if (!empty($filtros['status'])) {
            $where[]          = 'a.status = :status';
            $params[':status'] = $filtros['status'];
        }
        if (!empty($filtros['sucursal'])) {
            $where[]            = 'a.sucursal_id = :sucursal';
            $params[':sucursal'] = $filtros['sucursal'];
        }
        if (!empty($filtros['toca_pago'])) {
            $where[] = 'a.vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
        }

        $sql  = 'SELECT ' . $this->columnas('a') . ', p.label AS plan_label
                 FROM alumnos a
                 LEFT JOIN planes_catalogo p ON p.modalidad = a.modalidad AND p.clases_semana = a.clases_semana AND p.activo = 1
                 WHERE ' . implode(' AND ', $where) . ' ORDER BY a.nombre';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map($this->mapear(...), $stmt->fetchAll());
    }

    // ── Por folio ─────────────────────────────────────────────────────────

    public function porFolio(string $folio): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . $this->columnas('a') . ', p.label AS plan_label
             FROM alumnos a
             LEFT JOIN planes_catalogo p ON p.modalidad = a.modalidad AND p.clases_semana = a.clases_semana AND p.activo = 1
             WHERE a.folio = ?'
        );
        $stmt->execute([$folio]);
        $row = $stmt->fetch();

        return $row ? $this->mapear($row) : null;
    }

    // ── Crear ─────────────────────────────────────────────────────────────

    public function crear(array $datos): array
    {
        $folio = $this->siguienteFolio();

        $fechaInicio = $datos['fechaInicio'];
        $diaPago     = (int) date('j', strtotime($fechaInicio));
        $vencimiento = date('Y-m-d', strtotime($fechaInicio . ' +1 month'));

        $sql = <<<SQL
            INSERT INTO alumnos (
                folio, nombre, fecha_nacimiento, foto,
                tutor, telefono, email,
                modalidad, clases_semana, mensualidad,
                fecha_inicio, dia_pago, vencimiento,
                inscripcion_pagada,
                descuento_pct, descuento_motivo,
                disp_dias, disp_hora_inicio, disp_hora_fin,
                status, sucursal_id
            ) VALUES (
                :folio, :nombre, :fecha_nacimiento, :foto,
                :tutor, :telefono, :email,
                :modalidad, :clases_semana, :mensualidad,
                :fecha_inicio, :dia_pago, :vencimiento,
                :inscripcion_pagada,
                :descuento_pct, :descuento_motivo,
                :disp_dias, :disp_hora_inicio, :disp_hora_fin,
                :status, :sucursal_id
            )
        SQL;

        $contacto      = $datos['contacto']      ?? [];
        $plan          = $datos['plan']           ?? [];
        $descuento     = $datos['descuento']      ?? null;
        $disponibilidad = $datos['disponibilidad'] ?? [];

        $this->pdo->prepare($sql)->execute([
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
        ]);

        return $this->porFolio($folio);
    }

    // ── Actualizar ────────────────────────────────────────────────────────

    public function actualizar(string $folio, array $datos): ?array
    {
        $set    = [];
        $params = [':folio' => $folio];

        $mapa = [
            'nombre'         => 'nombre',
            'fechaNacimiento'=> 'fecha_nacimiento',
            'foto'           => 'foto',
            'modalidad'      => 'modalidad',
            'status'         => 'status',
        ];

        if (array_key_exists('fechaInicio', $datos)) {
            $set[] = 'fecha_inicio = :fecha_inicio';
            $params[':fecha_inicio'] = $datos['fechaInicio'];
            $set[] = 'dia_pago = :dia_pago';
            $params[':dia_pago'] = (int) date('j', strtotime($datos['fechaInicio']));
        }

        foreach ($mapa as $campo => $col) {
            if (array_key_exists($campo, $datos)) {
                $set[]              = "$col = :$col";
                $params[":$col"]    = $datos[$campo];
            }
        }

        if (isset($datos['contacto'])) {
            foreach (['tutor', 'telefono', 'email'] as $c) {
                if (array_key_exists($c, $datos['contacto'])) {
                    $set[]           = "$c = :$c";
                    $params[":$c"]   = $datos['contacto'][$c];
                }
            }
        }

        if (isset($datos['plan'])) {
            if (isset($datos['plan']['clasesSemana'])) { $set[] = 'clases_semana = :clases_semana'; $params[':clases_semana'] = $datos['plan']['clasesSemana']; }
            if (isset($datos['plan']['mensualidad']))  { $set[] = 'mensualidad = :mensualidad';      $params[':mensualidad']  = $datos['plan']['mensualidad']; }
        }

        if (isset($datos['descuento'])) {
            $set[] = 'descuento_pct = :dpct';     $params[':dpct']    = $datos['descuento']['pct']    ?? null;
            $set[] = 'descuento_motivo = :dmot';  $params[':dmot']    = $datos['descuento']['motivo'] ?? null;
        }

        if (isset($datos['disponibilidad'])) {
            $disp  = $datos['disponibilidad'];
            if (isset($disp['dias']))       { $set[] = 'disp_dias = :disp_dias';               $params[':disp_dias']        = json_encode($disp['dias']); }
            if (isset($disp['horaInicio'])) { $set[] = 'disp_hora_inicio = :disp_hora_inicio'; $params[':disp_hora_inicio'] = $disp['horaInicio']; }
            if (isset($disp['horaFin']))    { $set[] = 'disp_hora_fin = :disp_hora_fin';       $params[':disp_hora_fin']    = $disp['horaFin']; }
        }

        if (empty($set)) {
            return $this->porFolio($folio);
        }

        $this->pdo->prepare('UPDATE alumnos SET ' . implode(', ', $set) . ' WHERE folio = :folio')->execute($params);

        return $this->porFolio($folio);
    }

    // ── Eliminar ──────────────────────────────────────────────────────────

    public function eliminar(string $folio): void
    {
        $this->pdo->prepare('DELETE FROM alumnos WHERE folio = ?')->execute([$folio]);
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    private function siguienteFolio(): string
    {
        $this->pdo->exec('INSERT INTO folio_seq (reservado) VALUES (1)');
        $id = (int) $this->pdo->lastInsertId();
        return str_pad((string) $id, 3, '0', STR_PAD_LEFT);
    }

    private function columnas(string $alias): string
    {
        return "$alias.folio, $alias.nombre, $alias.fecha_nacimiento, $alias.foto,
                $alias.tutor, $alias.telefono, $alias.email,
                $alias.modalidad, $alias.clases_semana, $alias.mensualidad,
                $alias.fecha_inicio, $alias.dia_pago, $alias.ultimo_pago, $alias.vencimiento,
                $alias.inscripcion_pagada,
                $alias.descuento_pct, $alias.descuento_motivo,
                $alias.disp_dias, $alias.disp_hora_inicio, $alias.disp_hora_fin,
                $alias.status, $alias.sucursal_id, $alias.creado_en";
    }

    private function mapear(array $row): array
    {
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
            'modalidad'        => $row['modalidad'],
            'plan'             => [
                'clasesSemana' => (int) $row['clases_semana'],
                'mensualidad'  => (float) $row['mensualidad'],
                'label'        => $row['plan_label'] ?? null,
            ],
            'fechaInicio'      => $row['fecha_inicio'],
            'diaPago'          => (int) $row['dia_pago'],
            'ultimoPago'       => $row['ultimo_pago'],
            'vencimiento'      => $row['vencimiento'],
            'inscripcionPagada'=> (bool) $row['inscripcion_pagada'],
            'descuento'        => $row['descuento_pct'] !== null ? [
                'pct'    => (float) $row['descuento_pct'],
                'motivo' => $row['descuento_motivo'],
            ] : null,
            'disponibilidad'   => [
                'dias'       => json_decode($row['disp_dias'] ?? '[]', true),
                'horaInicio' => $row['disp_hora_inicio'],
                'horaFin'    => $row['disp_hora_fin'],
            ],
            'status'           => $row['status'],
            'sucursalId'       => $row['sucursal_id'],
            'creadoEn'         => $row['creado_en'],
            // Campos calculados de UI
            'tocaPago'         => $this->calcularTocaPago($row['vencimiento']),
            'diasMora'         => $this->calcularDiasMora($row['vencimiento']),
            'montoConDescuento'=> $this->calcularMontoConDescuento((float)$row['mensualidad']),
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

    private function calcularMontoConDescuento(float $mensualidad): float
    {
        return $mensualidad;
    }
}
