<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Usuarios;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Usuario\UsuarioRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ActualizarUsuarioAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly UsuarioRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id      = $this->args['id'];
        $body    = $this->getBody();
        $usuario = $this->repo->actualizar($id, $body);

        if (!$usuario) {
            return $this->error('Usuario no encontrado', 404);
        }

        return $this->json($usuario);
    }
}
