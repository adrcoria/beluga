<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Reportes;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Ausencia\AusenciaRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/**
 * Reporte de faltas y reposiciones, separando las que dieron derecho de las que
 * no. Es lo que el cliente llamó «con derecho y sin derecho».
 *
 * La diferencia la hace la anticipación con que se avisó, contra el umbral que
 * estaba configurado ese día.
 *
 *   GET /api/reportes/reposiciones?sucursal=&desde=&hasta=&derecho=si|no
 */
class ReposicionesReporteAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AusenciaRepository $ausencias
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $derecho = $this->getQueryParam('derecho');   // 'si' | 'no' | null

        $faltas = $this->ausencias->listar([
            'sucursalId' => $this->getQueryParam('sucursal'),
            'desde'      => $this->getQueryParam('desde'),
            'hasta'      => $this->getQueryParam('hasta'),
            'conDerecho' => $derecho === null ? null : ($derecho === 'si'),
        ]);

        $conDerecho = array_values(array_filter($faltas, fn($f) => $f['conDerecho']));
        $sinDerecho = array_values(array_filter($faltas, fn($f) => !$f['conDerecho']));

        // Cuántos comodines con derecho siguen sin usarse: es el pasivo que la
        // escuela le debe a los alumnos.
        $porUsar = count(array_filter(
            $conDerecho,
            fn($f) => in_array($f['reposicionEstado'], ['pendiente', 'agendada'], true)
        ));

        return $this->json([
            'umbralHoras' => $this->ausencias->umbralHoras(),
            'resumen'     => [
                'total'        => count($faltas),
                'conDerecho'   => count($conDerecho),
                'sinDerecho'   => count($sinDerecho),
                'porUsar'      => $porUsar,
            ],
            'faltas'      => $faltas,
        ]);
    }
}
