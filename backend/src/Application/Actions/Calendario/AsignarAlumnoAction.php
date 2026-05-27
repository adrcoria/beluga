<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Calendario;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Calendario\CalendarioService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class AsignarAlumnoAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly CalendarioService $servicio
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $body = $this->getBody();
        // body: { alumnoFolio, slotId, fecha, estado? }
        $asignacion = $this->servicio->asignar(
            folio:   $body['alumnoFolio'] ?? '',
            slotId:  $body['slotId']      ?? '',
            fecha:   $body['fecha']       ?? '',
            estado:  $body['estado']      ?? 'regular'
        );

        return $this->json($asignacion, 201);
    }
}
