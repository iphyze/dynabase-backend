<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/giftLists.php';
require_once __DIR__ . '/mailer.php';

/** Email campaigns can only originate from the main Dynabase application. */
function giftEmailRequireSender(mysqli $conn, array $actor): void
{
    if (!in_array(userRole($actor), ['super_admin', 'admin', 'user'], true)
        || (int) ($actor['pms_portal_only'] ?? 0) === 1) {
        throw new RuntimeException('Your account cannot send Gift List emails.', 403);
    }
    requirePermission($conn, $actor, 'gift_list_emails.send');
}

function giftEmailGroups(): array
{
    return [
        'pms_admins' => "(u.role = 'pms_admin' OR (u.role = 'admin' AND u.is_pms_admin = 1)) AND u.pms_portal_only = 0",
        'pms_users' => "u.role = 'pms_user' AND u.pms_portal_only = 0",
        'admins' => "u.role = 'admin'",
        'super_admins' => "u.role = 'super_admin'",
        'users' => "u.role = 'user'",
        'external' => 'u.pms_portal_only = 1',
        'all' => '1=1',
    ];
}

function giftEmailOwnerSql(): string
{
    return "(CASE
       WHEN u.role = 'pms_user' THEN u.parent_pms_admin_id
       WHEN u.role = 'pms_admin' OR u.is_pms_admin = 1 THEN u.id
       ELSE NULL END)";
}

/** No user-controlled SQL fragments. Search/filters are bound parameters. */
function giftEmailRecipientsFilter(array $options): array
{
    $group = (string) ($options['group'] ?? 'all');
    $groups = giftEmailGroups();
    if (!isset($groups[$group])) {
        throw new RuntimeException('Invalid recipient group.', 422);
    }
    $where = "u.status = 'active' AND u.email IS NOT NULL AND u.email <> '' AND " . $groups[$group];
    $types = '';
    $params = [];
    $query = trim((string) ($options['search'] ?? ''));
    if (strlen($query) > 100) {
        throw new RuntimeException('Search is too long.', 422);
    }
    if ($query !== '') {
        $where .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE ?)";
        $like = '%' . $query . '%';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    if (!empty($options['only_unstarted'])) {
        // Include blank saved lists, but exclude teams with selected recipients.
        $where .= ' AND ' . giftEmailOwnerSql() . ' IS NOT NULL'
            . " AND (gl.id IS NULL OR NOT EXISTS (SELECT 1 FROM gift_list_items gi"
            . " WHERE gi.gift_list_id = gl.id AND gi.gift_decision = 'selected'))";
    }
    return [$where, $types, $params];
}

function giftEmailRecipientsSql(int $year, array $options): array
{
    [$where, $types, $params] = giftEmailRecipientsFilter($options);
    $owner = giftEmailOwnerSql();
    $from = "FROM users u
        LEFT JOIN users parent ON parent.id = u.parent_pms_admin_id
        LEFT JOIN gift_lists gl ON gl.owner_pms_admin_id = {$owner} AND gl.gift_year = ?
        WHERE {$where}";
    return [$from, 'i' . $types, array_merge([$year], $params)];
}

function giftEmailUserRows(mysqli $conn, int $year, array $ids): array
{
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $owner = giftEmailOwnerSql();
    $sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.status, u.pms_portal_only,
            u.parent_pms_admin_id, u.is_pms_admin, {$owner} AS pms_owner_id,
            CONCAT_WS(' ', parent.first_name, parent.last_name) AS parent_name,
            gl.id AS gift_list_id,
            (SELECT COUNT(*) FROM gift_list_items gi
             WHERE gi.gift_list_id = gl.id AND gi.gift_decision = 'selected') AS selected_recipients
        FROM users u
        LEFT JOIN users parent ON parent.id = u.parent_pms_admin_id
        LEFT JOIN gift_lists gl ON gl.owner_pms_admin_id = {$owner} AND gl.gift_year = ?
        WHERE u.id IN ({$placeholders}) AND u.status = 'active'";
    return dbFetchAll($conn, $sql, 'i' . str_repeat('i', count($ids)), array_merge([$year], $ids));
}

function giftEmailRecipientPayload(array $row): array
{
    $owner = (int) ($row['pms_owner_id'] ?? 0);
    $count = (int) ($row['selected_recipients'] ?? 0);
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    return [
        'id' => (int) $row['id'],
        'name' => $name !== '' ? $name : (string) $row['email'],
        'email' => (string) $row['email'],
        'role' => (string) $row['role'],
        'portal_only' => (bool) ($row['pms_portal_only'] ?? false),
        'pms_owner_id' => $owner ?: null,
        'pms_owner_name' => (string) ($row['parent_name'] ?: ($owner === (int) $row['id'] ? $name : '')),
        'gift_list_id' => !empty($row['gift_list_id']) ? (int) $row['gift_list_id'] : null,
        'selected_recipients' => $count,
        'gift_list_status' => !$owner ? 'not_applicable' : ($count > 0 ? 'started' : 'not_started'),
        'reminder_eligible' => $owner > 0 && $count === 0,
    ];
}

function giftEmailSubject(string $kind, int $year, string $custom = ''): string
{
    return match ($kind) {
        'starter' => "Your {$year} Gift List — Getting Started",
        'reminder' => "Reminder: Complete Your {$year} Gift List",
        'custom' => $custom,
        default => throw new RuntimeException('Unsupported email type.', 422),
    };
}

/** Builds one individually addressed branded message. */
function giftEmailContent(string $kind, array $recipient, int $year, string $customSubject = '', string $customMessage = ''): array
{
    $name = dynabaseEmailEscape((string) ($recipient['first_name'] ?? ''));
    $name = $name ?: 'Colleague';
    $yearText = (string) $year;
    $url = rtrim(envString('FRONTEND_URL', 'http://localhost:5173'), '/') . '/login';
    $subject = giftEmailSubject($kind, $year, $customSubject);
    $salutation = '<p style="margin:0 0 17px">Dear <strong style="color:#12354a">' . $name . '</strong>,</p>';
    $showInitialPassword = $kind === 'starter' && (int) ($recipient['pms_portal_only'] ?? 0) !== 1;
    $signInStep = $showInitialPassword
        ? 'Choose Email verification (recommended) or Password if you are signing in with your starting credentials.'
        : 'Choose Email verification to receive a one-time code.';
    $guide = '<div style="padding:19px 20px;background:#f1f9f5;border:1px solid #d4e8df;border-radius:15px;color:#1b5447">'
        . '<strong style="letter-spacing:.05em;font-size:12px">HOW TO COMPLETE YOUR GIFT LIST</strong>'
        . '<ol style="margin:12px 0 0;padding-left:20px;color:#254d48;line-height:1.9">'
        . '<li>Open Dynabase using the button below.</li>'
        . '<li>' . $signInStep . '</li>'
        . '<li>Review last year’s suggested recipients, add or remove people, and update their gift rates.</li>'
        . '<li>Save your annual list. You can return later to make changes.</li>'
        . '</ol></div>';
    $initialPasswordNote = $showInitialPassword
        ? '<div style="margin-top:18px;padding:17px 20px;border:1px solid #cfe1ef;border-radius:14px;background:#f5faff;color:#254663">'
            . '<strong style="font-size:12px;letter-spacing:.05em">PASSWORD SIGN-IN FOR NEW OR RESET ACCOUNTS</strong>'
            . '<p style="margin:10px 0 5px">If your account was set up or reset with the shared starting password, sign in with your registered email address and:</p>'
            . '<p style="margin:0;color:#12354a;font-size:19px;font-weight:800;letter-spacing:.03em">Lambert@2026</p>'
            . '<p style="margin:9px 0 0;font-size:13px">Dynabase will prompt you to create a private password if your account is marked for a required password change. If you have already changed your password, use your updated password or choose Email verification instead. Do not share login credentials.</p>'
            . '</div>'
        : '';

    if ($kind === 'starter') {
        $intro = $salutation . '<p>Your <strong>' . $yearText . ' annual gift-list planning</strong> is ready. '
            . 'You can review your PMS recipients and complete your list in Dynabase.</p>';
        $body = $guide . $initialPasswordNote
            . '<p style="margin-top:19px">Sign in with your registered email address. '
            . 'If you choose email verification, Dynabase will send a one-time code. Never share a verification code.</p>';
        $title = 'Your ' . $yearText . ' Gift List Is Ready';
        $eyebrow = 'GIFT LIST STARTER';
    } elseif ($kind === 'reminder') {
        $intro = $salutation . '<p>Our records indicate that your PMS team has not yet saved a gift list '
            . 'with selected recipients for <strong>' . $yearText . '</strong>. Please complete it when convenient.</p>';
        $body = $guide . '<p style="margin-top:19px">If you have already updated the list, please save your changes.</p>';
        $title = 'A Friendly Gift List Reminder';
        $eyebrow = 'ACTION REMINDER';
    } else {
        $intro = $salutation;
        $body = '<div style="white-space:pre-line;line-height:1.85">'
            . nl2br(dynabaseEmailEscape($customMessage), false) . '</div>';
        $title = $subject;
        $eyebrow = 'TEAM COMMUNICATION';
    }

    $html = dynabaseEmailLayout([
        'brand' => 'Lambert Electromec',
        'title' => $title,
        'preheader' => $subject,
        'eyebrow' => $eyebrow,
        'badge' => 'DYNABASE',
        'accent' => '#1d6857',
        'accent_deep' => '#1c5b4e',
        'intro_html' => $intro,
        'body_html' => $body,
        'cta_label' => $kind === 'custom' ? '' : 'Open Dynabase',
        'cta_url' => $kind === 'custom' ? '' : $url,
        'footer_note' => 'Sent by an authorized Dynabase team member. Please do not share verification codes.',
    ]);
    return ['subject' => $subject, 'html' => $html, 'text' => dynabaseEmailTextFromHtml($html)];
}
