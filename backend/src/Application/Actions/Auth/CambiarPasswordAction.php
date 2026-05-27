<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Auth;

use Beluga\Application\Actions\Action;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class CambiarPasswordAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PDO $pdo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $usuarioId   = $this->request->getAttribute('usuario_id');
        $body        = $this->getBody();
        $nuevaPass   = $body['passwordNueva'] ?? '';

        if (strlen($nuevaPass) < 8) {
            return $this->error('La contraseña debe tener al menos 8 caracteres', 422);
        }

        $this->pdo->prepare(
            'UPDATE usuarios SET password_hash = ?, debe_cambiar_password = 0 WHERE id = ?'
        )->execute([password_hash($nuevaPass, PASSWORD_BCRYPT), $usuarioId]);

        return $this->json(['ok' => true]);
    }
}
