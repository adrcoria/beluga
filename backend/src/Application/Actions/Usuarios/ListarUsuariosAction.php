<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Usuarios;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Usuario\UsuarioRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListarUsuariosAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly UsuarioRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        // ?inactivos=1 para ver también a los dados de baja, y poder
        // reactivarlos o eliminarlos.
        $incluirInactivos = filter_var(
            $this->getQueryParam('inactivos', false),
            FILTER_VALIDATE_BOOLEAN
        );

        return $this->json($this->repo->listar($incluirInactivos));
    }
}
