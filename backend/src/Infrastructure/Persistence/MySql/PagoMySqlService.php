<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Persistence\MySql;

use Beluga\Domain\Pago\PagoService;
use PDO;

class PagoMySqlService implements PagoService
{
    public function __construct(private readonly PDO $pdo) {}

    public function registrar(array $datos, string $usuarioId): array
    {
        $folio    = $datos['alumnoFolio'];
        $concepto = $datos['concepto'] ?? 'mensualidad';
        $ticket   = $this->generarTicket();

        $this->pdo->prepare(
            'INSERT INTO pagos (id, alumno_folio, concepto, monto, metodo, fecha, ticket, ciclo_desde, ciclo_hasta, usuario_id, sucursal_id)
             VALUES (UUID(), :folio, :concepto, :monto, :metodo, :fecha, :ticket, :desde, :hasta, :usuario, :sucursal)'
        )->execute([
            ':folio'    => $folio,
            ':concepto' => $concepto,
            ':monto'    => $datos['monto'],
            ':metodo'   => $datos['metodo'] ?? 'efectivo',
            ':fecha'    => $datos['fecha']  ?? date('Y-m-d'),
            ':ticket'   => $ticket,
            ':desde'    => $datos['cicloDesde'] ?? null,
            ':hasta'    => $datos['cicloHasta'] ?? null,
            ':usuario'  => $usuarioId,
            ':sucursal' => $datos['sucursalId'],
        ]);

        // Si es mensualidad, actualizar alumno
        if ($concepto === 'mensualidad') {
            $fechaPago   = $datos['fecha'] ?? date('Y-m-d');
            $vencimiento = date('Y-m-d', strtotime($fechaPago . ' +1 month'));

            $this->pdo->prepare(
                'UPDATE alumnos SET ultimo_pago = :ultimo, vencimiento = :venc WHERE folio = :folio'
            )->execute([
                ':ultimo' => $fechaPago,
                ':venc'   => $vencimiento,
                ':folio'  => $folio,
            ]);
        }

        // Si es inscripción, marcar flag
        if ($concepto === 'inscripcion') {
            $this->pdo->prepare(
                'UPDATE alumnos SET inscripcion_pagada = 1 WHERE folio = ?'
            )->execute([$folio]);
        }

        // Recuperar el pago recién insertado
        $stmt = $this->pdo->prepare('SELECT * FROM pagos WHERE ticket = ?');
        $stmt->execute([$ticket]);
        return $this->mapear($stmt->fetch());
    }

    public function porAlumno(string $folio): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pagos WHERE alumno_folio = ? ORDER BY fecha DESC, creado_en DESC'
        );
        $stmt->execute([$folio]);
        return array_map($this->mapear(...), $stmt->fetchAll());
    }

    public function recientes(int $limite): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, a.nombre as alumno_nombre
             FROM pagos p
             JOIN alumnos a ON a.folio = p.alumno_folio
             ORDER BY p.creado_en DESC
             LIMIT :limite'
        );
        $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(function (array $row): array {
            $p = $this->mapear($row);
            $p['alumnoNombre'] = $row['alumno_nombre'];
            return $p;
        }, $stmt->fetchAll());
    }

    private function mapear(array $row): array
    {
        return [
            'id'          => $row['id'],
            'alumnoFolio' => $row['alumno_folio'],
            'concepto'    => $row['concepto'],
            'monto'       => (float) $row['monto'],
            'metodo'      => $row['metodo'],
            'fecha'       => $row['fecha'],
            'ticket'      => $row['ticket'],
            'cicloDesde'  => $row['ciclo_desde'],
            'cicloHasta'  => $row['ciclo_hasta'],
            'usuarioId'   => $row['usuario_id'],
            'sucursalId'  => $row['sucursal_id'],
            'creadoEn'    => $row['creado_en'],
        ];
    }

    private function generarTicket(): string
    {
        return strtoupper('BLG-' . date('ymd') . '-' . substr(uniqid(), -5));
    }
}
