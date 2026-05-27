<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Alumnos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Alumno\AlumnoRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListarAlumnosAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AlumnoRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $filtros = [
            'busqueda'   => $this->getQueryParam('q'),
            'modalidad'  => $this->getQueryParam('modalidad'),
            'status'     => $this->getQueryParam('status'),
            'sucursal'   => $this->getQueryParam('sucursal'),
            'toca_pago'  => $this->getQueryParam('toca_pago'),
        ];

        $alumnos = $this->repo->listar(array_filter($filtros));
        return $this->json($alumnos);
    }
}
