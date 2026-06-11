<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Cobros;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Cobro\CobroRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ObtenerPagosCobroAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly CobroRepository $cobros
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        return $this->json($this->cobros->pagosDe((string) $this->args['id']));
    }
}
