<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Reportes;

use Beluga\Application\Actions\Action;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class OcupacionAction extends Action
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

        // Instructores activos (de la sucursal si se indica)
        $sqlI = 'SELECT id, nombre, iniciales FROM instructores WHERE activo = 1';
        if ($suc) $sqlI .= ' AND JSON_CONTAINS(sucursales, :suc)';
        $sqlI .= ' ORDER BY nombre';
        $st = $this->pdo->prepare($sqlI);
        if ($suc) $st->bindValue(':suc', json_encode($suc));
        $st->execute();
        $instructores = $st->fetchAll();

        // Clases recurrentes vigentes hoy, con alumnos por clase
        $sqlH = "SELECT hr.instructor_id,
                        CASE WHEN hr.grupo_id IS NULL THEN 1
                             ELSE (SELECT COUNT(*) FROM grupo_alumnos ga WHERE ga.grupo_id = hr.grupo_id) END AS alumnos
                 FROM horario_recurrente hr
                 WHERE hr.vigente_desde <= CURDATE()
                   AND (hr.vigente_hasta IS NULL OR hr.vigente_hasta >= CURDATE())";
        if ($suc) $sqlH .= ' AND hr.sucursal_id = :suc';
        $st = $this->pdo->prepare($sqlH);
        if ($suc) $st->bindValue(':suc', $suc);
        $st->execute();

        $clasesPorInstr  = [];
        $alumnosPorInstr = [];
        foreach ($st->fetchAll() as $r) {
            $id = $r['instructor_id'];
            $clasesPorInstr[$id]  = ($clasesPorInstr[$id]  ?? 0) + 1;
            $alumnosPorInstr[$id] = ($alumnosPorInstr[$id] ?? 0) + (int) $r['alumnos'];
        }

        $lista = array_map(fn($i) => [
            'id'        => $i['id'],
            'nombre'    => $i['nombre'],
            'iniciales' => $i['iniciales'],
            'clases'    => $clasesPorInstr[$i['id']]  ?? 0,
            'alumnos'   => $alumnosPorInstr[$i['id']] ?? 0,
        ], $instructores);

        return $this->json([
            'instructores'   => $lista,
            'totalClases'    => array_sum(array_column($lista, 'clases')),
            'totalAlumnos'   => array_sum(array_column($lista, 'alumnos')),
        ]);
    }
}
