<?php

declare(strict_types=1);

namespace Noel\Api\Infrastructure;

use Noel\Api\Domain\PasswordResetNotifier;
use Noel\Api\Http\ApiException;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class SmtpPasswordResetNotifier implements PasswordResetNotifier
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromEmail,
        private readonly string $fromName
    ) {
    }

    public function sendPasswordResetEmail(string $login, string $email, string $temporaryPassword, string $confirmationUrl, int $expiresAt): void
    {
        if (!class_exists(PHPMailer::class)) {
            throw new ApiException(500, 'mailer_missing', 'PHPMailer is not available');
        }
        if (trim($this->host) === '' || trim($this->username) === '' || trim($this->password) === '' || trim($this->fromEmail) === '') {
            throw new ApiException(500, 'smtp_config_missing', 'SMTP configuration is incomplete');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->SMTPAuth = true;
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($email, $login);
            $mail->Subject = 'Réinitialisation de mot de passe';

            $expiryText = gmdate('Y-m-d H:i:s', $expiresAt) . ' UTC';
            $mail->Body = "Bonjour {$login},\n\n"
                . "Votre nouveau mot de passe est : {$temporaryPassword}\n\n"
                . "Pour confirmer le changement, cliquez sur ce lien (valide jusqu'au {$expiryText}) :\n"
                . "{$confirmationUrl}\n\n"
                . "Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.\n";

            $mail->send();
        } catch (PHPMailerException $exception) {
            throw new ApiException(500, 'mail_send_failed', 'Failed to send password reset email: ' . $exception->getMessage());
        }
    }
}

