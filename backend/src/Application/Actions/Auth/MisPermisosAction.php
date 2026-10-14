<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Auth;

use Beluga\Application\Actions\Action;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/**
 * Permisos del usuario con la sesión abierta, para que el frontend sepa qué
 * menús pintar y qué botones deshabilitar.
 *
 * Es informativo: quien decide es PermisosMiddleware en cada petición. Si
 * alguien manipula esta respuesta solo logra ver menús que el servidor le va a
 * rechazar igual.
 */
class MisPermisosAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly array $permisos
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $rol = (string) $this->request->getAttribute('usuario_rol');

        $mios = [];
        foreach ($this->permisos['matriz'] as $modulo => $porRol) {
            $mios[$modulo] = $porRol[$rol] ?? 'no';
        }

        return $this->json(['rol' => $rol, 'modulos' => $mios]);
    }
}
