<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Planes;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Plan\PlanRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListarPlanesAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PlanRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        return $this->json($this->repo->listar());
    }
}
