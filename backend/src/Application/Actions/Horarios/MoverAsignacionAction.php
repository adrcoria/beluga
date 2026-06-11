<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Horarios;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Horario\HorarioRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MoverAsignacionAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly HorarioRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id = (string) $this->args['id'];
        $b  = $this->getBody();

        $desdeLunes = $b['desdeLunes'] ?? null;
        if (empty($desdeLunes)) {
            return $this->error('Falta desdeLunes (fecha de efecto del cambio)', 422);
        }

        $cambios = array_filter(
            [
                'instructorId' => $b['instructorId'] ?? null,
                'dia'          => $b['dia']          ?? null,
                'horaInicio'   => $b['horaInicio']   ?? null,
                'duracionMin'  => isset($b['duracionMin']) ? (int) $b['duracionMin'] : null,
            ],
            fn($v) => $v !== null
        );

        try {
            $celda = $this->repo->mover($id, $cambios, $desdeLunes);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 400);
        }

        return $this->json($celda);
    }
}
