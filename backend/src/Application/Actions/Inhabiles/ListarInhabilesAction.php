<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Inhabiles;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Inhabil\InhabilService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListarInhabilesAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly InhabilService $servicio
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $inhabiles = $this->servicio->listar();
        return $this->json($inhabiles);
    }
}
