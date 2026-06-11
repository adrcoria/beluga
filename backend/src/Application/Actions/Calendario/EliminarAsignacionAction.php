<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Calendario;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Calendario\CalendarioService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class EliminarAsignacionAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly CalendarioService $servicio
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id = $this->resolveArg('id');
        if (!$this->servicio->eliminarAsignacion($id)) {
            return $this->error('Asignación no encontrada', 404);
        }
        return $this->json(['ok' => true]);
    }
}
