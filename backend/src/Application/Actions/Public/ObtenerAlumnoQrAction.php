<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Public;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Alumno\AlumnoRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/**
 * Endpoint público (sin auth) para la página del QR del alumno.
 * Devuelve solo folio + nombre para confirmar identidad.
 */
class ObtenerAlumnoQrAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AlumnoRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $folio  = (string) $this->args['folio'];
        $alumno = $this->repo->porFolio($folio);
        if (!$alumno) {
            return $this->error('No encontrado', 404);
        }
        return $this->json([
            'folio'  => $alumno['folio'],
            'nombre' => $alumno['nombre'],
        ]);
    }
}
