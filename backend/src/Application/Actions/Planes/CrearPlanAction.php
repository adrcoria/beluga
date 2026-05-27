<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Planes;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Plan\PlanRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class CrearPlanAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PlanRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $body = $this->getBody();
        if (empty($body['modalidad']) || empty($body['clasesSemana']) || !isset($body['mensualidad'])) {
            return $this->error('modalidad, clasesSemana y mensualidad son requeridos', 422);
        }

        try {
            $plan = $this->repo->crear($body);
            return $this->json($plan, 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }
    }
}
