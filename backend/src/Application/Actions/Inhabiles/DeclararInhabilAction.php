<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Inhabiles;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Inhabil\InhabilService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class DeclararInhabilAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly InhabilService $servicio
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $usuarioId = $this->request->getAttribute('usuario_id');
        $body      = $this->getBody();

        // Si es una petición de previsualización (?preview=1), no aplica
        $soloPreview = (bool) $this->getQueryParam('preview', false);

        $resultado = $this->servicio->declarar(
            fecha:       $body['fecha']       ?? '',
            motivo:      $body['motivo']      ?? '',
            sucursalId:  $body['sucursalId']  ?? null,
            usuarioId:   $usuarioId,
            soloPreview: $soloPreview
        );

        return $this->json($resultado, $soloPreview ? 200 : 201);
    }
}
