<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Alumnos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Alumno\AlumnoRepository;
use Beluga\Domain\Cobro\CobroRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ActualizarAlumnoAction extends Action
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
        $folio = $this->args['folio'];
        $datos = $this->getBody();

        $antes = $this->repo->porFolio($folio);
        if (!$antes) {
            return $this->error('Alumno no encontrado', 404);
        }

        $alumno = $this->repo->actualizar($folio, $datos);

        if (!$alumno) {
            return $this->error('Alumno no encontrado', 404);
        }

        // Se inscribió el que vino a probar: al crearlo no se le cobró nada,
        // así que los cobros se generan aquí, al convertirse.
        if ($antes['status'] === 'prueba' && ($alumno['status'] ?? '') === 'activo') {
            $this->cobrosDeInscripcion($alumno);
        }

        return $this->json($alumno);
    }

    private function cobrosDeInscripcion(array $alumno): void
    {
        $monto = (float) ($this->getBody()['montoInscripcion'] ?? 300.00);

        if (!($alumno['inscripcionPagada'] ?? false) && $monto > 0
            && !$this->cobros->tieneInscripcion($alumno['folio'])) {
            $this->cobros->crearInscripcion(
                alumnoFolio: $alumno['folio'],
                monto:       $monto,
                fecha:       $alumno['fechaInicio'],
            );
        }

        // Idempotente: no duplica el periodo si ya existía.
        $this->cobros->crearMensualidadInicial($alumno['folio']);
    }
}
