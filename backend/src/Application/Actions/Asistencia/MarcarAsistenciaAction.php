<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Asistencia;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Asistencia\AsistenciaRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pone a mano el estado de un alumno en una clase.
 *
 *   PUT /api/asistencias/marcar
 *   { alumnoFolio, fecha, horaClase, estado: 'asistio'|'no_asistio', sucursalId }
 *
 * Sirve para capturar la pasada de lista sin QR y para corregir un
 * 'no asistió' que puso el cierre automático.
 */
class MarcarAsistenciaAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AsistenciaRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $b = $this->getBody();

        foreach (['alumnoFolio', 'fecha', 'horaClase', 'estado'] as $campo) {
            if (empty($b[$campo])) {
                return $this->error("Falta el campo: $campo", 422);
            }
        }

        try {
            $res = $this->repo->marcar(
                (string) $b['alumnoFolio'],
                (string) $b['fecha'],
                (string) $b['horaClase'],
                (string) $b['estado'],
                $b['sucursalId'] ?? null,
                $this->request->getAttribute('usuario_id'),
            );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 400);
        }

        return $this->json($res);
    }
}
