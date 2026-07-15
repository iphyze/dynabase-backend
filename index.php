<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/authorization.php';
require_once __DIR__ . '/includes/permissions.php';

$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$relativePath = '/' . trim(substr($requestPath, strlen($basePath)), '/');
$relativePath = $relativePath === '/' ? '/' : $relativePath;

$routes = [
    '/' => static function (): void {
        require __DIR__ . '/routes/welcome.php';
    },

    '/auth/csrf' => 'routes/auth/csrf.php',
    '/auth/login' => 'routes/auth/login.php',
    '/auth/logout' => 'routes/auth/logout.php',
    '/auth/me' => 'routes/auth/me.php',
    '/auth/change-password' => 'routes/auth/changePassword.php',
    '/auth/invite-user' => 'routes/auth/inviteUser.php',
    '/auth/accept-invitation' => 'routes/auth/acceptInvitation.php',

    '/users/list' => 'routes/users/listUsers.php',
    '/users/pms-admins' => 'routes/users/listPmsAdmins.php',
    '/users/deactivate' => 'routes/users/deactivateUser.php',
    '/users/activate' => 'routes/users/activateUser.php',
    '/users/update' => 'routes/users/updateUser.php',
    '/users/bulk-update' => 'routes/users/bulkUpdateUsers.php',
    '/users/reset-password' => 'routes/users/resetPassword.php',
    '/users/export' => 'routes/users/exportUsers.php',
    '/users/permissions' => 'routes/users/permissions.php',

    '/clients/list' => 'routes/clients/list.php',
    '/clients/show' => 'routes/clients/show.php',
    '/clients/create' => 'routes/clients/create.php',
    '/clients/update' => 'routes/clients/update.php',
    '/clients/delete' => 'routes/clients/delete.php',
    '/clients/activate' => 'routes/clients/activate.php',
    '/clients/bulk-update' => 'routes/clients/bulkUpdate.php',
    '/clients/export' => 'routes/clients/exportClients.php',

    '/keypersons/list' => 'routes/keypersons/list.php',
    '/keypersons/show' => 'routes/keypersons/show.php',
    '/keypersons/create' => 'routes/keypersons/create.php',
    '/keypersons/update' => 'routes/keypersons/update.php',
    '/keypersons/delete' => 'routes/keypersons/delete.php',
    '/keypersons/activate' => 'routes/keypersons/activate.php',
    '/keypersons/bulk-update' => 'routes/keypersons/bulkUpdate.php',
    '/keypersons/export' => 'routes/keypersons/exportKeypersons.php',

    '/gift-lists/list' => 'routes/gift-lists/list.php',
    '/gift-lists/show' => 'routes/gift-lists/show.php',
    '/gift-lists/create' => 'routes/gift-lists/create.php',
    '/gift-lists/update' => 'routes/gift-lists/update.php',
    '/gift-lists/delete' => 'routes/gift-lists/delete.php',
    '/gift-lists/bulk-delete' => 'routes/gift-lists/bulkDelete.php',
    '/gift-lists/add-keypersons' => 'routes/gift-lists/addKeypersons.php',
    '/gift-lists/export' => 'routes/gift-lists/export.php',

    '/documents/list' => 'routes/documents/list.php',
    '/documents/show' => 'routes/documents/show.php',
    '/documents/options' => 'routes/documents/options.php',
    '/documents/create' => 'routes/documents/create.php',
    '/documents/update' => 'routes/documents/update.php',
    '/documents/delete' => 'routes/documents/delete.php',
    '/documents/bulk-delete' => 'routes/documents/bulkDelete.php',
    '/documents/file' => 'routes/documents/file.php',
    '/documents/revisions/list' => 'routes/documents/revisionsList.php',
    '/documents/revisions/create' => 'routes/documents/revisionsCreate.php',
    '/documents/revisions/replace' => 'routes/documents/revisionsReplace.php',
    '/documents/revisions/set-current' => 'routes/documents/revisionsSetCurrent.php',
    '/documents/shares/list' => 'routes/documents/shares/list.php',
    '/documents/shares/create' => 'routes/documents/shares/create.php',
    '/documents/shares/update' => 'routes/documents/shares/update.php',
    '/documents/shares/revoke' => 'routes/documents/shares/revoke.php',
    '/documents/public/share' => 'routes/documents/public/share.php',
    '/documents/public/unlock' => 'routes/documents/public/unlock.php',
    '/documents/public/file' => 'routes/documents/public/file.php',

    '/dashboard/overview' => 'routes/dashboard/overview.php',
    '/dashboard/pms' => 'routes/dashboard/pms.php',

    '/search/global' => 'routes/search/global.php',

    '/notifications/list' => 'routes/notifications/list.php',
    '/notifications/mark-read' => 'routes/notifications/markRead.php',
    '/notifications/mark-all-read' => 'routes/notifications/markAllRead.php',
    '/notifications/delete' => 'routes/notifications/delete.php',
    '/notifications/clear-read' => 'routes/notifications/clearRead.php',

    '/settings/overview' => 'routes/settings/overview.php',
    '/settings/update' => 'routes/settings/update.php',

    '/reports/overview' => 'routes/reports/overview.php',
    '/reports/export' => 'routes/reports/export.php',

    '/logs/list' => 'routes/logs/list.php',
    '/logs/create' => 'routes/logs/create.php',
    '/logs/update' => 'routes/logs/update.php',
    '/logs/delete' => 'routes/logs/delete.php',
    '/logs/bulk-delete' => 'routes/logs/bulkDelete.php',
    '/logs/export' => 'routes/logs/exportLogs.php',

    '/web-of-influence/list' => 'routes/web-of-influence/list.php',
    '/web-of-influence/show' => 'routes/web-of-influence/show.php',
    '/web-of-influence/options' => 'routes/web-of-influence/options.php',
    '/web-of-influence/create' => 'routes/web-of-influence/create.php',
    '/web-of-influence/update' => 'routes/web-of-influence/update.php',
    '/web-of-influence/delete' => 'routes/web-of-influence/delete.php',
    '/web-of-influence/bulk-delete' => 'routes/web-of-influence/bulkDelete.php',
    '/web-of-influence/export' => 'routes/web-of-influence/export.php',

    '/prequalifications/list' => 'routes/prequalifications/list.php',
    '/prequalifications/show' => 'routes/prequalifications/show.php',
    '/prequalifications/create' => 'routes/prequalifications/create.php',
    '/prequalifications/update' => 'routes/prequalifications/update.php',
    '/prequalifications/delete' => 'routes/prequalifications/delete.php',
    '/prequalifications/bulk-delete' => 'routes/prequalifications/bulkDelete.php',
    '/prequalifications/export' => 'routes/prequalifications/export.php',

    '/submission-register/options' => 'routes/submission-register/options.php',
    '/submission-register/list' => 'routes/submission-register/list.php',
    '/submission-register/overview' => 'routes/submission-register/overview.php',
    '/submission-register/show' => 'routes/submission-register/show.php',
    '/submission-register/pdf' => 'routes/submission-register/pdf.php',
    '/submission-register/create' => 'routes/submission-register/create.php',
    '/submission-register/update' => 'routes/submission-register/update.php',
    '/submission-register/delete' => 'routes/submission-register/delete.php',
    '/submission-register/export' => 'routes/submission-register/export.php',
    '/submission-register/updates/list' => 'routes/submission-register/updatesList.php',
    '/submission-register/updates/create' => 'routes/submission-register/updatesCreate.php',
    '/submission-register/updates/update' => 'routes/submission-register/updatesUpdate.php',
    '/submission-register/updates/delete' => 'routes/submission-register/updatesDelete.php',

    '/surveys/public/form' => 'routes/surveys/public/form.php',
    '/surveys/public/submit' => 'routes/surveys/public/submit.php',
    '/surveys/admin/list' => 'routes/surveys/admin/list.php',
    '/surveys/admin/show' => 'routes/surveys/admin/show.php',
    '/surveys/admin/delete' => 'routes/surveys/admin/delete.php',
    '/surveys/admin/export' => 'routes/surveys/admin/export.php',
    '/surveys/admin/invitations/list' => 'routes/surveys/admin/invitations/list.php',
    '/surveys/admin/invitations/create' => 'routes/surveys/admin/invitations/create.php',
    '/surveys/admin/invitations/revoke' => 'routes/surveys/admin/invitations/revoke.php',

    '/email-templates/list' => 'routes/email-templates/list.php',
    '/email-templates/preview' => 'routes/email-templates/preview.php',
    '/email-templates/send-test' => 'routes/email-templates/sendTest.php',

    '/references/client-categories' => 'routes/references/clientCategories.php',
    '/references/countries' => 'routes/references/countries.php',
    '/references/cities' => 'routes/references/cities.php',
    '/references/project-cities' => 'routes/references/projectCities.php',
    '/references/tender-sections' => 'routes/references/tenderSections.php',
    '/references/project-options' => 'routes/references/projectOptions.php',

    '/lookups/clients' => 'routes/lookups/clients.php',
    '/lookups/keypersons' => 'routes/lookups/keypersons.php',
    '/lookups/pms-admins' => 'routes/lookups/pmsAdmins.php',
    '/lookups/users' => 'routes/lookups/users.php',
    '/lookups/projects' => 'routes/lookups/projects.php',
    '/lookups/documents' => 'routes/lookups/documents.php',
    '/lookups/tender-sections' => 'routes/lookups/tenderSections.php',

    '/projects/list' => 'routes/projects/list.php',
    '/projects/show' => 'routes/projects/show.php',
    '/projects/create' => 'routes/projects/create.php',
    '/projects/update' => 'routes/projects/update.php',
    '/projects/delete' => 'routes/projects/delete.php',
    '/projects/bulk-delete' => 'routes/projects/bulkDelete.php',
    '/projects/bulk-update-status' => 'routes/projects/bulkUpdateStatus.php',
    '/projects/export' => 'routes/projects/exportProjects.php',

    '/audit/list' => 'routes/audit/list.php',
    '/audit/export-csv' => 'routes/audit/exportCsv.php',
];


function routePermissionForPath(string $path): string|array|null
{
    $exact = [
        '/dashboard/overview' => 'dashboard.view',
        '/dashboard/pms' => 'dashboard.view',
        '/search/global' => 'global_search.use',
        '/notifications/list' => 'notifications.view',
        '/notifications/mark-read' => 'notifications.view',
        '/notifications/mark-all-read' => 'notifications.view',
        '/notifications/delete' => 'notifications.view',
        '/notifications/clear-read' => 'notifications.view',

        '/auth/invite-user' => 'users.invite',
        '/users/list' => 'users.view',
        '/users/pms-admins' => ['users.invite', 'users.edit'],
        '/users/update' => 'users.edit',
        '/users/bulk-update' => 'users.edit',
        '/users/activate' => 'users.status',
        '/users/deactivate' => 'users.status',
        '/users/reset-password' => 'users.reset',
        '/users/export' => 'users.export',
        '/users/permissions' => 'users.view',

        '/clients/list' => 'clients.view',
        '/clients/show' => 'clients.view',
        '/clients/create' => 'clients.create',
        '/clients/update' => 'clients.edit',
        '/clients/activate' => 'clients.edit',
        '/clients/delete' => 'clients.delete',
        '/clients/bulk-update' => 'clients.edit',
        '/clients/export' => 'clients.export',

        '/keypersons/list' => 'keypersons.view',
        '/keypersons/show' => 'keypersons.view',
        '/keypersons/create' => 'keypersons.create',
        '/keypersons/update' => 'keypersons.edit',
        '/keypersons/activate' => 'keypersons.edit',
        '/keypersons/delete' => 'keypersons.delete',
        '/keypersons/bulk-update' => 'keypersons.edit',
        '/keypersons/export' => 'keypersons.export',

        '/gift-lists/list' => 'gift_lists.view',
        '/gift-lists/show' => 'gift_lists.view',
        '/gift-lists/create' => 'gift_lists.create',
        '/gift-lists/update' => 'gift_lists.edit',
        '/gift-lists/update-item' => 'gift_lists.edit',
        '/gift-lists/add-keypersons' => 'gift_lists.edit',
        '/gift-lists/delete' => 'gift_lists.delete',
        '/gift-lists/bulk-delete' => 'gift_lists.delete',
        '/gift-lists/export' => 'gift_lists.export',

        '/tenders/list' => 'tenders.view',
        '/tenders/show' => 'tenders.view',
        '/tenders/create' => 'tenders.create',
        '/tenders/update' => 'tenders.edit',
        '/tenders/delete' => 'tenders.delete',
        '/tenders/bulk-delete' => 'tenders.delete',
        '/tenders/export' => 'tenders.export',

        '/documents/list' => 'documents.view',
        '/documents/show' => 'documents.view',
        '/documents/file' => 'documents.view',
        '/documents/revisions/list' => 'documents.view',
        '/documents/revisions/create' => 'documents.revisions',
        '/documents/revisions/replace' => 'documents.revisions',
        '/documents/revisions/set-current' => 'documents.revisions',
        '/documents/shares/list' => 'documents.share',
        '/documents/shares/create' => 'documents.share',
        '/documents/shares/update' => 'documents.share',
        '/documents/shares/revoke' => 'documents.share',
        '/documents/options' => 'documents.view',
        '/documents/create' => 'documents.create',
        '/documents/update' => 'documents.edit',
        '/documents/delete' => 'documents.delete',
        '/documents/bulk-delete' => 'documents.delete',
        '/documents/export' => 'documents.export',

        '/prequalifications/list' => 'prequalifications.view',
        '/prequalifications/show' => 'prequalifications.view',
        '/prequalifications/create' => 'prequalifications.create',
        '/prequalifications/update' => 'prequalifications.edit',
        '/prequalifications/delete' => 'prequalifications.delete',
        '/prequalifications/bulk-delete' => 'prequalifications.delete',
        '/prequalifications/export' => 'prequalifications.export',

        '/submission-register/options' => 'submission_register.view',
        '/submission-register/list' => 'submission_register.view',
        '/submission-register/overview' => 'submission_register.view',
        '/submission-register/show' => 'submission_register.view',
        '/submission-register/pdf' => 'submission_register.pdf',
        '/submission-register/create' => 'submission_register.create',
        '/submission-register/update' => 'submission_register.edit',
        '/submission-register/delete' => 'submission_register.delete',
        '/submission-register/export' => 'submission_register.export',
        '/submission-register/updates/list' => 'submission_register.view',
        '/submission-register/updates/create' => 'submission_register.comment',
        '/submission-register/updates/update' => 'submission_register.comment',
        '/submission-register/updates/delete' => 'submission_register.comment',

        '/surveys/admin/list' => 'client_surveys.view',
        '/surveys/admin/show' => 'client_surveys.view',
        '/surveys/admin/delete' => 'client_surveys.delete',
        '/surveys/admin/export' => 'client_surveys.export',
        '/surveys/admin/invitations/list' => 'client_surveys.view',
        '/surveys/admin/invitations/create' => 'client_surveys.create',
        '/surveys/admin/invitations/revoke' => 'client_surveys.delete',

        '/logs/list' => 'influence_logs.view',
        '/logs/create' => 'influence_logs.create',
        '/logs/update' => 'influence_logs.edit',
        '/logs/delete' => 'influence_logs.delete',
        '/logs/bulk-delete' => 'influence_logs.delete',
        '/logs/export' => 'influence_logs.export',

        '/web-of-influence/list' => 'web_of_influence.view',
        '/web-of-influence/show' => 'web_of_influence.view',
        '/web-of-influence/options' => 'web_of_influence.view',
        '/web-of-influence/create' => 'web_of_influence.create',
        '/web-of-influence/update' => 'web_of_influence.edit',
        '/web-of-influence/delete' => 'web_of_influence.delete',
        '/web-of-influence/bulk-delete' => 'web_of_influence.delete',
        '/web-of-influence/export' => 'web_of_influence.export',

        '/projects/list' => 'tenders.view',
        '/projects/show' => 'tenders.view',
        '/projects/create' => 'tenders.create',
        '/projects/update' => 'tenders.edit',
        '/projects/delete' => 'tenders.delete',
        '/projects/bulk-delete' => 'tenders.delete',
        '/projects/bulk-update-status' => 'tenders.edit',
        '/projects/export' => 'tenders.export',

        '/reports/overview' => 'reports.view',
        '/reports/export' => 'reports.export',
        '/email-templates/list' => 'email_templates.view',
        '/email-templates/preview' => 'email_templates.view',
        '/email-templates/send-test' => 'email_templates.test',
        '/settings/overview' => 'settings.view',
        '/settings/update' => 'settings.manage',
        '/audit/list' => 'audit.view',
        '/audit/export-csv' => 'audit.export',

        '/lookups/clients' => 'clients.view',
        '/lookups/keypersons' => 'keypersons.view',
        '/lookups/projects' => ['tenders.view', 'documents.view', 'prequalifications.view', 'web_of_influence.view'],
        '/lookups/documents' => 'documents.view',
        '/lookups/users' => 'users.view',
        '/lookups/pms-admins' => ['users.invite', 'users.edit'],
        '/lookups/tender-sections' => 'tenders.view',
        '/references/project-cities' => ['tenders.view', 'prequalifications.view'],
        '/references/tender-sections' => 'tenders.view',
        '/references/project-options' => ['tenders.view', 'prequalifications.view'],
    ];

    return $exact[$path] ?? null;
}

function requireRoutePermissionIfNeeded(mysqli $conn, string $path): void
{
    $required = routePermissionForPath($path);
    if ($required === null) {
        return;
    }

    $routeUser = authenticateUser();
    $requiredPermissions = is_array($required) ? $required : [$required];
    foreach ($requiredPermissions as $permission) {
        if (userHasPermission($conn, $routeUser, $permission)) {
            return;
        }
    }

    throw new RuntimeException('You are not authorised to access this workspace area.', 403);
}

requireRoutePermissionIfNeeded($conn, $relativePath);

if (array_key_exists($relativePath, $routes)) {
    $target = $routes[$relativePath];
    if (is_callable($target)) {
        $target();
    } else {
        require __DIR__ . '/' . $target;
    }
    exit;
}

jsonResponse([
    'status' => 'Failed',
    'message' => 'Route not found.'
], 404);
