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

/**
 * Reject blank, malformed and known placeholder addresses before PHPMailer.
 * This mirrors the working appraisal mailer while retaining array recipients.
 */
function dynabaseIsDeliverableEmail(mixed $email): bool
{
    $email = strtolower(trim((string) $email));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $domain = substr(strrchr($email, '@') ?: '', 1);
    if (
        $domain === ''
        || $domain === 'invalid'
        || str_ends_with($domain, '.invalid')
    ) {
        return false;
    }

    if (
        str_starts_with($email, 'legacy.')
        || str_contains($email, '@archive.invalid')
    ) {
        return false;
    }

    return true;
}

/**
 * SMTP diagnostic logger. MAIL_LOG_PATH may point to a private writable file.
 * When it is not configured, messages are written to the PHP error log.
 */
function dynabaseLogMailEvent(string $message, array $context = []): void
{
    $logPath = dynabaseMailEnv(['MAIL_LOG_PATH']);
    $line = sprintf(
        "[%s] %s%s%s",
        date('Y-m-d H:i:s'),
        $message,
        $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '',
        PHP_EOL
    );

    if ($logPath !== '') {
        $directory = dirname($logPath);
        if (is_dir($directory) && is_writable($directory)) {
            error_log($line, 3, $logPath);
            return;
        }
    }

    error_log(rtrim($line));
}

function dynabaseConfiguredEncryption(PHPMailer $mail, string $encryption, int $port): void
{
    $encryption = strtolower(trim($encryption));

    if ($encryption === '' || $encryption === 'auto') {
        $encryption = $port === 465 ? 'smtps' : 'tls';
    }

    if (in_array($encryption, ['smtps', 'ssl'], true)) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->SMTPAutoTLS = false;
        return;
    }

    if (in_array($encryption, ['tls', 'starttls'], true)) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
        return;
    }

    if (in_array($encryption, ['none', 'off', 'false'], true)) {
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
        return;
    }

    throw new RuntimeException(
        'SMTP_ENCRYPTION must be smtps, ssl, tls, starttls, none or auto.'
    );
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
        $smtpHost = dynabaseRequiredMailSetting(['SMTP_HOST'], 'SMTP_HOST');
        $smtpPort = max(1, (int) dynabaseMailEnv(['SMTP_PORT'], '465'));
        $smtpAuth = envBool('SMTP_AUTH', true);
        $smtpUser = $smtpAuth
            ? dynabaseRequiredMailSetting(['SMTP_USERNAME', 'SMTP_USER'], 'SMTP_USERNAME')
            : dynabaseMailEnv(['SMTP_USERNAME', 'SMTP_USER']);
        $smtpPass = $smtpAuth
            ? dynabaseRequiredMailSetting(['SMTP_PASSWORD', 'SMTP_PASS'], 'SMTP_PASSWORD')
            : dynabaseMailEnv(['SMTP_PASSWORD', 'SMTP_PASS']);

        if (str_contains($smtpHost, '@') || str_contains($smtpHost, '://')) {
            throw new RuntimeException(
                'SMTP_HOST must be a mail-server hostname such as mail.example.com, not an email address or URL.'
            );
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->Port = $smtpPort;
        $mail->SMTPAuth = $smtpAuth;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;

        dynabaseConfiguredEncryption(
            $mail,
            dynabaseMailEnv(['SMTP_ENCRYPTION'], $smtpPort === 465 ? 'smtps' : 'tls'),
            $smtpPort
        );

        // Match the known-good appraisal transport behaviour.
        $mail->Timeout = max(5, min((int) dynabaseMailEnv(['SMTP_TIMEOUT'], '30'), 120));
        $mail->getSMTPInstance()->Timelimit = max(5, min((int) dynabaseMailEnv(['SMTP_TIME_LIMIT'], '30'), 120));
        $mail->SMTPKeepAlive = false;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        // Leave PHPMailer on its default transfer encoding instead of forcing
        // the entire message to Base64. This is friendlier to more mail filters.

        $authType = dynabaseMailEnv(['SMTP_AUTH_TYPE']);
        if ($authType !== '') {
            $mail->AuthType = $authType;
        }

        if (envBool('SMTP_DEBUG', false)) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = static function (string $message, int $level): void {
                dynabaseLogMailEvent('SMTP debug', [
                    'level' => $level,
                    'message' => trim($message),
                ]);
            };
        } else {
            $mail->SMTPDebug = 0;
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

        /*
         * Use the authenticated mailbox as the default From and envelope sender,
         * exactly like the working appraisal mailer. A separate From address is
         * still supported, but the envelope sender remains authenticated so SPF,
         * bounce processing and recipient-server checks are more consistent.
         */
        $fromEmail = dynabaseMailEnv(
            ['SMTP_FROM_EMAIL', 'MAIL_FROM_ADDRESS'],
            $smtpUser
        );
        if (!dynabaseIsDeliverableEmail($fromEmail)) {
            throw new RuntimeException(
                'SMTP_FROM_EMAIL is not configured with a valid deliverable email address.'
            );
        }

        $fromName = dynabaseMailEnv(
            ['SMTP_FROM_NAME', 'MAIL_FROM_NAME'],
            'Lambert Electromec'
        );
        $mail->setFrom($fromEmail, $fromName !== '' ? $fromName : 'Lambert Electromec');

        $returnPath = dynabaseMailEnv(
            ['SMTP_RETURN_PATH'],
            dynabaseIsDeliverableEmail($smtpUser) ? $smtpUser : $fromEmail
        );
        if (dynabaseIsDeliverableEmail($returnPath)) {
            $mail->Sender = $returnPath;
        }

        $replyToEmail = dynabaseMailEnv(
            ['SMTP_REPLY_TO_EMAIL', 'MAIL_REPLY_TO_ADDRESS'],
            $fromEmail
        );
        if (dynabaseIsDeliverableEmail($replyToEmail)) {
            $replyToName = dynabaseMailEnv(
                ['SMTP_REPLY_TO_NAME', 'MAIL_REPLY_TO_NAME'],
                $fromName
            );
            $mail->addReplyTo($replyToEmail, $replyToName);
        }

        return $mail;
    } catch (Throwable $exception) {
        dynabaseLogMailEvent('Mail configuration failed.', [
            'error' => $exception->getMessage(),
        ]);
        return $exception->getMessage();
    }
}

function dynabaseMailError(?PHPMailer $mail, Throwable $exception): string
{
    $message = $mail instanceof PHPMailer ? trim((string) $mail->ErrorInfo) : '';
    if ($message === '') {
        $message = $exception->getMessage();
    }

    dynabaseLogMailEvent('Email delivery failed.', ['error' => $message]);
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

        if (!dynabaseIsDeliverableEmail($email)) {
            if ($email !== '') {
                dynabaseLogMailEvent('Recipient skipped: no deliverable email.', [
                    'recipient' => $email,
                ]);
            }
            continue;
        }

        $normalized[strtolower($email)] = [
            'email' => $email,
            'name' => $name,
        ];
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

function dynabaseAddRecipients(
    PHPMailer $mailer,
    array $recipients,
    string $type,
    array &$seen
): void {
    foreach ($recipients as $recipient) {
        $email = strtolower($recipient['email']);
        if (isset($seen[$email])) {
            continue;
        }

        if ($type === 'to') {
            $mailer->addAddress($recipient['email'], $recipient['name']);
        } elseif ($type === 'cc') {
            $mailer->addCC($recipient['email'], $recipient['name']);
        } else {
            $mailer->addBCC($recipient['email'], $recipient['name']);
        }

        $seen[$email] = true;
    }
}

function sendDynabaseMail(array $options): bool|string
{
    if (dynabaseMailTransport() === 'log') {
        return dynabaseWriteMailLog($options);
    }

    $to = dynabaseNormalizeRecipients($options['to'] ?? []);
    $cc = dynabaseNormalizeRecipients($options['cc'] ?? []);
    $bcc = dynabaseNormalizeRecipients($options['bcc'] ?? []);

    if ($to === []) {
        dynabaseLogMailEvent('Mail skipped: no deliverable primary recipient.', [
            'subject' => (string) ($options['subject'] ?? 'Dynabase notification'),
        ]);
        return 'skipped_no_deliverable_email';
    }

    $mailer = configuredMailer();
    if (is_string($mailer)) {
        return $mailer;
    }

    try {
        $seen = [];
        dynabaseAddRecipients($mailer, $to, 'to', $seen);
        dynabaseAddRecipients($mailer, $cc, 'cc', $seen);
        dynabaseAddRecipients($mailer, $bcc, 'bcc', $seen);

        $subject = (string) ($options['subject'] ?? 'Dynabase notification');
        $html = (string) ($options['html'] ?? '');
        $text = trim((string) ($options['text'] ?? '')) !== ''
            ? (string) $options['text']
            : dynabaseEmailTextFromHtml($html);

        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = $html;
        $mailer->AltBody = $text;

        dynabaseLogMailEvent('Attempting email delivery.', [
            'to' => array_column($to, 'email'),
            'cc_count' => count($cc),
            'bcc_count' => count($bcc),
            'subject' => $subject,
            'smtp_host' => $mailer->Host,
            'smtp_port' => $mailer->Port,
            'encryption' => (string) $mailer->SMTPSecure,
        ]);

        $mailer->send();

        dynabaseLogMailEvent('Email submitted successfully.', [
            'to' => array_column($to, 'email'),
            'subject' => $subject,
        ]);

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
