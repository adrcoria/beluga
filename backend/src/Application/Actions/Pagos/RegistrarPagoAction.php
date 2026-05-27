<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Pagos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Pago\PagoService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class RegistrarPagoAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PagoService $servicio
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $usuarioId = $this->request->getAttribute('usuario_id');
        $body      = $this->getBody();

        $pago = $this->servicio->registrar(
            datos:     $body,
            usuarioId: $usuarioId
        );

        return $this->json($pago, 201);
    }
}
