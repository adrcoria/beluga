<?php

declare(strict_types=1);

namespace Beluga\Application\Actions\Auth;

use Beluga\Application\Actions\Action;
use Beluga\Infrastructure\Mail\Mailer;
use Firebase\JWT\JWT;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class LoginAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private readonly PDO    $pdo,
        private readonly Mailer $mailer
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $body     = $this->getBody();
        $email    = trim($body['email']    ?? '');
        $password = trim($body['password'] ?? '');

        if (!$email || !$password) {
            return $this->error('Credenciales requeridas', 422);
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, nombre, email, rol, sucursales, password_hash, activo, debe_cambiar_password FROM usuarios WHERE email = ?'
        );
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if (!$usuario || !$usuario['activo'] || !password_verify($password, $usuario['password_hash'])) {
            return $this->error('Credenciales incorrectas', 401);
        }

        // Login normal: emitir JWT directo sin OTP
        if (!$usuario['debe_cambiar_password']) {
            $token = JWT::encode([
                'iss' => 'beluga-api',
                'iat' => time(),
                'exp' => time() + 86400 * 7,
                'sub' => $usuario['id'],
                'rol' => $usuario['rol'],
            ], $_ENV['JWT_SECRET'], 'HS256');

            return $this->json([
                'step'    => 'login_ok',
                'token'   => $token,
                'usuario' => [
                    'id'         => $usuario['id'],
                    'nombre'     => $usuario['nombre'],
                    'email'      => $usuario['email'],
                    'rol'        => $usuario['rol'],
                    'sucursales' => json_decode($usuario['sucursales'], true),
                    'activo'     => (bool) $usuario['activo'],
                ],
            ]);
        }

        // Primer acceso (debe_cambiar_password=1): generar código OTP de 6 dígitos
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Eliminar OTPs anteriores del usuario
        $this->pdo->prepare('DELETE FROM otp_tokens WHERE usuario_id = ?')->execute([$usuario['id']]);

        // Guardar nuevo OTP (válido 5 minutos)
        $otpId   = $this->uuid();
        $expira  = date('Y-m-d H:i:s', time() + 300);
        $this->pdo->prepare(
            'INSERT INTO otp_tokens (id, usuario_id, codigo, expira_en) VALUES (?, ?, ?, ?)'
        )->execute([$otpId, $usuario['id'], $codigo, $expira]);

        // Enviar email (falla silenciosamente en dev si SMTP no está configurado)
        $this->mailer->enviarOtp($usuario['email'], $usuario['nombre'], $codigo);

        // Token de pre-autenticación (sin acceso a la API, solo para verificar OTP)
        $preAuthToken = JWT::encode([
            'purpose' => 'otp_pending',
            'sub'     => $usuario['id'],
            'iat'     => time(),
            'exp'     => time() + 300,
        ], $_ENV['JWT_SECRET'], 'HS256');

        // Enmascarar email para mostrarlo en pantalla
        [$local, $domain] = explode('@', $usuario['email']);
        $emailMasked = substr($local, 0, 2) . str_repeat('*', max(0, strlen($local) - 2)) . '@' . $domain;

        return $this->json([
            'step'        => 'otp',
            'otpToken'    => $preAuthToken,
            'emailMasked' => $emailMasked,
        ]);
    }

    private function uuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
