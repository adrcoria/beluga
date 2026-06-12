<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Reportes;

use Beluga\Application\Actions\Action;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class CarteraAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PDO $pdo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $suc = $this->getQueryParam('sucursal');
        $filtroSuc = $suc ? ' AND a.sucursal_id = :suc' : '';

        $sql = "SELECT a.folio, a.nombre, a.telefono,
                       COALESCE(SUM(c.monto_neto - c.monto_pagado),0) AS pendiente,
                       MIN(c.periodo_desde) AS desde_mas_viejo,
                       DATEDIFF(CURDATE(), MIN(c.periodo_desde)) AS dias_mora,
                       COUNT(*) AS cobros
                FROM cobros c
                JOIN alumnos a ON a.folio = c.alumno_folio
                WHERE c.estado IN ('pendiente','parcial') $filtroSuc
                GROUP BY a.folio, a.nombre, a.telefono
                HAVING pendiente > 0
                ORDER BY dias_mora DESC, pendiente DESC";

        $st = $this->pdo->prepare($sql);
        if ($suc) $st->bindValue(':suc', $suc);
        $st->execute();
        $rows = $st->fetchAll();

        $total = 0.0;
        $lista = array_map(function ($r) use (&$total) {
            $p = (float) $r['pendiente'];
            $total += $p;
            return [
                'folio'    => $r['folio'],
                'nombre'   => $r['nombre'],
                'telefono' => $r['telefono'],
                'pendiente'=> $p,
                'desde'    => $r['desde_mas_viejo'],
                'diasMora' => max(0, (int) $r['dias_mora']),
                'cobros'   => (int) $r['cobros'],
            ];
        }, $rows);

        return $this->json([
            'total'    => $total,
            'alumnos'  => count($lista),
            'cartera'  => $lista,
        ]);
    }
}
