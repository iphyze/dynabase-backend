<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/agreementExternal.php';

requireMethod('GET');
$authUser = authenticateUser();
assertAgreementExternalSchema($conn);
cleanupAgreementExternalSecurityArtifacts($conn, true);

$agreementId = (int) ($_GET['agreement_id'] ?? $_GET['id'] ?? 0);
if ($agreementId <= 0) {
    throw new RuntimeException('Agreement record ID is required.', 422);
}
assertAgreementAccessible($conn, $authUser, $agreementId);

$rows = dbFetchAll(
    $conn,
    "SELECT s.*,
            NULLIF(TRIM(CONCAT(COALESCE(creator.first_name,''),' ',COALESCE(creator.last_name,''))), '') AS creator_name,
            creator.email AS creator_email,
            NULLIF(TRIM(CONCAT(COALESCE(revoker.first_name,''),' ',COALESCE(revoker.last_name,''))), '') AS revoker_name
     FROM agreement_external_links s
     LEFT JOIN users creator ON creator.id = s.created_by_id
     LEFT JOIN users revoker ON revoker.id = s.revoked_by_id
     WHERE s.agreement_id = ?
     ORDER BY s.created_at DESC, s.id DESC
     LIMIT 100",
    'i',
    [$agreementId]
);
$items = array_map(static fn (array $row): array => agreementExternalAdminPayload($row), $rows);

jsonResponse([
    'status' => 'Success',
    'message' => 'Agreement workspace links retrieved successfully.',
    'data' => [
        'items' => $items,
        'field_options' => agreementExternalEditableFieldOptions(),
        'recent_submissions' => agreementExternalRecentSubmissions($conn, $agreementId),
        'summary' => [
            'total_links' => count($items),
            'active_links' => count(array_filter($items, static fn (array $item): bool => $item['status'] === 'active')),
            'total_submissions' => array_sum(array_column($items, 'submission_count')),
            'total_uploads' => array_sum(array_column($items, 'upload_count')),
        ],
    ],
]);
