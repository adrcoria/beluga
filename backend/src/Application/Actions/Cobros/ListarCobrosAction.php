<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Cobros;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Cobro\CobroRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListarCobrosAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly CobroRepository $cobros
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $filtros = array_filter([
            'estado'       => $this->getQueryParam('estado'),
            'alumno_folio' => $this->getQueryParam('alumno'),
            'busqueda'     => $this->getQueryParam('q'),
        ]);

        return $this->json($this->cobros->listar($filtros));
    }
}
