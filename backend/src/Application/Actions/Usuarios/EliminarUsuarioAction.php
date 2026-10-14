<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Usuarios;

use Beluga\Application\Actions\Action;
use Beluga\Domain\Usuario\UsuarioRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/**
 * Borra un usuario de verdad. Solo aplica a los que ya están inactivos y no
 * dejaron movimientos: quien cobró, condonó o declaró un día inhábil se queda
 * como inactivo para siempre, porque esos registros tienen que seguir diciendo
 * quién los hizo.
 */
class EliminarUsuarioAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly UsuarioRepository $repo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id = (string) $this->resolveArg('id');

        // Nadie puede borrarse a sí mismo: se quedaría sin sesión a media acción
        if ($id === (string) $this->request->getAttribute('usuario_id')) {
            return $this->error('No puedes eliminar tu propio usuario.', 409);
        }

        try {
            $this->repo->eliminar($id);
            return $this->json(['eliminado' => true]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }
    }
}
