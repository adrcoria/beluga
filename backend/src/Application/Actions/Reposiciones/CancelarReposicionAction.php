<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Reposiciones;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Reposicion\ReposicionRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class CancelarReposicionAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly ReposicionRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $this->repo->cancelar((string) $this->args['id']);
        return $this->json(['ok' => true]);
    }
}
