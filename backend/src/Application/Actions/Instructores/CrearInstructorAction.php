<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Instructores;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Instructor\InstructorRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class CrearInstructorAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly InstructorRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $instructor = $this->repo->crear($this->getBody());
        return $this->json($instructor, 201);
    }
}
