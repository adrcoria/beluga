<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Cobros;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Cobro\CobroRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/**
 * "No pedir cobro": condona el mes. El alumno deja de deberlo y su vencimiento
 * avanza como si hubiera pagado. El motivo es obligatorio y queda registrado
 * junto con quién lo autorizó.
 */
class CondonarCobroAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly CobroRepository $cobros
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $motivo = trim((string) ($this->getBody()['motivo'] ?? ''));

        if ($motivo === '') {
            return $this->error('Escribe el motivo por el que no se cobra', 422);
        }

        try {
            return $this->json($this->cobros->condonar(
                (string) $this->args['id'],
                $motivo,
                (string) $this->request->getAttribute('usuario_id')
            ));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}
