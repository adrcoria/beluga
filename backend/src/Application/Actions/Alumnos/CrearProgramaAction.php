<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Alumnos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Alumno\AlumnoRepository;
use Beluga\Domain\Cobro\CobroRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class CrearProgramaAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AlumnoRepository $alumnos,
        private readonly CobroRepository  $cobros
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $folio = (string) $this->resolveArg('folio');
        $datos = $this->getBody();

        if (empty($datos['modalidad'])) {
            return $this->error('La modalidad es requerida', 422);
        }

        try {
            $this->alumnos->crearPrograma($folio, $datos);
            // Primera mensualidad del nuevo programa, cobrable de inmediato
            $this->cobros->crearMensualidadInicial($folio);
            return $this->json($this->alumnos->porFolio($folio), 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}
