<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/giftListEmails.php';

requireMethod('POST');
$actor = authenticateUser();
giftEmailRequireSender($conn, $actor);
validateCsrfToken();
$data = readJsonBody();
$year = validateGiftYear($data['gift_year'] ?? date('Y'));
$kind = trim((string) ($data['type'] ?? ''));
if (!in_array($kind, ['starter', 'reminder', 'custom'], true)) {
    throw new RuntimeException('Choose a valid email type.', 422);
}
$subject = trim((string) ($data['subject'] ?? ''));
$message = trim((string) ($data['message'] ?? ''));
if ($kind === 'custom' && ($subject === '' || $message === '' || strlen($subject) > 160 || strlen($message) > 10000)) {
    throw new RuntimeException('Provide a subject (up to 160 characters) and a message (up to 10,000 characters).', 422);
}
$id = $data['recipient_user_id'] ?? null;
if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id <= 0) {
    throw new RuntimeException('Select a recipient to personalize the preview.', 422);
}

$users = giftEmailUserRows($conn, $year, [(int) $id]);
if (count($users) !== 1 || !dynabaseIsDeliverableEmail((string) $users[0]['email'])) {
    throw new RuntimeException('The selected recipient is no longer available.', 422);
}
$user = $users[0];
if ($kind === 'reminder' && ((int) ($user['pms_owner_id'] ?? 0) === 0 || (int) ($user['selected_recipients'] ?? 0) > 0)) {
    throw new RuntimeException('This recipient is no longer eligible for a reminder. Refresh your selection.', 422);
}

$preview = giftEmailContent($kind, $user, $year, $subject, $message);
header('Cache-Control: no-store, private');
jsonResponse([
    'status' => 'Success',
    'data' => [
        'subject' => $preview['subject'],
        'html' => $preview['html'],
        'recipient' => [
            'id' => (int) $user['id'],
            'name' => trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
            'email' => (string) $user['email'],
        ],
    ],
]);
