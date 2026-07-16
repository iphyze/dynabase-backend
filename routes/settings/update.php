<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/authorization.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../includes/audit.php';

requireMethod('POST');

$actor = authenticateUser();
$data = readJsonBody();
$section = cleanString($data['section'] ?? '');
$payload = is_array($data['values'] ?? null) ? $data['values'] : [];

$validated = [];

if ($section === 'workspace') {
    $workspaceName = cleanString($payload['workspace_name'] ?? '');
    $organisationName = cleanString($payload['organisation_name'] ?? '');
    $supportEmail = cleanEmail($payload['support_email'] ?? '');
    $supportPhone = cleanString($payload['support_phone'] ?? '');
    $websiteUrl = cleanString($payload['website_url'] ?? '');
    $officeAddress = cleanString($payload['office_address'] ?? '');

    if (mb_strlen($workspaceName) < 2 || mb_strlen($workspaceName) > 80) {
        throw new RuntimeException('Workspace name must be between 2 and 80 characters.', 422);
    }
    if (mb_strlen($organisationName) < 2 || mb_strlen($organisationName) > 150) {
        throw new RuntimeException('Organisation name must be between 2 and 150 characters.', 422);
    }
    if ($supportEmail !== '' && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Please enter a valid support email address.', 422);
    }
    if ($websiteUrl !== '' && !filter_var($websiteUrl, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Please enter a valid website URL including http:// or https://.', 422);
    }
    if (mb_strlen($supportPhone) > 50) {
        throw new RuntimeException('Support telephone must not exceed 50 characters.', 422);
    }
    if (mb_strlen($officeAddress) > 500) {
        throw new RuntimeException('Office address must not exceed 500 characters.', 422);
    }

    $validated = [
        'workspace_name' => $workspaceName,
        'organisation_name' => $organisationName,
        'support_email' => $supportEmail,
        'support_phone' => $supportPhone,
        'website_url' => $websiteUrl,
        'office_address' => $officeAddress,
    ];
} elseif ($section === 'regional') {
    $timezone = cleanString($payload['timezone'] ?? 'Africa/Lagos');
    $dateFormat = cleanString($payload['date_format'] ?? 'd M Y');
    $weekStartsOn = cleanString($payload['week_starts_on'] ?? 'monday');
    $defaultReportingPeriod = cleanString($payload['default_reporting_period'] ?? 'all');
    $defaultPageSize = (int) ($payload['default_page_size'] ?? 10);

    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        throw new RuntimeException('Please choose a valid timezone.', 422);
    }
    if (!in_array($dateFormat, ['d M Y', 'd/m/Y', 'm/d/Y', 'Y-m-d'], true)) {
        throw new RuntimeException('Please choose a supported date format.', 422);
    }
    if (!in_array($weekStartsOn, ['monday', 'sunday'], true)) {
        throw new RuntimeException('Please choose a valid first day of the week.', 422);
    }
    if (!in_array($defaultReportingPeriod, ['all', 'current_year'], true)) {
        throw new RuntimeException('Please choose a valid reporting-period default.', 422);
    }
    if (!in_array($defaultPageSize, [10, 20, 25, 50], true)) {
        throw new RuntimeException('Please choose a supported default page size.', 422);
    }

    $validated = [
        'timezone' => $timezone,
        'date_format' => $dateFormat,
        'week_starts_on' => $weekStartsOn,
        'default_reporting_period' => $defaultReportingPeriod,
        'default_page_size' => $defaultPageSize,
    ];
} elseif ($section === 'notifications') {
    $keys = [
        'notifications_enabled',
        'notification_relationship_enabled',
        'notification_gift_list_enabled',
        'notification_opportunity_enabled',
        'notification_document_enabled',
        'notification_survey_enabled',
        'notification_access_enabled',
    ];

    foreach ($keys as $key) {
        $value = $payload[$key] ?? false;
        $validated[$key] = $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
} else {
    throw new RuntimeException('Please choose a valid settings section.', 422);
}

$actorId = (int) $actor['id'];
$conn->begin_transaction();
try {
    saveAppSettings($conn, $validated, $actorId);
    writeAuditLog($conn, $actor, 'settings.updated', 'workspace_settings', $section, [
        'section' => $section,
        'keys' => array_keys($validated),
    ]);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

jsonResponse([
    'status' => 'Success',
    'message' => ucfirst($section) . ' settings updated successfully.',
    'data' => [
        'section' => $section,
        'settings' => loadAppSettings($conn),
    ],
]);
