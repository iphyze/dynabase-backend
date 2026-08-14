<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';

function dynabasePermissionCatalog(): array
{
    return [
        'Workspace' => [
            ['key' => 'dashboard.view', 'label' => 'View dashboard', 'description' => 'Access workspace dashboards and metrics.'],
            ['key' => 'notifications.view', 'label' => 'View notifications', 'description' => 'Open notification alerts and activity updates.'],
            ['key' => 'global_search.use', 'label' => 'Use global search', 'description' => 'Search records and workspace areas.'],
        ],
        'Clients' => [
            ['key' => 'clients.view', 'label' => 'View clients', 'description' => 'Open the client directory and profiles.'],
            ['key' => 'clients.create', 'label' => 'Create clients', 'description' => 'Add new client records.'],
            ['key' => 'clients.edit', 'label' => 'Edit clients', 'description' => 'Update client profiles and status.'],
            ['key' => 'clients.delete', 'label' => 'Delete clients', 'description' => 'Remove client records.'],
            ['key' => 'clients.export', 'label' => 'Export clients', 'description' => 'Download client directories.'],
        ],
        'Key Persons' => [
            ['key' => 'keypersons.view', 'label' => 'View key persons', 'description' => 'Open the key-person register and profiles.'],
            ['key' => 'keypersons.create', 'label' => 'Create key persons', 'description' => 'Add contacts and relationship records.'],
            ['key' => 'keypersons.edit', 'label' => 'Edit key persons', 'description' => 'Update contact and relationship details.'],
            ['key' => 'keypersons.delete', 'label' => 'Delete key persons', 'description' => 'Remove key-person records.'],
            ['key' => 'keypersons.export', 'label' => 'Export key persons', 'description' => 'Download key-person registers.'],
        ],
        'Gift Lists' => [
            ['key' => 'gift_lists.view', 'label' => 'View gift lists', 'description' => 'Open annual gift-list registers.'],
            ['key' => 'gift_lists.create', 'label' => 'Create gift lists', 'description' => 'Create annual gift registers and recipients.'],
            ['key' => 'gift_lists.edit', 'label' => 'Edit gift lists', 'description' => 'Update annual registers and recipient decisions.'],
            ['key' => 'gift_lists.delete', 'label' => 'Delete gift lists', 'description' => 'Remove annual registers.'],
            ['key' => 'gift_lists.export', 'label' => 'Export gift lists', 'description' => 'Download gift-list workbooks.'],
        ],
        'Tenders' => [
            ['key' => 'tenders.view', 'label' => 'View tenders', 'description' => 'Open tender registers and profiles.'],
            ['key' => 'tenders.create', 'label' => 'Create tenders', 'description' => 'Capture new tender opportunities.'],
            ['key' => 'tenders.edit', 'label' => 'Edit tenders', 'description' => 'Update tender movement and commercial data.'],
            ['key' => 'tenders.delete', 'label' => 'Delete tenders', 'description' => 'Remove tender records.'],
            ['key' => 'tenders.export', 'label' => 'Export tenders', 'description' => 'Download tender registers.'],
        ],
        'Documents' => [
            ['key' => 'documents.view', 'label' => 'View documents', 'description' => 'Open document registers and files.'],
            ['key' => 'documents.create', 'label' => 'Upload documents', 'description' => 'Add protected document records.'],
            ['key' => 'documents.edit', 'label' => 'Edit documents', 'description' => 'Update document metadata.'],
            ['key' => 'documents.revisions', 'label' => 'Manage document revisions', 'description' => 'Add, replace and select current document revisions.'],
            ['key' => 'documents.share', 'label' => 'Share documents', 'description' => 'Create, update and revoke secure document links.'],
            ['key' => 'documents.delete', 'label' => 'Delete documents', 'description' => 'Remove protected documents.'],
            ['key' => 'documents.export', 'label' => 'Export documents', 'description' => 'Download document registers.'],
        ],
        'Agreement Register' => [
            ['key' => 'agreement_register.view', 'label' => 'View Agreement Register', 'description' => 'Open NDA and MOU registers, profiles and insights.'],
            ['key' => 'agreement_register.create', 'label' => 'Create agreements', 'description' => 'Create NDA and MOU records.'],
            ['key' => 'agreement_register.edit', 'label' => 'Edit agreements', 'description' => 'Update agreement details and lifecycle status.'],
            ['key' => 'agreement_register.renew', 'label' => 'Renew agreements', 'description' => 'Create linked agreement renewals.'],
            ['key' => 'agreement_register.share', 'label' => 'Share agreements externally', 'description' => 'Create and manage client-facing agreement workspaces.'],
            ['key' => 'agreement_register.reminders', 'label' => 'Manage agreement reminders', 'description' => 'Schedule and send agreement reminders.'],
            ['key' => 'agreement_register.delete', 'label' => 'Delete agreements', 'description' => 'Remove agreement records from the active register.'],
            ['key' => 'agreement_register.export', 'label' => 'Export Agreement Register', 'description' => 'Download agreement reports and registers.'],
        ],
        'Prequalifications' => [
            ['key' => 'prequalifications.view', 'label' => 'View prequalifications', 'description' => 'Open readiness registers and profiles.'],
            ['key' => 'prequalifications.create', 'label' => 'Create prequalifications', 'description' => 'Capture readiness checklists.'],
            ['key' => 'prequalifications.edit', 'label' => 'Edit prequalifications', 'description' => 'Update readiness and opportunity data.'],
            ['key' => 'prequalifications.delete', 'label' => 'Delete prequalifications', 'description' => 'Remove readiness records.'],
            ['key' => 'prequalifications.export', 'label' => 'Export prequalifications', 'description' => 'Download readiness registers.'],
        ],
        'Submission Register' => [
            ['key' => 'submission_register.view', 'label' => 'View submission register', 'description' => 'Open submission records, profiles and dashboards.'],
            ['key' => 'submission_register.create', 'label' => 'Create submission records', 'description' => 'Capture prequalification, technical and registration submissions.'],
            ['key' => 'submission_register.edit', 'label' => 'Edit submission records', 'description' => 'Update submission metadata, dates, mode and status.'],
            ['key' => 'submission_register.delete', 'label' => 'Delete submission records', 'description' => 'Remove submission records from the active register.'],
            ['key' => 'submission_register.comment', 'label' => 'Add submission updates', 'description' => 'Add chat-style progress updates to submission records.'],
            ['key' => 'submission_register.export', 'label' => 'Export submission register', 'description' => 'Download submission register reports.'],
            ['key' => 'submission_register.pdf', 'label' => 'Print submission PDFs', 'description' => 'Generate PDF-ready submission profiles.'],
        ],
        'Client Surveys' => [
            ['key' => 'client_surveys.view', 'label' => 'View client surveys', 'description' => 'Review survey responses and invitations.'],
            ['key' => 'client_surveys.create', 'label' => 'Create survey invitations', 'description' => 'Create reusable or targeted survey links.'],
            ['key' => 'client_surveys.delete', 'label' => 'Delete survey records', 'description' => 'Remove survey responses or revoke invitations.'],
            ['key' => 'client_surveys.export', 'label' => 'Export survey results', 'description' => 'Download survey reports.'],
        ],
        'Influence Intelligence' => [
            ['key' => 'influence_logs.view', 'label' => 'View influence logs', 'description' => 'Open relationship notes and influence history.'],
            ['key' => 'influence_logs.create', 'label' => 'Create influence logs', 'description' => 'Add relationship intelligence notes.'],
            ['key' => 'influence_logs.edit', 'label' => 'Edit influence logs', 'description' => 'Update relationship notes.'],
            ['key' => 'influence_logs.delete', 'label' => 'Delete influence logs', 'description' => 'Remove relationship notes.'],
            ['key' => 'influence_logs.export', 'label' => 'Export influence logs', 'description' => 'Download influence logs.'],
            ['key' => 'web_of_influence.view', 'label' => 'View web of influence', 'description' => 'Open stakeholder influence maps.'],
            ['key' => 'web_of_influence.create', 'label' => 'Create influence maps', 'description' => 'Create stakeholder influence records.'],
            ['key' => 'web_of_influence.edit', 'label' => 'Edit influence maps', 'description' => 'Update stakeholder influence records.'],
            ['key' => 'web_of_influence.delete', 'label' => 'Delete influence maps', 'description' => 'Remove stakeholder influence records.'],
            ['key' => 'web_of_influence.export', 'label' => 'Export influence maps', 'description' => 'Download stakeholder intelligence.'],
        ],
        'Reports' => [
            ['key' => 'reports.view', 'label' => 'View reports', 'description' => 'Open the reporting workspace.'],
            ['key' => 'reports.export', 'label' => 'Export reports', 'description' => 'Generate Excel workbooks.'],
        ],
        'Administration' => [
            ['key' => 'users.view', 'label' => 'View users', 'description' => 'Open Users & Access.'],
            ['key' => 'users.invite', 'label' => 'Invite users', 'description' => 'Create role-aware invitations.'],
            ['key' => 'users.edit', 'label' => 'Edit users', 'description' => 'Update user role, scope and permissions.'],
            ['key' => 'users.status', 'label' => 'Activate/deactivate users', 'description' => 'Control account availability.'],
            ['key' => 'users.reset', 'label' => 'Reset user passwords', 'description' => 'Issue temporary passwords.'],
            ['key' => 'users.export', 'label' => 'Export users', 'description' => 'Download the user register.'],
            ['key' => 'email_templates.view', 'label' => 'View email templates', 'description' => 'Open transactional template previews.'],
            ['key' => 'email_templates.test', 'label' => 'Send test emails', 'description' => 'Send template test messages.'],
            ['key' => 'settings.view', 'label' => 'View settings', 'description' => 'Review workspace configuration.'],
            ['key' => 'settings.manage', 'label' => 'Manage settings', 'description' => 'Update workspace configuration.'],
            ['key' => 'audit.view', 'label' => 'View audit logs', 'description' => 'Review workspace audit history.'],
            ['key' => 'audit.export', 'label' => 'Export audit logs', 'description' => 'Download audit history.'],
        ],
    ];
}

function dynabasePermissionKeys(): array
{
    $keys = [];
    foreach (dynabasePermissionCatalog() as $items) {
        foreach ($items as $item) {
            $keys[] = $item['key'];
        }
    }

    return array_values(array_unique($keys));
}

function dynabasePermissionKeysForSections(array $sections): array
{
    $catalog = dynabasePermissionCatalog();
    $keys = [];

    foreach ($sections as $section) {
        foreach ($catalog[$section] ?? [] as $item) {
            $keys[] = (string) $item['key'];
        }
    }

    return array_values(array_unique($keys));
}

function dynabaseUserManagementPermissionKeys(): array
{
    return array_values(array_filter(
        dynabasePermissionKeysForSections(['Administration']),
        static fn (string $permission): bool => str_starts_with($permission, 'users.')
    ));
}

function dynabaseAdministrationPermissionKeys(): array
{
    return dynabasePermissionKeysForSections(['Administration']);
}

function dynabasePmsRelationshipPermissionKeys(): array
{
    return dynabasePermissionKeysForSections(['Clients', 'Key Persons', 'Gift Lists', 'Agreement Register']);
}

function dynabasePermissionDependencies(): array
{
    return [
        'clients.create' => ['clients.view'],
        'clients.edit' => ['clients.view'],
        'clients.delete' => ['clients.view'],
        'clients.export' => ['clients.view'],

        'keypersons.create' => ['keypersons.view'],
        'keypersons.edit' => ['keypersons.view'],
        'keypersons.delete' => ['keypersons.view'],
        'keypersons.export' => ['keypersons.view'],

        'gift_lists.create' => ['gift_lists.view'],
        'gift_lists.edit' => ['gift_lists.view'],
        'gift_lists.delete' => ['gift_lists.view'],
        'gift_lists.export' => ['gift_lists.view'],

        'tenders.create' => ['tenders.view'],
        'tenders.edit' => ['tenders.view'],
        'tenders.delete' => ['tenders.view'],
        'tenders.export' => ['tenders.view'],

        'documents.create' => ['documents.view'],
        'documents.edit' => ['documents.view'],
        'documents.revisions' => ['documents.view'],
        'documents.share' => ['documents.view'],
        'documents.delete' => ['documents.view'],
        'documents.export' => ['documents.view'],

        'agreement_register.create' => ['agreement_register.view'],
        'agreement_register.edit' => ['agreement_register.view'],
        'agreement_register.renew' => ['agreement_register.view'],
        'agreement_register.share' => ['agreement_register.view'],
        'agreement_register.reminders' => ['agreement_register.view'],
        'agreement_register.delete' => ['agreement_register.view'],
        'agreement_register.export' => ['agreement_register.view'],

        'prequalifications.create' => ['prequalifications.view'],
        'prequalifications.edit' => ['prequalifications.view'],
        'prequalifications.delete' => ['prequalifications.view'],
        'prequalifications.export' => ['prequalifications.view'],

        'submission_register.create' => ['submission_register.view'],
        'submission_register.edit' => ['submission_register.view'],
        'submission_register.delete' => ['submission_register.view'],
        'submission_register.comment' => ['submission_register.view'],
        'submission_register.export' => ['submission_register.view'],
        'submission_register.pdf' => ['submission_register.view'],

        'client_surveys.create' => ['client_surveys.view'],
        'client_surveys.delete' => ['client_surveys.view'],
        'client_surveys.export' => ['client_surveys.view'],

        'influence_logs.create' => ['influence_logs.view'],
        'influence_logs.edit' => ['influence_logs.view'],
        'influence_logs.delete' => ['influence_logs.view'],
        'influence_logs.export' => ['influence_logs.view'],

        'web_of_influence.create' => ['web_of_influence.view'],
        'web_of_influence.edit' => ['web_of_influence.view'],
        'web_of_influence.delete' => ['web_of_influence.view'],
        'web_of_influence.export' => ['web_of_influence.view'],

        'reports.export' => ['reports.view'],

        'users.invite' => ['users.view'],
        'users.edit' => ['users.view'],
        'users.status' => ['users.view'],
        'users.reset' => ['users.view'],
        'users.export' => ['users.view'],
        'email_templates.test' => ['email_templates.view'],
        'settings.manage' => ['settings.view'],
        'audit.export' => ['audit.view'],
    ];
}

function normalisePermissionKeys(array $keys): array
{
    $valid = array_flip(dynabasePermissionKeys());
    $clean = [];

    foreach ($keys as $key) {
        $key = trim((string) $key);
        if ($key !== '' && isset($valid[$key])) {
            $clean[$key] = true;
        }
    }

    return array_keys($clean);
}

function expandPermissionDependencies(array $keys): array
{
    $selected = array_fill_keys(normalisePermissionKeys($keys), true);
    $dependencies = dynabasePermissionDependencies();
    $changed = true;

    while ($changed) {
        $changed = false;
        foreach (array_keys($selected) as $permission) {
            foreach ($dependencies[$permission] ?? [] as $requiredPermission) {
                if (!isset($selected[$requiredPermission])) {
                    $selected[$requiredPermission] = true;
                    $changed = true;
                }
            }
        }
    }

    return array_values(array_intersect(dynabasePermissionKeys(), array_keys($selected)));
}

function permissionCeilingForRole(string $role): array
{
    $all = dynabasePermissionKeys();
    $workspace = dynabasePermissionKeysForSections(['Workspace']);
    $relationship = dynabasePmsRelationshipPermissionKeys();
    $pmsUserAdministration = ['users.view', 'users.invite', 'users.edit', 'users.status', 'users.reset'];

    return match ($role) {
        DYNABASE_ROLE_SUPER_ADMIN => $all,
        DYNABASE_ROLE_ADMIN => array_values(array_diff($all, dynabaseUserManagementPermissionKeys())),
        DYNABASE_ROLE_PMS_ADMIN => array_values(array_unique(array_merge($workspace, $relationship, $pmsUserAdministration))),
        DYNABASE_ROLE_PMS_USER => array_values(array_unique(array_merge($workspace, $relationship))),
        DYNABASE_ROLE_USER => array_values(array_diff($all, dynabaseAdministrationPermissionKeys())),
        default => [],
    };
}

function normalisePermissionsForRole(string $role, array $keys): array
{
    $ceiling = permissionCeilingForRole($role);
    if ($ceiling === []) {
        return [];
    }

    $selected = array_values(array_intersect(normalisePermissionKeys($keys), $ceiling));
    $selected = expandPermissionDependencies($selected);

    return array_values(array_intersect($selected, $ceiling));
}

function defaultPermissionsForRole(string $role): array
{
    $workspace = dynabasePermissionKeysForSections(['Workspace']);
    $relationshipDefaults = [
        'clients.view', 'clients.create', 'clients.edit', 'clients.export',
        'keypersons.view', 'keypersons.create', 'keypersons.edit', 'keypersons.export',
        'gift_lists.view', 'gift_lists.create', 'gift_lists.edit', 'gift_lists.export',
        'agreement_register.view', 'agreement_register.create', 'agreement_register.edit', 'agreement_register.export',
    ];
    $pmsUserAdministration = ['users.view', 'users.invite', 'users.edit', 'users.status', 'users.reset'];

    $defaults = match ($role) {
        DYNABASE_ROLE_SUPER_ADMIN => dynabasePermissionKeys(),
        DYNABASE_ROLE_ADMIN => permissionCeilingForRole(DYNABASE_ROLE_ADMIN),
        DYNABASE_ROLE_PMS_ADMIN => array_values(array_unique(array_merge($workspace, $relationshipDefaults, $pmsUserAdministration))),
        DYNABASE_ROLE_PMS_USER => array_values(array_unique(array_merge($workspace, $relationshipDefaults))),
        DYNABASE_ROLE_USER => $workspace,
        default => [],
    };

    return normalisePermissionsForRole($role, $defaults);
}

function permissionTableExists(mysqli $conn): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "user_permissions"'
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $exists = (int) ($row['total'] ?? 0) > 0;
    return $exists;
}

function userPermissionRows(mysqli $conn, int $userId): ?array
{
    if (!permissionTableExists($conn)) {
        return null;
    }

    $stmt = $conn->prepare('SELECT permission_key, granted FROM user_permissions WHERE user_id = ? ORDER BY permission_key ASC');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $keys = [];
    $hasCustomRows = false;

    while ($row = $result->fetch_assoc()) {
        $hasCustomRows = true;
        if ((int) ($row['granted'] ?? 0) === 1) {
            $keys[] = (string) $row['permission_key'];
        }
    }

    $stmt->close();
    return $hasCustomRows ? $keys : null;
}

function userEffectivePermissions(mysqli $conn, array $user): array
{
    $role = userRole($user);
    if ($role === DYNABASE_ROLE_SUPER_ADMIN) {
        return dynabasePermissionKeys();
    }

    $userId = (int) ($user['id'] ?? 0);
    $rows = $userId > 0 ? userPermissionRows($conn, $userId) : null;
    $source = $rows ?? defaultPermissionsForRole($role);

    return normalisePermissionsForRole($role, $source);
}

function userHasPermission(mysqli $conn, array $user, string $permission): bool
{
    $role = userRole($user);
    if ($role === DYNABASE_ROLE_SUPER_ADMIN) {
        return true;
    }

    if (!in_array($permission, permissionCeilingForRole($role), true)) {
        return false;
    }

    return in_array($permission, userEffectivePermissions($conn, $user), true);
}

function requirePermission(mysqli $conn, array $user, string $permission, string $message = 'You are not authorised to perform this action.'): void
{
    if (!userHasPermission($conn, $user, $permission)) {
        throw new RuntimeException($message, 403);
    }
}

function permissionsAssignableToRole(string $role): array
{
    return permissionCeilingForRole($role);
}

function permissionsActorMayGrant(mysqli $conn, array $actor, string $targetRole): array
{
    $targetRolePermissions = permissionsAssignableToRole($targetRole);

    if (userRole($actor) === DYNABASE_ROLE_SUPER_ADMIN) {
        return $targetRolePermissions;
    }

    if (userRole($actor) === DYNABASE_ROLE_PMS_ADMIN && $targetRole !== DYNABASE_ROLE_PMS_USER) {
        return [];
    }

    return array_values(array_intersect(userEffectivePermissions($conn, $actor), $targetRolePermissions));
}

function resolveSubmittedPermissions(mysqli $conn, array $actor, string $targetRole, mixed $submittedPermissions): array
{
    $grantable = permissionsActorMayGrant($conn, $actor, $targetRole);
    $submitted = is_array($submittedPermissions)
        ? $submittedPermissions
        : defaultPermissionsForRole($targetRole);

    $selected = array_values(array_intersect(normalisePermissionKeys($submitted), $grantable));
    $selected = expandPermissionDependencies($selected);

    return array_values(array_intersect($selected, $grantable));
}

function replaceUserPermissions(
    mysqli $conn,
    int $userId,
    array $permissions,
    ?int $updatedBy = null,
    ?string $targetRole = null
): void {
    if (!permissionTableExists($conn)) {
        return;
    }

    if ($targetRole === null || !in_array($targetRole, DYNABASE_ROLES, true)) {
        $roleStmt = $conn->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        $roleStmt->bind_param('i', $userId);
        $roleStmt->execute();
        $roleRow = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();
        $targetRole = (string) ($roleRow['role'] ?? '');
    }

    $permissions = normalisePermissionsForRole($targetRole, $permissions);

    $deleteStmt = $conn->prepare('DELETE FROM user_permissions WHERE user_id = ?');
    $deleteStmt->bind_param('i', $userId);
    $deleteStmt->execute();
    $deleteStmt->close();

    $selected = array_flip($permissions);
    $stmt = $conn->prepare('INSERT INTO user_permissions (user_id, permission_key, granted, updated_by) VALUES (?, ?, ?, ?)');

    foreach (dynabasePermissionKeys() as $permission) {
        $granted = isset($selected[$permission]) ? 1 : 0;
        $updatedByParam = $updatedBy;
        $stmt->bind_param('isii', $userId, $permission, $granted, $updatedByParam);
        $stmt->execute();
    }

    $stmt->close();
}

function permissionPayload(mysqli $conn, array $user): array
{
    $roleDefaults = [];
    $roleCeilings = [];

    foreach (DYNABASE_ROLES as $role) {
        $roleDefaults[$role] = defaultPermissionsForRole($role);
        $roleCeilings[$role] = permissionCeilingForRole($role);
    }

    $grantableByRole = [];
    foreach (DYNABASE_ROLES as $role) {
        if (canInviteRole($user, $role) || in_array($role, allowedManagedRoles($user), true)) {
            $grantableByRole[$role] = permissionsActorMayGrant($conn, $user, $role);
        }
    }

    return [
        'catalog' => dynabasePermissionCatalog(),
        'keys' => dynabasePermissionKeys(),
        'dependencies' => dynabasePermissionDependencies(),
        'user_permissions' => userEffectivePermissions($conn, $user),
        'role_defaults' => $roleDefaults,
        'role_ceilings' => $roleCeilings,
        'grantable_by_role' => $grantableByRole,
    ];
}
