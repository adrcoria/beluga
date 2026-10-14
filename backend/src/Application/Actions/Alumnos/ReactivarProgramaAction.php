<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Alumnos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Alumno\AlumnoRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/**
 * Reactiva un programa dado de baja. La fecha de pago es obligatoria: al volver
 * el alumno hay que confirmar con qué ciclo se reincorpora, no heredar el viejo.
 */
class ReactivarProgramaAction extends Action
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
        $fechaPago  = trim((string) ($this->getBody()['fechaPago'] ?? ''));

        if ($fechaPago === '') {
            return $this->error('Confirma la fecha de pago con la que se reincorpora', 422);
        }

        try {
            return $this->json($this->alumnos->reactivarPrograma($programaId, $fechaPago));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}
