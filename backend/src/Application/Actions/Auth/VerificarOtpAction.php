<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Auth;

use Beluga\Application\Actions\Action;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class VerificarOtpAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PDO $pdo
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $body      = $this->getBody();
        $otpToken  = $body['otpToken'] ?? '';
        $codigo    = trim($body['codigo'] ?? '');

        if (!$otpToken || !$codigo) {
            return $this->error('otpToken y codigo son requeridos', 422);
        }

        // Verificar el pre-auth token
        try {
            $decoded = JWT::decode($otpToken, new Key($_ENV['JWT_SECRET'], 'HS256'));
        } catch (\Throwable) {
            return $this->error('Token expirado. Inicia sesión nuevamente.', 401);
        }

        if (($decoded->purpose ?? '') !== 'otp_pending') {
            return $this->error('Token inválido', 401);
        }

        $usuarioId = $decoded->sub;

        // Buscar OTP válido
        $stmt = $this->pdo->prepare(
            'SELECT id FROM otp_tokens
             WHERE usuario_id = ? AND codigo = ? AND usado = 0 AND expira_en > NOW()
             LIMIT 1'
        );
        $stmt->execute([$usuarioId, $codigo]);
        $otp = $stmt->fetch();

        if (!$otp) {
            return $this->error('Código incorrecto o expirado', 401);
        }

        // Marcar OTP como usado
        $this->pdo->prepare('UPDATE otp_tokens SET usado = 1 WHERE id = ?')->execute([$otp['id']]);

        // Cargar usuario completo
        $stmt = $this->pdo->prepare(
            'SELECT id, nombre, email, rol, sucursales, activo, debe_cambiar_password FROM usuarios WHERE id = ?'
        );
        $stmt->execute([$usuarioId]);
        $usuario = $stmt->fetch();

        if (!$usuario || !$usuario['activo']) {
            return $this->error('Acceso denegado', 403);
        }

        // Emitir JWT completo
        $token = JWT::encode([
            'iss' => 'beluga-api',
            'iat' => time(),
            'exp' => time() + 86400 * 7,
            'sub' => $usuario['id'],
            'rol' => $usuario['rol'],
        ], $_ENV['JWT_SECRET'], 'HS256');

        $debeCambiar = (bool) $usuario['debe_cambiar_password'];
        unset($usuario['debe_cambiar_password']);
        $usuario['sucursales'] = json_decode($usuario['sucursales'], true);

        return $this->json([
            'token'                => $token,
            'usuario'              => $usuario,
            'debeChangiarPassword' => $debeCambiar,
        ]);
    }
}
