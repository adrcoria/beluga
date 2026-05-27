<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Cobro\CobroRepository;
use PDO;
use RuntimeException;

class CobroMySqlRepository implements CobroRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function listar(array $filtros = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['estado'])) {
            $where[]           = 'c.estado = :estado';
            $params[':estado'] = $filtros['estado'];
        }
        if (!empty($filtros['alumno_folio'])) {
            $where[]             = 'c.alumno_folio = :folio';
            $params[':folio']    = $filtros['alumno_folio'];
        }
        if (!empty($filtros['busqueda'])) {
            $where[]   = '(a.nombre LIKE :q OR a.folio LIKE :q)';
            $params[':q'] = '%' . $filtros['busqueda'] . '%';
        }

        $sql = 'SELECT c.*, a.nombre AS alumno_nombre, a.foto AS alumno_foto,
                       a.telefono AS alumno_telefono, a.saldo_favor AS alumno_saldo_favor
                FROM cobros c
                JOIN alumnos a ON a.folio = c.alumno_folio
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY c.generado_en DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map($this->mapear(...), $stmt->fetchAll());
    }

    public function porId(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, a.nombre AS alumno_nombre, a.foto AS alumno_foto,
                    a.telefono AS alumno_telefono, a.saldo_favor AS alumno_saldo_favor,
                    a.modalidad AS alumno_modalidad, a.clases_semana AS alumno_clases_semana
             FROM cobros c
             JOIN alumnos a ON a.folio = c.alumno_folio
             WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->mapear($row) : null;
    }

    public function registrarAbono(string $cobroId, float $monto, string $metodo, string $usuarioId, string $sucursalId): array
    {
        $cobro = $this->porId($cobroId);
        if (!$cobro) {
            throw new RuntimeException('Cobro no encontrado');
        }
        if ($cobro['estado'] === 'pagado') {
            throw new RuntimeException('Este cobro ya está liquidado');
        }
        if ($monto <= 0) {
            throw new RuntimeException('El monto debe ser mayor a cero');
        }

        $pendiente   = round($cobro['montoNeto'] - $cobro['montoPagado'], 2);
        $abonoReal   = min($monto, $pendiente);
        $excedente   = round($monto - $abonoReal, 2);

        $nuevoMontoPagado = round($cobro['montoPagado'] + $abonoReal, 2);
        $nuevoEstado      = $nuevoMontoPagado >= $cobro['montoNeto'] ? 'pagado' : 'parcial';

        $ticket = strtoupper('BLG-' . date('ymd') . '-' . substr(uniqid(), -5));

        $this->pdo->beginTransaction();
        try {
            // 1. Registrar pago en tabla pagos
            $this->pdo->prepare(
                'INSERT INTO pagos (id, alumno_folio, concepto, monto, metodo, fecha, ticket,
                                   ciclo_desde, ciclo_hasta, cobro_id, usuario_id, sucursal_id)
                 VALUES (UUID(), :folio, :concepto, :monto, :metodo, CURDATE(), :ticket,
                         :desde, :hasta, :cobro_id, :usuario, :sucursal)'
            )->execute([
                ':folio'    => $cobro['alumnoFolio'],
                ':concepto' => 'mensualidad',
                ':monto'    => $monto,
                ':metodo'   => $metodo,
                ':ticket'   => $ticket,
                ':desde'    => $cobro['periodoDesde'],
                ':hasta'    => $cobro['periodoHasta'],
                ':cobro_id' => $cobroId,
                ':usuario'  => $usuarioId,
                ':sucursal' => $sucursalId,
            ]);

            // 2. Actualizar cobro
            $this->pdo->prepare(
                'UPDATE cobros SET monto_pagado = :pagado, estado = :estado WHERE id = :id'
            )->execute([
                ':pagado' => $nuevoMontoPagado,
                ':estado' => $nuevoEstado,
                ':id'     => $cobroId,
            ]);

            // 3. Si el cobro quedó pagado: avanzar alumno
            if ($nuevoEstado === 'pagado') {
                $this->pdo->prepare(
                    'UPDATE alumnos SET ultimo_pago = CURDATE(), vencimiento = :venc WHERE folio = :folio'
                )->execute([
                    ':venc'  => $cobro['periodoHasta'],
                    ':folio' => $cobro['alumnoFolio'],
                ]);
            }

            // 4. Si hay excedente: acumular saldo a favor
            if ($excedente > 0) {
                $this->pdo->prepare(
                    'UPDATE alumnos SET saldo_favor = saldo_favor + :exc WHERE folio = :folio'
                )->execute([
                    ':exc'   => $excedente,
                    ':folio' => $cobro['alumnoFolio'],
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $cobroActualizado          = $this->porId($cobroId);
        $cobroActualizado['ticket'] = $ticket;
        return $cobroActualizado;
    }

    public function crearInscripcion(string $alumnoFolio, float $monto, string $fecha): array
    {
        $this->pdo->prepare(
            'INSERT INTO cobros (id, alumno_folio, concepto, monto_base, saldo_favor_aplicado, monto_neto, estado, periodo_desde, periodo_hasta)
             VALUES (UUID(), :folio, \'inscripcion\', :monto_base, 0, :monto_neto, \'pendiente\', :periodo_desde, :periodo_hasta)'
        )->execute([
            ':folio'         => $alumnoFolio,
            ':monto_base'    => $monto,
            ':monto_neto'    => $monto,
            ':periodo_desde' => $fecha,
            ':periodo_hasta' => $fecha,
        ]);

        $stmt = $this->pdo->prepare(
            'SELECT c.*, a.nombre AS alumno_nombre, a.foto AS alumno_foto,
                    a.telefono AS alumno_telefono, a.saldo_favor AS alumno_saldo_favor
             FROM cobros c JOIN alumnos a ON a.folio = c.alumno_folio
             WHERE c.alumno_folio = :folio AND c.concepto = \'inscripcion\'
             ORDER BY c.generado_en DESC LIMIT 1'
        );
        $stmt->execute([':folio' => $alumnoFolio]);
        return $this->mapear($stmt->fetch());
    }

    public function generarManual(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cobros (
                id, alumno_folio, concepto,
                monto_base, saldo_favor_aplicado, monto_neto,
                estado, periodo_desde, periodo_hasta
             )
             SELECT
                UUID(),
                a.folio,
                \'mensualidad\',
                ROUND(a.mensualidad * (1 - COALESCE(a.descuento_pct, 0) / 100), 2),
                LEAST(a.saldo_favor, ROUND(a.mensualidad * (1 - COALESCE(a.descuento_pct, 0) / 100), 2)),
                GREATEST(0, ROUND(a.mensualidad * (1 - COALESCE(a.descuento_pct, 0) / 100), 2) - a.saldo_favor),
                CASE
                    WHEN a.saldo_favor >= ROUND(a.mensualidad * (1 - COALESCE(a.descuento_pct, 0) / 100), 2)
                    THEN \'pagado\' ELSE \'pendiente\'
                END,
                a.vencimiento,
                DATE_ADD(a.vencimiento, INTERVAL 1 MONTH)
             FROM alumnos a
             WHERE a.status = \'activo\'
               AND NOT EXISTS (
                   SELECT 1 FROM cobros c
                   WHERE c.alumno_folio = a.folio
                     AND c.concepto = \'mensualidad\'
                     AND c.periodo_desde = a.vencimiento
               )'
        );
        $stmt->execute();

        // Avanzar alumnos que quedaron pagados automáticamente por saldo a favor
        $this->pdo->exec(
            'UPDATE alumnos a
             INNER JOIN cobros c
                 ON c.alumno_folio = a.folio
                AND c.periodo_desde = a.vencimiento
                AND c.estado = \'pagado\'
                AND c.saldo_favor_aplicado > 0
             SET
                a.saldo_favor = GREATEST(0, a.saldo_favor - c.monto_base),
                a.ultimo_pago = CURDATE(),
                a.vencimiento = DATE_ADD(a.vencimiento, INTERVAL 1 MONTH)
             WHERE a.status = \'activo\''
        );

        return $stmt->rowCount();
    }

    private function mapear(array $row): array
    {
        return [
            'id'               => $row['id'],
            'alumnoFolio'      => $row['alumno_folio'],
            'concepto'         => $row['concepto'],
            'alumnoNombre'     => $row['alumno_nombre'],
            'alumnoFoto'       => $row['alumno_foto'] ?? null,
            'alumnoTelefono'   => $row['alumno_telefono'] ?? null,
            'alumnoSaldoFavor' => (float) ($row['alumno_saldo_favor'] ?? 0),
            'montoBase'        => (float) $row['monto_base'],
            'saldoFavorAplicado' => (float) $row['saldo_favor_aplicado'],
            'montoNeto'        => (float) $row['monto_neto'],
            'montoPagado'      => (float) $row['monto_pagado'],
            'estado'           => $row['estado'],
            'periodoDesde'     => $row['periodo_desde'],
            'periodoHasta'     => $row['periodo_hasta'],
            'generadoEn'       => $row['generado_en'],
        ];
    }
}
