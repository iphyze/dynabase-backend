<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/authorization.php';

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


$adminOnlyPrefixes = ['/users/', '/projects/', '/documents/', '/logs/', '/web-of-influence/', '/prequalifications/', '/surveys/admin/', '/email-templates/', '/audit/', '/reports/', '/settings/'];
$adminOnlyExact = [
    '/auth/invite-user',
    '/reports',
    '/references/project-cities',
    '/references/tender-sections',
    '/references/project-options',
    '/lookups/projects',
    '/lookups/documents',
    '/lookups/tender-sections',
];

$requiresAdminWorkspace = in_array($relativePath, $adminOnlyExact, true);
foreach ($adminOnlyPrefixes as $prefix) {
    if (str_starts_with($relativePath, $prefix)) {
        $requiresAdminWorkspace = true;
        break;
    }
}

if ($requiresAdminWorkspace) {
    $routeUser = authenticateUser();
    requireRole(
        $routeUser,
        [DYNABASE_ROLE_SUPER_ADMIN, DYNABASE_ROLE_ADMIN],
        'This route is only available to Super Admins and Admins.'
    );
}

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
