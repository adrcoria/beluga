<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Pagos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Pago\PagoService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListarPagosAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PagoService $servicio
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $limite = (int) ($this->getQueryParam('limite', '50'));
        $pagos  = $this->servicio->recientes(min($limite, 200));
        return $this->json($pagos);
    }
}
