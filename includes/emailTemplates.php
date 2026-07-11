<?php

declare(strict_types=1);

/**
 * Central Dynabase email-template library.
 *
 * Templates are deliberately code-managed so every transactional email stays
 * version controlled, reviewable and consistent across environments.
 */

function dynabaseEmailTemplateCatalog(): array
{
    return [
        'user_invitation' => [
            'key' => 'user_invitation',
            'name' => 'Workspace invitation',
            'category' => 'Access',
            'audience' => 'Invited team member',
            'description' => 'Welcomes a new team member and guides them to accept their Dynabase invitation.',
            'icon' => 'user-plus',
            'variables' => ['role', 'invite_url', 'expires_text'],
        ],
        'password_reset' => [
            'key' => 'password_reset',
            'name' => 'Temporary password',
            'category' => 'Access',
            'audience' => 'Existing team member',
            'description' => 'Shares a temporary password after an administrator resets a user account.',
            'icon' => 'key-round',
            'variables' => ['temporary_password', 'login_url'],
        ],
        'client_survey_invitation' => [
            'key' => 'client_survey_invitation',
            'name' => 'Client survey invitation',
            'category' => 'Client surveys',
            'audience' => 'Client respondent',
            'description' => 'Invites a client to complete a secure project-experience survey.',
            'icon' => 'send',
            'variables' => ['recipient_name', 'company', 'project_title', 'link', 'expires_at'],
        ],
        'client_survey_internal' => [
            'key' => 'client_survey_internal',
            'name' => 'New survey response',
            'category' => 'Internal notifications',
            'audience' => 'Dynabase administrators',
            'description' => 'Notifies the internal team when a client submits a survey response.',
            'icon' => 'bell-ring',
            'variables' => ['reference', 'company', 'project_title', 'filled_by', 'email', 'overall_score', 'response_url'],
        ],
        'client_survey_confirmation' => [
            'key' => 'client_survey_confirmation',
            'name' => 'Survey confirmation',
            'category' => 'Client surveys',
            'audience' => 'Client respondent',
            'description' => 'Thanks the respondent and confirms that their feedback was received successfully.',
            'icon' => 'badge-check',
            'variables' => ['filled_by', 'project_title', 'reference'],
        ],
    ];
}

function dynabaseEmailTemplateSampleData(string $key): array
{
    $frontend = rtrim(envString('FRONTEND_URL', 'http://localhost:5173'), '/');

    return match ($key) {
        'user_invitation' => [
            'role' => 'Admin',
            'invite_url' => $frontend . '/accept-invitation?token=sample-secure-token',
            'expires_text' => '7 days',
        ],
        'password_reset' => [
            'temporary_password' => 'DB-Temp-4827',
            'login_url' => $frontend . '/login',
        ],
        'client_survey_invitation' => [
            'recipient_name' => 'Amina Yusuf',
            'company' => 'Northstar Energy Services',
            'project_title' => 'Integrated Power Infrastructure Upgrade',
            'link' => $frontend . '/client-survey/sample-secure-token',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
        ],
        'client_survey_internal' => [
            'reference' => 'CSR-2026-00418',
            'company' => 'Northstar Energy Services',
            'project_title' => 'Integrated Power Infrastructure Upgrade',
            'filled_by' => 'Amina Yusuf',
            'email' => 'amina.yusuf@example.com',
            'overall_score' => '91.5',
            'response_url' => $frontend . '/client-surveys/418',
        ],
        'client_survey_confirmation' => [
            'filled_by' => 'Amina Yusuf',
            'project_title' => 'Integrated Power Infrastructure Upgrade',
            'reference' => 'CSR-2026-00418',
        ],
        default => [],
    };
}

function dynabaseEmailEscape(mixed $value): string
{
    return htmlspecialchars(trim((string) $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dynabaseEmailValue(array $data, string $key, string $fallback = ''): string
{
    $value = trim((string) ($data[$key] ?? ''));
    return $value !== '' ? $value : $fallback;
}

function dynabaseEmailTextFromHtml(string $html): string
{
    $withBreaks = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
    $withParagraphs = preg_replace('/<\s*\/p\s*>/i', "\n\n", $withBreaks) ?? $withBreaks;
    $withRows = preg_replace('/<\s*\/tr\s*>/i', "\n", $withParagraphs) ?? $withParagraphs;

    return trim(html_entity_decode(strip_tags($withRows), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function dynabaseEmailInfoRows(array $rows): string
{
    $html = '';
    foreach ($rows as $row) {
        $label = dynabaseEmailEscape($row['label'] ?? '');
        $value = dynabaseEmailEscape($row['value'] ?? '');
        if ($value === '') {
            continue;
        }

        $html .= '<tr>'
            . '<td style="padding:10px 0;color:#6d8296;font-size:12px;line-height:1.45;border-bottom:1px solid #e8eff5;width:38%;vertical-align:top">' . $label . '</td>'
            . '<td style="padding:10px 0;color:#18334b;font-size:13px;line-height:1.45;font-weight:700;border-bottom:1px solid #e8eff5;text-align:right;vertical-align:top">' . $value . '</td>'
            . '</tr>';
    }

    return $html === '' ? '' : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 0;border-collapse:collapse">' . $html . '</table>';
}

function dynabaseEmailLayout(array $options): string
{
    $brand = dynabaseEmailEscape($options['brand'] ?? 'Lambert Electromec');
    $eyebrow = dynabaseEmailEscape($options['eyebrow'] ?? 'DYNABASE NOTIFICATION');
    $title = dynabaseEmailEscape($options['title'] ?? 'A new update is ready');
    $preheader = dynabaseEmailEscape($options['preheader'] ?? $title);
    $intro = (string) ($options['intro_html'] ?? '');
    $body = (string) ($options['body_html'] ?? '');
    $ctaLabel = dynabaseEmailEscape($options['cta_label'] ?? '');
    $ctaUrl = dynabaseEmailEscape($options['cta_url'] ?? '');
    $footerNote = dynabaseEmailEscape($options['footer_note'] ?? 'This is an automated transactional message from Dynabase.');
    $accent = dynabaseEmailEscape($options['accent'] ?? '#3478c5');
    $accentDeep = dynabaseEmailEscape($options['accent_deep'] ?? '#225f9b');
    $badge = dynabaseEmailEscape($options['badge'] ?? 'SECURE');

    $cta = '';
    if ($ctaLabel !== '' && $ctaUrl !== '') {
        $cta = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px 0 8px"><tr><td style="border-radius:12px;background:' . $accent . '">'
            . '<a href="' . $ctaUrl . '" style="display:inline-block;padding:14px 22px;color:#ffffff;text-decoration:none;font-size:13px;line-height:1;font-weight:800;letter-spacing:.01em;border-radius:12px;background:' . $accent . ';box-shadow:0 10px 24px rgba(37,100,164,.2)">' . $ctaLabel . '</a>'
            . '</td></tr></table>';
    }

    return '<!doctype html>'
        . '<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title></head>'
        . '<body style="margin:0;padding:0;background:#edf4f9;color:#173047;font-family:Inter,Segoe UI,Arial,sans-serif;-webkit-font-smoothing:antialiased">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . $preheader . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;background:#edf4f9"><tr><td align="center" style="padding:34px 14px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:650px;border-collapse:separate;background:#ffffff;border:1px solid #dce8f0;border-radius:22px;overflow:hidden;box-shadow:0 24px 70px rgba(27,72,104,.13)">'
        . '<tr><td style="padding:0;background:' . $accentDeep . '">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="padding:30px 32px;background:' . $accentDeep . '">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="vertical-align:middle"><div style="display:inline-block;padding:7px 10px;border-radius:999px;background:rgba(255,255,255,.12);color:#dcecff;font-size:10px;line-height:1;font-weight:800;letter-spacing:.12em">' . $eyebrow . '</div>'
        . '<h1 style="margin:15px 0 0;color:#ffffff;font-size:28px;line-height:1.18;font-weight:800;letter-spacing:-.035em">' . $title . '</h1>'
        . '<p style="margin:9px 0 0;color:#d3e5f3;font-size:13px;line-height:1.65">' . $brand . ' · Project Intelligence</p></td>'
        . '<td align="right" style="vertical-align:top;padding-left:18px"><span style="display:inline-block;padding:8px 11px;border-radius:10px;border:1px solid rgba(255,255,255,.16);color:#dcecff;font-size:9px;font-weight:800;letter-spacing:.11em">' . $badge . '</span></td>'
        . '</tr></table></td></tr></table></td></tr>'
        . '<tr><td style="padding:30px 32px 12px">'
        . '<div style="color:#405b72;font-size:14px;line-height:1.75">' . $intro . $body . '</div>'
        . $cta
        . '</td></tr>'
        . '<tr><td style="padding:20px 32px 28px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e6eef4"><tr>'
        . '<td style="padding-top:18px;color:#7b8fa0;font-size:11px;line-height:1.65">' . $footerNote . '<br><span style="color:#9aacba">© ' . date('Y') . ' ' . $brand . '</span></td>'
        . '<td align="right" style="padding-top:18px;color:#3478c5;font-size:11px;font-weight:800;letter-spacing:.08em">DYNABASE</td>'
        . '</tr></table></td></tr>'
        . '</table></td></tr></table></body></html>';
}

function dynabaseRenderEmailTemplate(string $key, array $data = []): array
{
    $catalog = dynabaseEmailTemplateCatalog();
    if (!isset($catalog[$key])) {
        throw new RuntimeException('Email template not found.', 404);
    }

    $data = array_merge(dynabaseEmailTemplateSampleData($key), $data);
    $subject = '';
    $html = '';

    if ($key === 'user_invitation') {
        $role = dynabaseEmailValue($data, 'role', 'User');
        $inviteUrl = dynabaseEmailValue($data, 'invite_url');
        $expires = dynabaseEmailValue($data, 'expires_text', '7 days');
        $subject = 'You have been invited to Dynabase';
        $html = dynabaseEmailLayout([
            'eyebrow' => 'WORKSPACE INVITATION',
            'title' => 'Your Dynabase access is ready',
            'preheader' => 'Accept your secure Dynabase workspace invitation.',
            'badge' => 'INVITATION',
            'intro_html' => '<p style="margin:0">Hello,</p><p style="margin:14px 0 0">You have been invited to join the Lambert Electromec Dynabase workspace as a <strong style="color:#173047">' . dynabaseEmailEscape($role) . '</strong>.</p>',
            'body_html' => '<div style="margin:22px 0 0;padding:16px 18px;border:1px solid #dce9f2;border-radius:14px;background:#f6fafc"><p style="margin:0;color:#6d8296;font-size:11px;font-weight:800;letter-spacing:.09em;text-transform:uppercase">Access note</p><p style="margin:7px 0 0;color:#23425c;font-size:13px;line-height:1.65">For your security, this invitation expires in <strong>' . dynabaseEmailEscape($expires) . '</strong>. Use the button below to create your password and enter the workspace.</p></div>',
            'cta_label' => 'Accept invitation',
            'cta_url' => $inviteUrl,
            'footer_note' => 'If you were not expecting this invitation, you may safely ignore this email.',
        ]);
    } elseif ($key === 'password_reset') {
        $temporaryPassword = dynabaseEmailValue($data, 'temporary_password');
        $loginUrl = dynabaseEmailValue($data, 'login_url', rtrim(envString('FRONTEND_URL', 'http://localhost:5173'), '/') . '/login');
        $subject = 'Your Dynabase password has been reset';
        $html = dynabaseEmailLayout([
            'eyebrow' => 'ACCOUNT SECURITY',
            'title' => 'A temporary password was created',
            'preheader' => 'Use your temporary password to sign in to Dynabase.',
            'badge' => 'SECURITY',
            'intro_html' => '<p style="margin:0">Hello,</p><p style="margin:14px 0 0">An administrator reset your Dynabase password. Use the temporary password below to sign in.</p>',
            'body_html' => '<div style="margin:22px 0 0;padding:18px;border:1px solid #cfe1ef;border-radius:14px;background:#f5faff;text-align:center"><p style="margin:0;color:#71879a;font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase">Temporary password</p><p style="margin:10px 0 0;color:#153f66;font-family:Consolas,Monaco,monospace;font-size:22px;line-height:1.2;font-weight:800;letter-spacing:.08em">' . dynabaseEmailEscape($temporaryPassword) . '</p></div><p style="margin:18px 0 0;color:#6d8296;font-size:12px">Change this password immediately after signing in. Do not forward or share it.</p>',
            'cta_label' => 'Sign in to Dynabase',
            'cta_url' => $loginUrl,
            'footer_note' => 'If you did not request this reset, contact your Dynabase administrator immediately.',
            'accent' => '#2f78bd',
            'accent_deep' => '#173f66',
        ]);
    } elseif ($key === 'client_survey_invitation') {
        $recipientName = dynabaseEmailValue($data, 'recipient_name', 'Client');
        $company = dynabaseEmailValue($data, 'company');
        $project = dynabaseEmailValue($data, 'project_title');
        $link = dynabaseEmailValue($data, 'link');
        $expiresAt = dynabaseEmailValue($data, 'expires_at');
        $expires = $expiresAt !== '' && strtotime($expiresAt) ? date('j M Y', strtotime($expiresAt)) : 'the stated expiry date';
        $context = 'We would value your perspective on your recent experience working with Lambert Electromec.';
        if ($project !== '' && $company !== '') {
            $context = 'We would value your feedback on <strong style="color:#173047">' . dynabaseEmailEscape($project) . '</strong> for <strong style="color:#173047">' . dynabaseEmailEscape($company) . '</strong>.';
        } elseif ($project !== '') {
            $context = 'We would value your feedback on <strong style="color:#173047">' . dynabaseEmailEscape($project) . '</strong>.';
        } elseif ($company !== '') {
            $context = 'We would value feedback from <strong style="color:#173047">' . dynabaseEmailEscape($company) . '</strong> about your experience working with Lambert Electromec.';
        }
        $subject = 'Your feedback matters — Lambert Electromec client survey';
        $html = dynabaseEmailLayout([
            'brand' => 'Lambert Electromec',
            'eyebrow' => 'CLIENT EXPERIENCE',
            'title' => 'Your feedback helps us build better',
            'preheader' => 'Share your project experience with Lambert Electromec.',
            'badge' => '5 MINUTES',
            'intro_html' => '<p style="margin:0">Dear <strong style="color:#173047">' . dynabaseEmailEscape($recipientName) . '</strong>,</p><p style="margin:14px 0 0">' . $context . '</p>',
            'body_html' => '<div style="margin:22px 0 0;padding:16px 18px;border-radius:14px;background:#f4f9fc;border:1px solid #d9e8f1"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="color:#6c8294;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase">What to expect</td><td align="right" style="color:#3478c5;font-size:12px;font-weight:800">Open until ' . dynabaseEmailEscape($expires) . '</td></tr></table><p style="margin:10px 0 0;color:#405b72;font-size:13px;line-height:1.65">The secure survey takes approximately five minutes. Your response helps us strengthen project delivery, communication and service quality.</p></div>',
            'cta_label' => 'Complete the survey',
            'cta_url' => $link,
            'footer_note' => 'This secure survey link is intended for the recipient of this email. Please do not forward it unless requested.',
            'accent' => '#3478c5',
            'accent_deep' => '#173f66',
        ]);
    } elseif ($key === 'client_survey_internal') {
        $reference = dynabaseEmailValue($data, 'reference');
        $company = dynabaseEmailValue($data, 'company', 'Not recorded');
        $project = dynabaseEmailValue($data, 'project_title', 'Not recorded');
        $filledBy = dynabaseEmailValue($data, 'filled_by', 'Client respondent');
        $email = dynabaseEmailValue($data, 'email', 'Not recorded');
        $score = dynabaseEmailValue($data, 'overall_score', '0');
        $responseUrl = dynabaseEmailValue($data, 'response_url', rtrim(envString('FRONTEND_URL', 'http://localhost:5173'), '/') . '/client-surveys');
        $subject = 'New client survey response — ' . $project;
        $html = dynabaseEmailLayout([
            'eyebrow' => 'CLIENT EXPERIENCE ALERT',
            'title' => 'A new survey response is ready',
            'preheader' => 'A client survey response has been submitted to Dynabase.',
            'badge' => 'NEW RESPONSE',
            'intro_html' => '<p style="margin:0">A client has completed the Lambert Electromec experience survey. The response is now available for review in Dynabase.</p>',
            'body_html' => '<div style="margin:22px 0 0;padding:18px;border-radius:16px;background:#f4f9fc;border:1px solid #d9e8f1"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td><p style="margin:0;color:#6f8497;font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase">Overall score</p><p style="margin:7px 0 0;color:#173f66;font-size:30px;line-height:1;font-weight:850;letter-spacing:-.04em">' . dynabaseEmailEscape($score) . '%</p></td><td align="right" style="vertical-align:middle"><span style="display:inline-block;padding:8px 11px;border-radius:999px;background:#def4eb;color:#1d7d5d;font-size:11px;font-weight:800">Submitted</span></td></tr></table></div>'
                . dynabaseEmailInfoRows([
                    ['label' => 'Reference', 'value' => $reference],
                    ['label' => 'Company', 'value' => $company],
                    ['label' => 'Project', 'value' => $project],
                    ['label' => 'Respondent', 'value' => $filledBy],
                    ['label' => 'Email', 'value' => $email],
                ]),
            'cta_label' => 'Review full response',
            'cta_url' => $responseUrl,
            'footer_note' => 'This notification was sent to the configured Client Survey notification recipients.',
            'accent' => '#2f7fba',
            'accent_deep' => '#143b5f',
        ]);
    } elseif ($key === 'client_survey_confirmation') {
        $filledBy = dynabaseEmailValue($data, 'filled_by', 'Client');
        $project = dynabaseEmailValue($data, 'project_title', 'your project');
        $reference = dynabaseEmailValue($data, 'reference');
        $subject = 'Thank you for your feedback';
        $html = dynabaseEmailLayout([
            'brand' => 'Lambert Electromec',
            'eyebrow' => 'FEEDBACK RECEIVED',
            'title' => 'Thank you — your response is in',
            'preheader' => 'Lambert Electromec has received your client survey response.',
            'badge' => 'CONFIRMED',
            'intro_html' => '<p style="margin:0">Dear <strong style="color:#173047">' . dynabaseEmailEscape($filledBy) . '</strong>,</p><p style="margin:14px 0 0">Thank you for taking the time to share your experience with us. Your feedback for <strong style="color:#173047">' . dynabaseEmailEscape($project) . '</strong> has been received successfully.</p>',
            'body_html' => '<div style="margin:22px 0 0;padding:18px;border-radius:14px;background:#f2faf7;border:1px solid #d2ece3"><p style="margin:0;color:#2c765f;font-size:11px;font-weight:800;letter-spacing:.09em;text-transform:uppercase">Submission reference</p><p style="margin:8px 0 0;color:#184d3d;font-size:18px;font-weight:850;letter-spacing:.02em">' . dynabaseEmailEscape($reference) . '</p></div><p style="margin:18px 0 0">Your comments help us improve how we plan, communicate and deliver. We genuinely appreciate your partnership.</p>',
            'footer_note' => 'Please keep the submission reference above for your records.',
            'accent' => '#2e8b72',
            'accent_deep' => '#1b5f50',
        ]);
    }

    return [
        'key' => $key,
        'name' => $catalog[$key]['name'],
        'category' => $catalog[$key]['category'],
        'audience' => $catalog[$key]['audience'],
        'description' => $catalog[$key]['description'],
        'subject' => $subject,
        'html' => $html,
        'text' => dynabaseEmailTextFromHtml($html),
        'variables' => $catalog[$key]['variables'],
        'sample_data' => $data,
    ];
}
