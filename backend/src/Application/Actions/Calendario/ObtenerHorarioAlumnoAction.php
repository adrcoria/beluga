<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Calendario;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Calendario\CalendarioService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ObtenerHorarioAlumnoAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly CalendarioService $servicio
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $folio = $this->args['folio'];
        return $this->json($this->servicio->horarioPorAlumno($folio));
    }
}
