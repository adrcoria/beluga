<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Cobros;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Cobro\CobroRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class PagoAdelantadoAction extends Action
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

        if (empty($body['programaId']) || empty($body['metodo']) || empty($body['sucursalId'])) {
            return $this->error('programaId, metodo y sucursalId son requeridos');
        }

        $meses = (int) ($body['meses'] ?? 1);
        if ($meses < 1) {
            return $this->error('Los meses deben ser al menos 1');
        }

        try {
            $cobro = $this->cobros->registrarPagoAdelantado(
                programaId:      (string) $body['programaId'],
                meses:           $meses,
                metodo:          $body['metodo'],
                usuarioId:       $usuarioId,
                sucursalId:      $body['sucursalId'],
                requiereFactura: (bool) ($body['requiereFactura'] ?? false),
            );
            return $this->json($cobro, 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}
