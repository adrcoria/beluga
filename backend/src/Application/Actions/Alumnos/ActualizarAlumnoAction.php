<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Alumnos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Alumno\AlumnoRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ActualizarAlumnoAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AlumnoRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $folio = $this->args['folio'];
        $datos = $this->getBody();

        $alumno = $this->repo->actualizar($folio, $datos);

        if (!$alumno) {
            return $this->error('Alumno no encontrado', 404);
        }

        return $this->json($alumno);
    }
}
