<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Cobros;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Cobro\CobroRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class RegistrarAbonoAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly CobroRepository $cobros
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $usuarioId = $this->request->getAttribute('usuario_id');
        $body      = $this->getBody();

        if (empty($body['monto']) || empty($body['metodo']) || empty($body['sucursalId'])) {
            return $this->error('monto, metodo y sucursalId son requeridos');
        }

        try {
            $cobro = $this->cobros->registrarAbono(
                cobroId:         $this->args['id'],
                monto:           (float) $body['monto'],
                metodo:          $body['metodo'],
                usuarioId:       $usuarioId,
                sucursalId:      $body['sucursalId'],
                requiereFactura: (bool) ($body['requiereFactura'] ?? false),
                meses:           (int) ($body['meses'] ?? 1),
            );
            return $this->json($cobro, 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}
