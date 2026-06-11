<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Cobros;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Cobro\CobroRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class MarcarFacturaAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly CobroRepository $cobros
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id = (string) $this->args['id'];
        $b  = $this->getBody();

        $requiere  = array_key_exists('requiereFactura', $b)  ? (bool) $b['requiereFactura']  : null;
        $realizada = array_key_exists('facturaRealizada', $b) ? (bool) $b['facturaRealizada'] : null;

        return $this->json($this->cobros->actualizarFactura($id, $requiere, $realizada));
    }
}
