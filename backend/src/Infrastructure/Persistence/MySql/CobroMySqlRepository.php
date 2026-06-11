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
            $where[]       = '(a.nombre LIKE :q1 OR a.folio LIKE :q2)';
            $q             = '%' . $filtros['busqueda'] . '%';
            $params[':q1'] = $q;
            $params[':q2'] = $q;
        }

        $sql = 'SELECT c.*, a.nombre AS alumno_nombre, a.foto AS alumno_foto,
                       a.telefono AS alumno_telefono, a.email AS alumno_email,
                       a.saldo_favor AS alumno_saldo_favor
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
                    a.telefono AS alumno_telefono, a.email AS alumno_email,
                    a.saldo_favor AS alumno_saldo_favor,
                    a.modalidad AS alumno_modalidad, a.clases_semana AS alumno_clases_semana
             FROM cobros c
             JOIN alumnos a ON a.folio = c.alumno_folio
             WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->mapear($row) : null;
    }

    public function registrarAbono(string $cobroId, float $monto, string $metodo, string $usuarioId, string $sucursalId, bool $requiereFactura = false): array
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

        $this->pdo->beginTransaction();
        try {
            // 0. Folio consecutivo de la sucursal (atómico)
            $ticket = (string) $this->siguienteFolioPago($sucursalId);

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

            // 2. Actualizar cobro (incluye si requiere factura)
            $this->pdo->prepare(
                'UPDATE cobros SET monto_pagado = :pagado, estado = :estado, requiere_factura = :rf WHERE id = :id'
            )->execute([
                ':pagado' => $nuevoMontoPagado,
                ':estado' => $nuevoEstado,
                ':rf'     => $requiereFactura ? 1 : 0,
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
                a.mensualidad,
                LEAST(a.saldo_favor, a.mensualidad),
                GREATEST(0, a.mensualidad - a.saldo_favor),
                CASE
                    WHEN a.saldo_favor >= a.mensualidad
                    THEN \'pagado\' ELSE \'pendiente\'
                END,
                a.vencimiento,
                DATE_ADD(a.vencimiento, INTERVAL 1 MONTH)
             FROM alumnos a
             WHERE a.status = \'activo\'
               -- Solo generar cuando el cobro ya entra en la ventana de "toca pago"
               -- (vence hoy, está vencido, o vence dentro de 7 días). Esto evita que
               -- al pagar (vencimiento salta +1 mes) se regenere el siguiente de inmediato.
               AND a.vencimiento <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
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

    public function actualizarFactura(string $cobroId, ?bool $requiere, ?bool $realizada): array
    {
        $set = [];
        $params = [':id' => $cobroId];
        if ($requiere !== null) {
            $set[] = 'requiere_factura = :rf';
            $params[':rf'] = $requiere ? 1 : 0;
        }
        if ($realizada !== null) {
            $set[] = 'factura_realizada = :fr';
            $params[':fr'] = $realizada ? 1 : 0;
        }
        if ($set) {
            $this->pdo->prepare('UPDATE cobros SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
        }
        return $this->porId($cobroId);
    }

    public function pagosDe(string $cobroId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, monto, metodo, fecha, ticket, concepto
             FROM pagos WHERE cobro_id = ? ORDER BY fecha DESC, id DESC'
        );
        $stmt->execute([$cobroId]);
        return array_map(fn($r) => [
            'id'       => $r['id'],
            'monto'    => (float) $r['monto'],
            'metodo'   => $r['metodo'],
            'fecha'    => $r['fecha'],
            'ticket'   => $r['ticket'],
            'concepto' => $r['concepto'],
        ], $stmt->fetchAll());
    }

    /** Devuelve el siguiente folio consecutivo de la sucursal e incrementa el contador (atómico). */
    private function siguienteFolioPago(string $sucursalId): int
    {
        $this->pdo->prepare('INSERT IGNORE INTO folio_pago_seq (sucursal_id, siguiente) VALUES (?, 1)')
                  ->execute([$sucursalId]);
        // LAST_INSERT_ID(siguiente) fija el folio a asignar y deja siguiente = folio + 1
        $this->pdo->prepare('UPDATE folio_pago_seq SET siguiente = LAST_INSERT_ID(siguiente) + 1 WHERE sucursal_id = ?')
                  ->execute([$sucursalId]);
        return (int) $this->pdo->lastInsertId();
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
            'alumnoEmail'      => $row['alumno_email'] ?? null,
            'alumnoSaldoFavor' => (float) ($row['alumno_saldo_favor'] ?? 0),
            'montoBase'        => (float) $row['monto_base'],
            'saldoFavorAplicado' => (float) $row['saldo_favor_aplicado'],
            'montoNeto'        => (float) $row['monto_neto'],
            'montoPagado'      => (float) $row['monto_pagado'],
            'estado'           => $row['estado'],
            'requiereFactura'  => (bool) ($row['requiere_factura'] ?? false),
            'facturaRealizada' => (bool) ($row['factura_realizada'] ?? false),
            'periodoDesde'     => $row['periodo_desde'],
            'periodoHasta'     => $row['periodo_hasta'],
            'generadoEn'       => $row['generado_en'],
        ];
    }
}
