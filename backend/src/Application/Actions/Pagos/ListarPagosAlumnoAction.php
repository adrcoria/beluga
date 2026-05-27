<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Pagos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Pago\PagoService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListarPagosAlumnoAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PagoService $servicio
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $folio = $this->args['folio'];
        $pagos = $this->servicio->porAlumno($folio);
        return $this->json($pagos);
    }
}
