<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Reposiciones;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Reposicion\ReposicionRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListarReposicionesAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly ReposicionRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $sucursalId = (string) $this->getQueryParam('sucursal', '');
        if ($sucursalId === '') {
            return $this->error('Falta la sucursal', 422);
        }
        $estado = $this->getQueryParam('estado');
        return $this->json($this->repo->listar($sucursalId, $estado ? (string) $estado : null));
    }
}
