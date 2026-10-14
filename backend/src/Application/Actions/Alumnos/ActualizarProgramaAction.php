<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Alumnos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Alumno\AlumnoRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ActualizarProgramaAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AlumnoRepository $alumnos
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $programaId = (string) $this->resolveArg('id');
        $datos      = $this->getBody();

        try {
            $programa = $this->alumnos->actualizarPrograma($programaId, $datos);
            return $this->json($programa);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}
