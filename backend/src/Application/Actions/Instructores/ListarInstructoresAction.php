<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Instructores;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Instructor\InstructorRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListarInstructoresAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly InstructorRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $sucursal = $this->getQueryParam('sucursal');
        $todos    = $this->getQueryParam('todos') === 'true';
        return $this->json($this->repo->listar($sucursal, $todos));
    }
}
