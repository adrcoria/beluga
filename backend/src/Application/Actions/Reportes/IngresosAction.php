<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Reportes;

use Beluga\Application\Actions\Action;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class IngresosAction extends Action
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

        $filtroSuc = $suc ? ' AND p.sucursal_id = :suc' : '';

        $bind = function (\PDOStatement $s) use ($desde, $hasta, $suc): void {
            $s->bindValue(':d', $desde);
            $s->bindValue(':h', $hasta);
            if ($suc) $s->bindValue(':suc', $suc);
        };

        // Totales
        $st = $this->pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(p.monto),0) total FROM pagos p WHERE p.fecha BETWEEN :d AND :h $filtroSuc");
        $bind($st); $st->execute(); $tot = $st->fetch();

        // Por método
        $st = $this->pdo->prepare("SELECT p.metodo, COUNT(*) c, COALESCE(SUM(p.monto),0) total FROM pagos p WHERE p.fecha BETWEEN :d AND :h $filtroSuc GROUP BY p.metodo ORDER BY total DESC");
        $bind($st); $st->execute(); $porMetodo = $st->fetchAll();

        // Por concepto
        $st = $this->pdo->prepare("SELECT p.concepto, COUNT(*) c, COALESCE(SUM(p.monto),0) total FROM pagos p WHERE p.fecha BETWEEN :d AND :h $filtroSuc GROUP BY p.concepto ORDER BY total DESC");
        $bind($st); $st->execute(); $porConcepto = $st->fetchAll();

        // Por día
        $st = $this->pdo->prepare("SELECT p.fecha, COUNT(*) c, COALESCE(SUM(p.monto),0) total FROM pagos p WHERE p.fecha BETWEEN :d AND :h $filtroSuc GROUP BY p.fecha ORDER BY p.fecha");
        $bind($st); $st->execute(); $porDia = $st->fetchAll();

        // Detalle
        $st = $this->pdo->prepare(
            "SELECT p.fecha, p.ticket, p.monto, p.metodo, p.concepto, a.folio, a.nombre AS alumno
             FROM pagos p JOIN alumnos a ON a.folio = p.alumno_folio
             WHERE p.fecha BETWEEN :d AND :h $filtroSuc ORDER BY p.fecha DESC, p.ticket DESC"
        );
        $bind($st); $st->execute(); $detalle = $st->fetchAll();

        return $this->json([
            'desde'       => $desde,
            'hasta'       => $hasta,
            'total'       => (float) $tot['total'],
            'totalCount'  => (int) $tot['c'],
            'porMetodo'   => array_map([$this, 'num'], $porMetodo),
            'porConcepto' => array_map([$this, 'num'], $porConcepto),
            'porDia'      => array_map([$this, 'num'], $porDia),
            'detalle'     => array_map(fn($r) => [
                'fecha' => $r['fecha'], 'ticket' => $r['ticket'], 'monto' => (float) $r['monto'],
                'metodo' => $r['metodo'], 'concepto' => $r['concepto'], 'folio' => $r['folio'], 'alumno' => $r['alumno'],
            ], $detalle),
        ]);
    }

    private function num(array $r): array
    {
        $r['total'] = (float) $r['total'];
        $r['c']     = (int) $r['c'];
        return $r;
    }
}
