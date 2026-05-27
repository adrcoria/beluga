<?php

declare(strict_types=1);

namespace Beluga\Infrastructure\Mail;

use Psr\Log\LoggerInterface;

class Mailer
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function enviarOtp(string $email, string $nombre, string $codigo): void
    {
        // Si PHPMailer no está instalado aún, registra el código en el log (modo desarrollo)
        if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            $this->logger->warning("[OTP-DEV] Código para {$email}: {$codigo}");
            return;
        }

        $host = $_ENV['MAIL_HOST'] ?? '';
        $user = $_ENV['MAIL_USERNAME'] ?? '';

        if (!$host || !$user) {
            $this->logger->warning("[OTP-DEV] SMTP no configurado. Código para {$email}: {$codigo}");
            return;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $user;
            $mail->Password   = $_ENV['MAIL_PASSWORD'] ?? '';
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int) ($_ENV['MAIL_PORT'] ?? 587);
            $mail->CharSet    = 'UTF-8';
            // Necesario en XAMPP/Windows donde OpenSSL no tiene el bundle de CAs de Gmail
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];

            $from     = $_ENV['MAIL_FROM']      ?? $user;
            $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'Acuática Beluga';
            $mail->setFrom($from, $fromName);
            $mail->addAddress($email, $nombre);

            $mail->isHTML(true);
            $mail->Subject = "Tu código de acceso — Acuática Beluga";
            $mail->Body    = $this->plantilla($nombre, $codigo);
            $mail->AltBody = "Tu código de verificación es: {$codigo}. Válido por 5 minutos.";

            $mail->send();
            $this->logger->info("OTP enviado a {$email}");
        } catch (\Throwable $e) {
            $this->logger->error("Error enviando OTP a {$email}: " . $e->getMessage());
            $this->logger->warning("[OTP-DEV] Código para {$email}: {$codigo}");
        }
    }

    private function plantilla(string $nombre, string $codigo): string
    {
        $primerNombre = explode(' ', $nombre)[0];
        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Código de acceso</title></head>
<body style="margin:0;padding:0;background:#f3fbff;font-family:'Segoe UI',Arial,sans-serif">
  <div style="max-width:480px;margin:32px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(10,30,110,.10)">
    <div style="background:linear-gradient(135deg,#e8f4ff,#dbeafe);padding:28px 32px;border-bottom:1px solid #b8d4ff;text-align:center">
      <div style="font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:#1a3fe8;margin-bottom:6px">Acuática Beluga</div>
      <div style="font-size:22px;font-weight:700;color:#0a1e6e">Código de verificación</div>
    </div>
    <div style="padding:32px">
      <p style="color:#374151;font-size:15px;margin:0 0 24px">Hola, <strong>{$primerNombre}</strong>. Usa este código para completar tu acceso:</p>
      <div style="text-align:center;margin:24px 0">
        <span style="display:inline-block;background:#0a1e6e;color:#2fe6b0;font-size:36px;font-weight:900;letter-spacing:12px;padding:16px 28px;border-radius:12px;font-family:monospace">{$codigo}</span>
      </div>
      <p style="color:#9ca3af;font-size:13px;text-align:center;margin:0">Válido por <strong>5 minutos</strong>. No compartas este código.</p>
    </div>
    <div style="background:#f9fafb;padding:16px 32px;text-align:center;border-top:1px solid #e5e7eb">
      <p style="color:#9ca3af;font-size:11px;margin:0">Acuática Beluga · Morelia, Michoacán · Nadar es vida</p>
    </div>
  </div>
</body>
</html>
HTML;
    }
}
