<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Instructores;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Instructor\InstructorRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ActualizarInstructorAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly InstructorRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id         = $this->resolveArg('id');
        $instructor = $this->repo->actualizar($id, $this->getBody());
        return $this->json($instructor);
    }
}
