<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Horarios;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Horario\HorarioRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class EliminarAsignacionAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly HorarioRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id         = (string) $this->args['id'];
        $desdeLunes = $this->getQueryParam('desde'); // opcional: cierra desde esa fecha

        $this->repo->eliminar($id, $desdeLunes ? (string) $desdeLunes : null);

        return $this->json(['ok' => true]);
    }
}
