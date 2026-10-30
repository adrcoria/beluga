<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Asistencia;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Asistencia\AsistenciaRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/**
 * Pasa de lista de un día: todas las clases programadas con su estado.
 *
 *   GET /api/asistencias?sucursal=&fecha=
 *
 * Si el día ya terminó y nadie lo había cerrado, se cierra aquí mismo: a
 * quien tenía clase y no quedó registrado se le marca 'no asistió'. Se hace
 * al consultar y no con una tarea programada para no depender de un cron en
 * el hosting, que si falla deja días sin cerrar sin que nadie se entere.
 */
class ListarAsistenciasAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AsistenciaRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $sucursalId = (string) $this->getQueryParam('sucursal', '');
        $fecha      = (string) $this->getQueryParam('fecha', date('Y-m-d'));

        if ($sucursalId === '') {
            return $this->error('Falta la sucursal', 422);
        }

        $cierre = $this->repo->cierreDe($sucursalId, $fecha);
        if (!$cierre && $fecha < date('Y-m-d')) {
            try {
                $cierre = $this->repo->cerrarDia($sucursalId, $fecha);
            } catch (\RuntimeException) {
                // Si no se pudo cerrar, el día se muestra abierto igual
            }
        }

        $clases = $this->repo->clasesDelDia($sucursalId, $fecha);

        return $this->json([
            'fecha'   => $fecha,
            'abierto' => $cierre === null,
            'cierre'  => $cierre,
            'resumen' => $this->resumen($clases),
            'clases'  => $clases,
            'ventana' => [
                'minAntes'   => $this->repo->minutosAntes(),
                'retardoMax' => $this->repo->retardoMaximo(),
            ],
        ]);
    }

    /**
     * Quien avisó que no venía no se cuenta igual que quien simplemente no
     * se presentó: es la diferencia entre una falta justificada y un plantón.
     */
    private function resumen(array $clases): array
    {
        $r = ['clases' => count($clases), 'asistieron' => 0, 'faltaronAvisando' => 0, 'faltaronSinAvisar' => 0, 'pendientes' => 0];

        foreach ($clases as $c) {
            if ($c['estado'] === 'asistio') { $r['asistieron']++; continue; }
            if ($c['estado'] === null)      { $r['pendientes']++; continue; }
            $c['aviso'] ? $r['faltaronAvisando']++ : $r['faltaronSinAvisar']++;
        }

        $resueltas = $r['clases'] - $r['pendientes'];
        $r['porcentajeAsistencia'] = $resueltas > 0 ? round($r['asistieron'] * 100 / $resueltas, 1) : null;

        return $r;
    }
}
