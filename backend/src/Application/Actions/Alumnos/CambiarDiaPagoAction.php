<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Alumnos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Alumno\AlumnoRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/**
 * Cambia el día de pago de un programa. Aplica desde la siguiente mensualidad;
 * los cobros ya generados no se tocan.
 */
class CambiarDiaPagoAction extends Action
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
        $body       = $this->getBody();

        if (!isset($body['diaPago'])) {
            return $this->error('diaPago es requerido', 422);
        }

        try {
            return $this->json($this->alumnos->cambiarDiaPago($programaId, (int) $body['diaPago']));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}
