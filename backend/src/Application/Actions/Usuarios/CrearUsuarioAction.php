<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Usuarios;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Usuario\UsuarioRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class CrearUsuarioAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly UsuarioRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $body = $this->getBody();

        if (empty($body['nombre']) || empty($body['email']) || empty($body['rol'])) {
            return $this->error('nombre, email y rol son requeridos', 422);
        }

        $rolesValidos = ['administrador', 'gerencia', 'operativo'];
        if (!in_array($body['rol'], $rolesValidos, true)) {
            return $this->error('Rol no válido', 422);
        }

        try {
            $usuario = $this->repo->crear($body);
            return $this->json($usuario, 201);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return $this->error('Ya existe un usuario con ese email', 409);
            }
            throw $e;
        }
    }
}
