<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Reposiciones;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Reposicion\ReposicionRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AgendarReposicionAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly ReposicionRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id = (string) $this->args['id'];
        $b  = $this->getBody();

        foreach (['fecha', 'instructorId', 'horaInicio'] as $req) {
            if (empty($b[$req])) {
                return $this->error("Falta el campo: $req", 422);
            }
        }

        try {
            $rep = $this->repo->agendar($id, [
                'fecha'        => $b['fecha'],
                'instructorId' => $b['instructorId'],
                'horaInicio'   => $b['horaInicio'],
                'duracionMin'  => (int) ($b['duracionMin'] ?? 30),
            ]);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 400);
        }

        return $this->json($rep);
    }
}
