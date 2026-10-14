<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Ausencias;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Ausencia\AusenciaRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/**
 * Marca que un alumno no va a venir a una clase.
 *
 * Si avisó con la anticipación mínima configurada, la falta da derecho a
 * reposición y el comodín se genera solo, ligado a esta falta.
 */
class RegistrarAusenciaAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AusenciaRepository $ausencias
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $b = $this->getBody();

        foreach (['alumnoFolio', 'sucursalId', 'fecha', 'horaClase'] as $campo) {
            if (empty($b[$campo])) {
                return $this->error("Falta el campo: $campo", 422);
            }
        }

        try {
            $ausencia = $this->ausencias->registrar([
                'alumnoFolio' => $b['alumnoFolio'],
                'sucursalId'  => $b['sucursalId'],
                'fecha'       => $b['fecha'],
                'horaClase'   => $b['horaClase'],
                'horarioId'   => $b['horarioId'] ?? null,
                'motivo'      => $b['motivo']    ?? null,
                'usuarioId'   => $this->request->getAttribute('usuario_id'),
            ]);
            return $this->json($ausencia, 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }
    }
}
