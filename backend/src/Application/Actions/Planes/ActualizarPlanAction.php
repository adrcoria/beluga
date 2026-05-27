<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Planes;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Plan\PlanRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ActualizarPlanAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PlanRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id   = $this->args['id'];
        $body = $this->getBody();

        $plan = $this->repo->actualizarPorId($id, $body);
        if (!$plan) {
            return $this->error('Plan no encontrado', 404);
        }
        return $this->json($plan);
    }
}
