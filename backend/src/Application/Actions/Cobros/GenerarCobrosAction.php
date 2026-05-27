<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Cobros;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Cobro\CobroRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class GenerarCobrosAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly CobroRepository $cobros
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $generados = $this->cobros->generarManual();
        return $this->json(['generados' => $generados], 201);
    }
}
