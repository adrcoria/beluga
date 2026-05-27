<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Alumnos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Alumno\AlumnoRepository;
use Beluga\Domain\Cobro\CobroRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class CrearAlumnoAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AlumnoRepository $repo,
        private readonly CobroRepository  $cobros
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $datos  = $this->getBody();
        $alumno = $this->repo->crear($datos);

        // Generar cobro de inscripción si no está pagada y el monto es mayor a cero
        $montoInscripcion = (float) ($datos['montoInscripcion'] ?? 300.00);
        if (!($datos['inscripcionPagada'] ?? false) && $montoInscripcion > 0) {
            $this->cobros->crearInscripcion(
                alumnoFolio: $alumno['folio'],
                monto:       $montoInscripcion,
                fecha:       $alumno['fechaInicio'],
            );
        }

        return $this->json($alumno, 201);
    }
}
