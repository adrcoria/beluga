<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Reposiciones;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Reposicion\ReposicionRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class CrearReposicionAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly ReposicionRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $b = $this->getBody();
        if (empty($b['alumnoFolio']) || empty($b['sucursalId'])) {
            return $this->error('Alumno y sucursal son requeridos', 422);
        }
        $rep = $this->repo->crearManual([
            'alumnoFolio' => $b['alumnoFolio'],
            'sucursalId'  => $b['sucursalId'],
            'motivo'      => $b['motivo'] ?? null,
        ]);
        return $this->json($rep, 201);
    }
}
