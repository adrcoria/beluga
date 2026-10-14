<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Alumnos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Alumno\DatosFiscalesRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class GuardarDatosFiscalesAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly DatosFiscalesRepository $fiscales
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        try {
            return $this->json(
                $this->fiscales->guardar((string) $this->resolveArg('folio'), $this->getBody())
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
