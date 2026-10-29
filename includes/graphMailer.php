<?php

declare(strict_types=1);

/**
 * Microsoft Graph application-only mail transport.
 *
 * Graph is configured entirely on the backend. The application registration must
 * have an administrator-consented Mail.Send APPLICATION permission, restricted to
 * the designated sender mailbox in Exchange Online.
 *
 * No secrets or access tokens are logged or returned to callers.
 */

require_once __DIR__ . '/bootstrap.php';

function dynabaseGraphConfig(): array
{
    $required = [
        'tenant' => 'GRAPH_TENANT_ID',
        'client' => 'GRAPH_CLIENT_ID',
        'secret' => 'GRAPH_CLIENT_SECRET',
        'sender' => 'GRAPH_SENDER',
    ];
    $config = [];
    foreach ($required as $field => $key) {
        $config[$field] = trim(envString($key));
        if ($config[$field] === '') {
            throw new RuntimeException("{$key} is not configured.");
        }
    }

    // The endpoints are fixed to Microsoft's hosts, never to user-provided URLs.
    if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $config['tenant'])
        || str_contains($config['tenant'], '..')
        || !filter_var($config['sender'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Microsoft Graph tenant or sender configuration is invalid.');
    }

    return $config;
}

/**
 * POST only to fixed Microsoft HTTPS endpoints; redirects and TLS bypass are disabled.
 * A missing response is reported separately since a send might have reached Graph.
 */
function dynabaseGraphHttpPost(string $url, string $body, array $headers): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Microsoft Graph requires the PHP cURL extension.');
    }

    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Could not initialize the Microsoft Graph connection.');
    }

    try {
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        } elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $networkError = $response === false;
        if ($networkError) {
            dynabaseLogMailEvent('Microsoft Graph connection failed.', [
                'curl_code' => curl_errno($handle),
                'http_status' => $status,
            ]);
        }

        return [
            'http_status' => $status,
            'body' => is_string($response) ? $response : '',
            'network_error' => $networkError,
        ];
    } finally {
        curl_close($handle);
    }
}

function dynabaseGraphAccessToken(array $config): string
{
    static $cached = [];
    $cacheKey = hash('sha256', $config['tenant'] . "\0" . $config['client'] . "\0" . $config['secret']);
    if (isset($cached[$cacheKey]) && $cached[$cacheKey]['expires'] > time() + 60) {
        return $cached[$cacheKey]['token'];
    }

    $url = 'https://login.microsoftonline.com/' . rawurlencode($config['tenant']) . '/oauth2/v2.0/token';
    $response = dynabaseGraphHttpPost($url, http_build_query([
        'client_id' => $config['client'],
        'client_secret' => $config['secret'],
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ], '', '&', PHP_QUERY_RFC3986), ['Content-Type: application/x-www-form-urlencoded']);

    $payload = json_decode($response['body'], true);
    if ($response['network_error'] || $response['http_status'] !== 200
        || !is_array($payload) || !is_string($payload['access_token'] ?? null)
        || $payload['access_token'] === '') {
        dynabaseLogMailEvent('Microsoft Graph token request was rejected.', [
            'http_status' => $response['http_status'],
            'error_code' => is_array($payload) ? substr((string) ($payload['error'] ?? ''), 0, 80) : '',
        ]);
        throw new RuntimeException('Microsoft Graph authentication failed. Check app credentials and permissions.');
    }

    $cached[$cacheKey] = [
        'token' => $payload['access_token'],
        'expires' => time() + max(60, (int) ($payload['expires_in'] ?? 3600)),
    ];

    return $cached[$cacheKey]['token'];
}

/** Keep each address only once across TO, CC and BCC, like the SMTP mailer. */
function dynabaseGraphRecipients(array $to, array $cc, array $bcc): array
{
    $seen = [];
    $result = ['toRecipients' => [], 'ccRecipients' => [], 'bccRecipients' => []];
    foreach (['toRecipients' => $to, 'ccRecipients' => $cc, 'bccRecipients' => $bcc] as $type => $recipients) {
        foreach ($recipients as $recipient) {
            $email = strtolower(trim((string) ($recipient['email'] ?? '')));
            if (isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $address = ['address' => (string) $recipient['email']];
            if (!empty($recipient['name'])) {
                $address['name'] = (string) $recipient['name'];
            }
            $result[$type][] = ['emailAddress' => $address];
        }
    }
    return $result;
}

function dynabaseGraphPayload(array $options, array $config): array
{
    $recipients = dynabaseGraphRecipients(
        $options['to'] ?? [],
        $options['cc'] ?? [],
        $options['bcc'] ?? []
    );
    if (!$recipients['toRecipients']) {
        throw new RuntimeException('Microsoft Graph requires a primary recipient.');
    }

    $html = (string) ($options['html'] ?? '');
    $text = (string) ($options['text'] ?? '');
    $body = $html !== '' ? $html : nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $message = [
        'subject' => (string) ($options['subject'] ?? 'Dynabase notification'),
        'body' => ['contentType' => 'HTML', 'content' => $body],
        'toRecipients' => $recipients['toRecipients'],
    ];
    if ($recipients['ccRecipients']) {
        $message['ccRecipients'] = $recipients['ccRecipients'];
    }
    if ($recipients['bccRecipients']) {
        $message['bccRecipients'] = $recipients['bccRecipients'];
    }
    $replyTo = trim(envString('MAIL_REPLY_TO_ADDRESS'));
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $message['replyTo'] = [['emailAddress' => [
            'address' => $replyTo,
            'name' => envString('MAIL_REPLY_TO_NAME', 'Lambert Electromec'),
        ]]];
    }
    // The sender is determined by the /users/{sender}/sendMail endpoint; no
    // arbitrary From override is accepted from individual callers.
    return ['message' => $message, 'saveToSentItems' => true];
}

/**
 * Internal result keeps ambiguous outcomes distinct for the future failover layer.
 * "accepted" means Graph returned 202, NOT that the recipient received mail.
 * "uncertain" must never be automatically resent by the backup provider.
 */
function dynabaseGraphSendDetailed(array $options): array
{
    $sendStarted = false;
    try {
        $config = dynabaseGraphConfig();
        $payload = dynabaseGraphPayload($options, $config);
        $token = dynabaseGraphAccessToken($config);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($config['sender']) . '/sendMail';
        // Once the send request begins, a subsequent exception cannot prove
        // Graph did not receive it. Never retry those ambiguous outcomes.
        $sendStarted = true;
        $result = dynabaseGraphHttpPost($url, $json, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=utf-8',
        ]);
        if ($result['network_error']) {
            return ['status' => 'uncertain', 'retry_safe' => false, 'http_status' => $result['http_status']];
        }
        if ($result['http_status'] === 202) {
            dynabaseLogMailEvent('Microsoft Graph accepted a message.', [
                'http_status' => 202,
                'to_count' => count($options['to'] ?? []),
            ]);
            return ['status' => 'accepted', 'retry_safe' => false, 'http_status' => 202];
        }

        $payload = json_decode($result['body'], true);
        $errorCode = is_array($payload) ? substr((string) ($payload['error']['code'] ?? ''), 0, 80) : '';
        dynabaseLogMailEvent('Microsoft Graph rejected a message.', [
            'http_status' => $result['http_status'],
            'error_code' => $errorCode,
        ]);
        $http = $result['http_status'];
        return [
            'status' => $http >= 500 || $http === 0 || $http === 408 ? 'uncertain' : 'failed',
            'retry_safe' => $http >= 400 && $http < 500 && $http !== 408,
            'http_status' => $http,
        ];
    } catch (Throwable $exception) {
        // Authentication/configuration errors occur before a send is attempted.
        dynabaseLogMailEvent('Microsoft Graph could not prepare a message.', [
            'error_type' => get_class($exception),
            'error' => $exception->getMessage(),
        ]);
        return [
            'status' => $sendStarted ? 'uncertain' : 'failed',
            'retry_safe' => !$sendStarted,
            'http_status' => 0,
        ];
    }
}
