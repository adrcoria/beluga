<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Cobros;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Cobro\CobroRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ObtenerCobroAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly CobroRepository $cobros
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $cobro = $this->cobros->porId($this->args['id']);
        if (!$cobro) {
            return $this->error('Cobro no encontrado', 404);
        }
        return $this->json($cobro);
    }
}
