<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Grupos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Grupo\GrupoRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class CrearGrupoAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly GrupoRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $b = $this->getBody();
        if (empty($b['nombre']) || empty($b['sucursalId'])) {
            return $this->error('Nombre y sucursal son requeridos', 422);
        }
        $grupo = $this->repo->crear([
            'nombre'     => $b['nombre'],
            'sucursalId' => $b['sucursalId'],
            'alumnos'    => $b['alumnos'] ?? [],
        ]);
        return $this->json($grupo, 201);
    }
}
