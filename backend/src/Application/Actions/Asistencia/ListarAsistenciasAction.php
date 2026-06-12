<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Asistencia;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Asistencia\AsistenciaRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListarAsistenciasAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly AsistenciaRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $sucursalId = $this->getQueryParam('sucursal');
        $fecha      = (string) $this->getQueryParam('fecha', date('Y-m-d'));
        return $this->json($this->repo->listar($sucursalId ? (string) $sucursalId : null, $fecha));
    }
}
