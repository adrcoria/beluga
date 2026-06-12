<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Reportes;

use Beluga\Application\Actions\Action;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class AsistenciaReporteAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PDO $pdo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $desde = (string) $this->getQueryParam('desde', date('Y-m-01'));
        $hasta = (string) $this->getQueryParam('hasta', date('Y-m-d'));
        $suc   = $this->getQueryParam('sucursal');
        $filtroSuc = $suc ? ' AND a.sucursal_id = :suc' : '';

        // Asistencias por día
        $st = $this->pdo->prepare(
            "SELECT a.fecha, COUNT(*) total FROM asistencias a
             WHERE a.fecha BETWEEN :d AND :h $filtroSuc GROUP BY a.fecha ORDER BY a.fecha"
        );
        $st->bindValue(':d', $desde); $st->bindValue(':h', $hasta);
        if ($suc) $st->bindValue(':suc', $suc);
        $st->execute();
        $porDia = array_map(fn($r) => ['fecha' => $r['fecha'], 'total' => (int) $r['total']], $st->fetchAll());

        $total = array_sum(array_column($porDia, 'total'));

        // Ausentismo: alumnos activos sin asistencia en el rango
        $filtroSucAl = $suc ? ' AND al.sucursal_id = :suc' : '';
        $st = $this->pdo->prepare(
            "SELECT al.folio, al.nombre,
                    (SELECT MAX(x.fecha) FROM asistencias x WHERE x.alumno_folio = al.folio) AS ultima
             FROM alumnos al
             WHERE al.status = 'activo' $filtroSucAl
               AND al.folio NOT IN (
                   SELECT alumno_folio FROM asistencias WHERE fecha BETWEEN :d AND :h
               )
             ORDER BY ultima IS NULL DESC, ultima ASC"
        );
        $st->bindValue(':d', $desde); $st->bindValue(':h', $hasta);
        if ($suc) $st->bindValue(':suc', $suc);
        $st->execute();
        $ausentes = array_map(fn($r) => [
            'folio'  => $r['folio'],
            'nombre' => $r['nombre'],
            'ultima' => $r['ultima'],
        ], $st->fetchAll());

        return $this->json([
            'desde'    => $desde,
            'hasta'    => $hasta,
            'total'    => $total,
            'porDia'   => $porDia,
            'ausentes' => $ausentes,
        ]);
    }
}
