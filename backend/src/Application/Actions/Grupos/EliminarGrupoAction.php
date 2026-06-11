<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Grupos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Grupo\GrupoRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class EliminarGrupoAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly GrupoRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $this->repo->eliminar((string) $this->args['id']);
        return $this->json(['ok' => true]);
    }
}
