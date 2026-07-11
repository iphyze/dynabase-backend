<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/emailTemplates.php';

use PHPMailer\PHPMailer\PHPMailer;

function dynabaseMailEnv(array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        $value = trim(envString((string) $key));
        if ($value !== '') {
            return $value;
        }
    }

    return $default;
}

function dynabaseRequiredMailSetting(array $keys, string $label): string
{
    $value = dynabaseMailEnv($keys);
    if ($value === '') {
        throw new RuntimeException($label . ' is not configured.');
    }

    return $value;
}

function dynabaseMailTransport(): string
{
    return strtolower(trim(envString('MAIL_DRIVER', 'smtp'))) === 'log' ? 'log' : 'smtp';
}

function configuredMailer(): PHPMailer|string
{
    if (!envBool('MAIL_ENABLED', false)) {
        return 'Mail is disabled. Set MAIL_ENABLED=true in the backend .env file.';
    }

    if (!class_exists(PHPMailer::class)) {
        return 'PHPMailer is not installed. Run composer install in the backend folder.';
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->SMTPAuth = envBool('SMTP_AUTH', true);
        $mail->Host = dynabaseRequiredMailSetting(['SMTP_HOST'], 'SMTP_HOST');
        $mail->Port = max(1, (int) dynabaseMailEnv(['SMTP_PORT'], '587'));

        if ($mail->SMTPAuth) {
            $mail->Username = dynabaseRequiredMailSetting(['SMTP_USERNAME', 'SMTP_USER'], 'SMTP_USERNAME');
            $mail->Password = dynabaseRequiredMailSetting(['SMTP_PASSWORD', 'SMTP_PASS'], 'SMTP_PASSWORD');
        }

        /*
         * Match the SMTP behaviour that is already working in the user's other
         * application. Port 587 with SMTP_ENCRYPTION=tls is allowed to negotiate
         * STARTTLS automatically instead of forcing the crypto mode before the
         * server advertises its capabilities. Explicit SMTPS and no-encryption
         * modes remain available where required.
         */
        $encryption = strtolower(dynabaseMailEnv(['SMTP_ENCRYPTION'], 'auto'));
        if (in_array($encryption, ['smtps', 'ssl'], true)) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->SMTPAutoTLS = false;
        } elseif (in_array($encryption, ['none', 'off', 'false'], true)) {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        } else {
            // Covers tls, starttls and auto. PHPMailer upgrades the connection
            // after the server advertises STARTTLS support.
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = true;
        }

        $mail->Timeout = max(5, min((int) dynabaseMailEnv(['SMTP_TIMEOUT'], '20'), 60));
        // $mail->Timelimit = max(5, min((int) dynabaseMailEnv(['SMTP_TIME_LIMIT'], '30'), 60));
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->SMTPKeepAlive = false;

        $authType = trim(dynabaseMailEnv(['SMTP_AUTH_TYPE']));
        if ($authType !== '') {
            $mail->AuthType = $authType;
        }

        if (envBool('SMTP_DEBUG', false)) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = static function (string $message, int $level): void {
                error_log('[Dynabase SMTP ' . $level . '] ' . trim($message));
            };
        }

        if (!envBool('SMTP_VERIFY_PEER', true)) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        $fromEmail = dynabaseMailEnv(
            ['SMTP_FROM_EMAIL', 'MAIL_FROM_ADDRESS'],
            $mail->Username
        );
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP_FROM_EMAIL is not configured with a valid email address.');
        }

        $fromName = dynabaseMailEnv(
            ['SMTP_FROM_NAME', 'MAIL_FROM_NAME'],
            'Lambert Electromec'
        );
        $mail->setFrom($fromEmail, $fromName !== '' ? $fromName : 'Lambert Electromec');

        $replyToEmail = dynabaseMailEnv(['SMTP_REPLY_TO_EMAIL', 'MAIL_REPLY_TO_ADDRESS']);
        if ($replyToEmail !== '' && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
            $replyToName = dynabaseMailEnv(
                ['SMTP_REPLY_TO_NAME', 'MAIL_REPLY_TO_NAME'],
                $fromName
            );
            $mail->addReplyTo($replyToEmail, $replyToName);
        }

        return $mail;
    } catch (Throwable $exception) {
        error_log('[Dynabase Mail Configuration] ' . $exception->getMessage());
        return $exception->getMessage();
    }
}

function dynabaseMailError(?PHPMailer $mail, Throwable $exception): string
{
    $message = $mail instanceof PHPMailer ? trim((string) $mail->ErrorInfo) : '';
    if ($message === '') {
        $message = $exception->getMessage();
    }

    error_log('[Dynabase Mail] ' . $message);
    return $message;
}

function dynabaseNormalizeRecipients(array $recipients): array
{
    $normalized = [];
    foreach ($recipients as $recipient) {
        if (is_string($recipient)) {
            $email = trim($recipient);
            $name = '';
        } else {
            $email = trim((string) ($recipient['email'] ?? ''));
            $name = trim((string) ($recipient['name'] ?? ''));
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $normalized[strtolower($email)] = ['email' => $email, 'name' => $name];
        }
    }

    return array_values($normalized);
}

function dynabaseWriteMailLog(array $options): bool|string
{
    try {
        $directory = dirname(__DIR__) . '/storage';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the local mail log directory.');
        }

        $to = implode(', ', array_column(dynabaseNormalizeRecipients($options['to'] ?? []), 'email'));
        $cc = implode(', ', array_column(dynabaseNormalizeRecipients($options['cc'] ?? []), 'email'));
        $bcc = implode(', ', array_column(dynabaseNormalizeRecipients($options['bcc'] ?? []), 'email'));
        $entry = sprintf(
            "[%s]\nTO: %s\nCC: %s\nBCC: %s\nSUBJECT: %s\n%s\n\n",
            date('c'),
            $to,
            $cc,
            $bcc,
            (string) ($options['subject'] ?? 'Dynabase notification'),
            (string) ($options['text'] ?? dynabaseEmailTextFromHtml((string) ($options['html'] ?? '')))
        );
        file_put_contents($directory . '/mail.log', $entry, FILE_APPEND | LOCK_EX);
        return true;
    } catch (Throwable $exception) {
        return dynabaseMailError(null, $exception);
    }
}

function sendDynabaseMail(array $options): bool|string
{
    if (dynabaseMailTransport() === 'log') {
        return dynabaseWriteMailLog($options);
    }

    $mailer = configuredMailer();
    if (is_string($mailer)) {
        return $mailer;
    }

    try {
        foreach (dynabaseNormalizeRecipients($options['to'] ?? []) as $recipient) {
            $mailer->addAddress($recipient['email'], $recipient['name']);
        }
        foreach (dynabaseNormalizeRecipients($options['cc'] ?? []) as $recipient) {
            $mailer->addCC($recipient['email'], $recipient['name']);
        }
        foreach (dynabaseNormalizeRecipients($options['bcc'] ?? []) as $recipient) {
            $mailer->addBCC($recipient['email'], $recipient['name']);
        }

        if (count($mailer->getToAddresses()) === 0) {
            throw new RuntimeException('At least one recipient email address is required.');
        }

        $mailer->isHTML(true);
        $mailer->Subject = (string) ($options['subject'] ?? 'Dynabase notification');
        $mailer->Body = (string) ($options['html'] ?? '');
        $mailer->AltBody = trim((string) ($options['text'] ?? '')) !== ''
            ? (string) $options['text']
            : dynabaseEmailTextFromHtml((string) ($options['html'] ?? ''));
        $mailer->send();

        return true;
    } catch (Throwable $exception) {
        return dynabaseMailError($mailer, $exception);
    }
}

function sendDynabaseTemplateEmail(
    string $templateKey,
    array $data,
    array $to,
    array $cc = [],
    array $bcc = []
): bool|string {
    try {
        $template = dynabaseRenderEmailTemplate($templateKey, $data);
        return sendDynabaseMail([
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $template['subject'],
            'html' => $template['html'],
            'text' => $template['text'],
        ]);
    } catch (Throwable $exception) {
        return dynabaseMailError(null, $exception);
    }
}

function sendInvitationEmail(string $toEmail, string $inviteUrl, string $role): bool|string
{
    return sendDynabaseTemplateEmail(
        'user_invitation',
        [
            'role' => $role,
            'invite_url' => $inviteUrl,
            'expires_text' => envString('INVITATION_EXPIRES_DAYS', '7') . ' days',
        ],
        [['email' => $toEmail]]
    );
}

function sendTemporaryPasswordEmail(string $toEmail, string $temporaryPassword): bool|string
{
    return sendDynabaseTemplateEmail(
        'password_reset',
        [
            'temporary_password' => $temporaryPassword,
            'login_url' => rtrim(envString('FRONTEND_URL', 'http://localhost:5173'), '/') . '/login',
        ],
        [['email' => $toEmail]]
    );
}

function sendClientSurveyInvitationEmail(array $survey): bool|string
{
    $recipientEmail = trim((string) ($survey['recipient_email'] ?? ''));
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return 'The survey recipient email address is invalid.';
    }

    return sendDynabaseTemplateEmail(
        'client_survey_invitation',
        $survey,
        [[
            'email' => $recipientEmail,
            'name' => trim((string) ($survey['recipient_name'] ?? '')),
        ]]
    );
}

function surveyNotificationEmailList(string $key, string $default = ''): array
{
    $values = array_map('trim', explode(',', envString($key, $default)));
    $valid = [];

    foreach ($values as $email) {
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $valid[strtolower($email)] = $email;
        }
    }

    return array_values($valid);
}

function sendClientSurveyNotifications(array $survey): bool|string
{
    $primaryRecipients = surveyNotificationEmailList(
        'SURVEY_NOTIFICATION_TO',
        'i.nzekwue@lambertelectromec.com'
    );
    $legacyRecipients = surveyNotificationEmailList('SURVEY_NOTIFICATION_EMAILS');
    $toRecipients = [];
    foreach (array_merge($primaryRecipients, $legacyRecipients) as $email) {
        $toRecipients[strtolower($email)] = ['email' => $email];
    }

    $internalResult = true;
    if ($toRecipients !== []) {
        $internalResult = sendDynabaseTemplateEmail(
            'client_survey_internal',
            $survey,
            array_values($toRecipients),
            array_map(static fn (string $email): array => ['email' => $email], surveyNotificationEmailList('SURVEY_NOTIFICATION_CC')),
            array_map(static fn (string $email): array => ['email' => $email], surveyNotificationEmailList('SURVEY_NOTIFICATION_BCC'))
        );
    }

    $clientEmail = trim((string) ($survey['email'] ?? ''));
    $clientResult = true;
    if (filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
        $clientResult = sendDynabaseTemplateEmail(
            'client_survey_confirmation',
            $survey,
            [[
                'email' => $clientEmail,
                'name' => trim((string) ($survey['filled_by'] ?? 'Client')),
            ]]
        );
    }

    if ($internalResult !== true) {
        return $internalResult;
    }
    if ($clientResult !== true) {
        return $clientResult;
    }

    return true;
}
