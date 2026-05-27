<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Calendario;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Calendario\CalendarioService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ActualizarAsignacionAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly CalendarioService $servicio
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id   = $this->args['id'];
        $body = $this->getBody();
        // body: { estado?, nota? }
        $asignacion = $this->servicio->actualizarAsignacion($id, $body);

        if (!$asignacion) {
            return $this->error('Asignación no encontrada', 404);
        }

        return $this->json($asignacion);
    }
}
