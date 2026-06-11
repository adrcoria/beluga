<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Calendario;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Horario\HorarioRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ObtenerHorarioAlumnoAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly HorarioRepository $horarios
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $folio = (string) $this->args['folio'];
        return $this->json($this->horarios->porAlumno($folio));
    }
}
