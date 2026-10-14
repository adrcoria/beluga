<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Alumnos;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Alumno\DatosFiscalesRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ObtenerDatosFiscalesAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly DatosFiscalesRepository $fiscales
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        // null es una respuesta legítima: la mayoría de los alumnos no factura.
        return $this->json($this->fiscales->porAlumno((string) $this->resolveArg('folio')));
    }
}
