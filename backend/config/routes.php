<?php
declare(strict_types=1);

use Slim\App;
use App\Core\Http\Controllers\HealthController;
use App\Core\Http\Middleware\AuthenticateMiddleware;
use App\Core\Http\Middleware\AuthorizeMiddleware;
use App\RBAC\PermissionResolver;

return function (App $app) {
    $app->group('/api/v1', function ($group) use ($app) {
        $group->get('/health', HealthController::class);
        $group->get('/me', \App\Auth\Http\MeController::class)
              ->add(AuthenticateMiddleware::class);

        $container = $app->getContainer();
        $authed = fn (string $permission) => (new AuthorizeMiddleware($permission, $container->get(PermissionResolver::class)));

        // ---- Users (user.manage) ----
        $group->get('/users', \App\Users\Http\UserAdminController::class . ':list')
            ->add($authed('user.manage'))->add(AuthenticateMiddleware::class);
        $group->post('/users', \App\Users\Http\UserAdminController::class . ':create')
            ->add($authed('user.manage'))->add(AuthenticateMiddleware::class);
        $group->get('/users/{id}', \App\Users\Http\UserAdminController::class . ':get')
            ->add($authed('user.manage'))->add(AuthenticateMiddleware::class);
        $group->put('/users/{id}', \App\Users\Http\UserAdminController::class . ':update')
            ->add($authed('user.manage'))->add(AuthenticateMiddleware::class);
        $group->post('/users/{id}/deactivate', \App\Users\Http\UserAdminController::class . ':deactivate')
            ->add($authed('user.manage'))->add(AuthenticateMiddleware::class);
        $group->put('/users/{id}/roles', \App\Users\Http\UserAdminController::class . ':setRoles')
            ->add($authed('user.manage'))->add(AuthenticateMiddleware::class);
        $group->get('/users/{id}/scopes', \App\Users\Http\UserAdminController::class . ':getScopes')
            ->add($authed('scope.manage'))->add(AuthenticateMiddleware::class);
        $group->put('/users/{id}/scopes', \App\Users\Http\UserAdminController::class . ':setScopes')
            ->add($authed('scope.manage'))->add(AuthenticateMiddleware::class);
        $group->post('/users/{id}/force-password-reset', \App\Users\Http\UserAdminController::class . ':forcePasswordReset')
            ->add($authed('user.manage'))->add(AuthenticateMiddleware::class);
        $group->get('/users/{id}/effective-access', \App\Users\Http\UserAdminController::class . ':effectiveAccess')
            ->add($authed('user.manage'))->add(AuthenticateMiddleware::class);

        // ---- Roles & permissions (role.manage) ----
        $group->get('/roles', \App\RBAC\Http\RoleAdminController::class . ':list')
            ->add($authed('role.manage'))->add(AuthenticateMiddleware::class);
        $group->post('/roles', \App\RBAC\Http\RoleAdminController::class . ':create')
            ->add($authed('role.manage'))->add(AuthenticateMiddleware::class);
        $group->get('/roles/{id}', \App\RBAC\Http\RoleAdminController::class . ':get')
            ->add($authed('role.manage'))->add(AuthenticateMiddleware::class);
        $group->put('/roles/{id}', \App\RBAC\Http\RoleAdminController::class . ':update')
            ->add($authed('role.manage'))->add(AuthenticateMiddleware::class);
        $group->delete('/roles/{id}', \App\RBAC\Http\RoleAdminController::class . ':delete')
            ->add($authed('role.manage'))->add(AuthenticateMiddleware::class);
        $group->put('/roles/{id}/permissions', \App\RBAC\Http\RoleAdminController::class . ':setPermissions')
            ->add($authed('role.manage'))->add(AuthenticateMiddleware::class);
        $group->get('/permissions', \App\RBAC\Http\RoleAdminController::class . ':listPermissions')
            ->add($authed('role.manage'))->add(AuthenticateMiddleware::class);

        // ---- Organisations (system.config) ----
        $group->get('/organizations', \App\Organizations\Http\OrganizationAdminController::class . ':list')
            ->add($authed('system.config'))->add(AuthenticateMiddleware::class);
        $group->post('/organizations', \App\Organizations\Http\OrganizationAdminController::class . ':create')
            ->add($authed('system.config'))->add(AuthenticateMiddleware::class);
        $group->get('/organizations/{id}', \App\Organizations\Http\OrganizationAdminController::class . ':get')
            ->add($authed('system.config'))->add(AuthenticateMiddleware::class);
        $group->put('/organizations/{id}', \App\Organizations\Http\OrganizationAdminController::class . ':update')
            ->add($authed('system.config'))->add(AuthenticateMiddleware::class);
        $group->post('/organizations/{id}/deactivate', \App\Organizations\Http\OrganizationAdminController::class . ':deactivate')
            ->add($authed('system.config'))->add(AuthenticateMiddleware::class);
    });
};