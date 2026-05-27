<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Calendario;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Calendario\CalendarioService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ObtenerCalendarioAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly CalendarioService $servicio
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $sucursalId = $this->getQueryParam('sucursal', '');
        $dia        = $this->getQueryParam('dia', '');     // L | M | X | J | V | S
        $fecha      = $this->getQueryParam('fecha', date('Y-m-d'));

        $calendario = $this->servicio->obtenerPizarron($sucursalId, $dia, $fecha);
        return $this->json($calendario);
    }
}
