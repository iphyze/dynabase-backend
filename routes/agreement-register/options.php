<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/agreements.php';

requireMethod('GET');
authenticateUser();

jsonResponse([
    'status' => 'Success',
    'message' => 'Agreement Register options retrieved successfully.',
    'data' => [
        'document_ref_types' => DYNABASE_AGREEMENT_TYPES,
        'issued_by' => DYNABASE_AGREEMENT_ISSUED_BY,
        'renewal_options' => DYNABASE_AGREEMENT_RENEWAL_OPTIONS,
        'statuses' => DYNABASE_AGREEMENT_STATUSES,
        'expiring_soon_days' => agreementExpiringSoonDays($conn),
        'status_groups' => [
            'preparation_execution' => ['Draft', 'Under Review', 'Sent', 'Awaiting Client Signature', 'Awaiting Lambert Signature', 'Fully Executed'],
            'live' => ['Active', 'Expiring Soon'],
            'closed' => ['Renewed', 'Expired', 'Terminated', 'Archived'],
        ],
    ],
]);
