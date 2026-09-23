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
        $group->put('/me/password', \App\Auth\Http\MeController::class . ':changePassword')
              ->add(AuthenticateMiddleware::class);

        // ---- Auth (public; no Bearer required) ----
        $auth = \App\Auth\Http\AuthController::class;
        $group->post('/auth/login', $auth . ':login');
        $group->post('/auth/mfa/verify', $auth . ':mfaVerify');
        $group->post('/auth/refresh', $auth . ':refresh');
        $group->post('/auth/logout', $auth . ':logout');

        $container = $app->getContainer();
        $authed = fn (string $permission) => (new AuthorizeMiddleware($permission, $container->get(PermissionResolver::class)));

        // ---- Basemaps ----
        $group->get('/basemaps', \App\GIS\Http\BasemapProviderController::class . ':listPublic')
            ->add(AuthenticateMiddleware::class);
        $group->get('/basemaps/{id:[0-9]+}/tiles/{z}/{x}/{y}', \App\GIS\Http\TileProxyController::class . ':proxy')
            ->add(\App\Core\Http\Middleware\RateLimitMiddleware::class)
            ->add(AuthenticateMiddleware::class);
        $group->get('/admin/basemaps', \App\GIS\Http\BasemapProviderController::class . ':listAdmin')
            ->add($authed('basemap.manage'))->add(AuthenticateMiddleware::class);
        $group->post('/admin/basemaps', \App\GIS\Http\BasemapProviderController::class . ':create')
            ->add($authed('basemap.manage'))->add(AuthenticateMiddleware::class);
        $group->put('/admin/basemaps/{id:[0-9]+}', \App\GIS\Http\BasemapProviderController::class . ':update')
            ->add($authed('basemap.manage'))->add(AuthenticateMiddleware::class);
        $group->delete('/admin/basemaps/{id:[0-9]+}', \App\GIS\Http\BasemapProviderController::class . ':delete')
            ->add($authed('basemap.manage'))->add(AuthenticateMiddleware::class);

        // ---- Users (user.manage) ----
        $group->get('/users', \App\Users\Http\UserAdminController::class . ':list')
            ->add($authed('user.manage'))->add(AuthenticateMiddleware::class);

        // ---- GIS Layers (layer.manage) ----
        $layerAuthed = fn (string $permission) => (new AuthorizeMiddleware($permission, $container->get(PermissionResolver::class)));
        $group->get('/layers', \App\GIS\Http\GisLayerController::class . ':list')
            ->add(AuthenticateMiddleware::class);
        $group->post('/layers', \App\GIS\Http\GisLayerController::class . ':create')
            ->add($layerAuthed('gis.layer.create'))->add(AuthenticateMiddleware::class);
        $group->get('/layers/{id:[0-9]+}', \App\GIS\Http\GisLayerController::class . ':get')
            ->add(AuthenticateMiddleware::class);
        $group->put('/layers/{id:[0-9]+}', \App\GIS\Http\GisLayerController::class . ':update')
            ->add($layerAuthed('gis.layer.create'))->add(AuthenticateMiddleware::class);
        $group->delete('/layers/{id:[0-9]+}', \App\GIS\Http\GisLayerController::class . ':delete')
            ->add($layerAuthed('gis.layer.create'))->add(AuthenticateMiddleware::class);

        // ---- GIS Layer Fields ----
        $group->get('/layers/{layer_id:[0-9]+}/fields', \App\GIS\Http\GisLayerFieldController::class . ':list')
            ->add(AuthenticateMiddleware::class);
        $group->post('/layers/{layer_id:[0-9]+}/fields', \App\GIS\Http\GisLayerFieldController::class . ':create')
              ->add($layerAuthed('gis.layer.create'))->add(AuthenticateMiddleware::class);
        $group->put('/layers/{layer_id:[0-9]+}/fields/{id:[0-9]+}', \App\GIS\Http\GisLayerFieldController::class . ':update')
              ->add($layerAuthed('gis.layer.create'))->add(AuthenticateMiddleware::class);
        $group->post('/layers/{layer_id:[0-9]+}/fields/{id:[0-9]+}/retype-preview', \App\GIS\Http\GisLayerFieldController::class . ':retypePreview')
              ->add($layerAuthed('gis.layer.create'))->add(AuthenticateMiddleware::class);
        $group->delete('/layers/{layer_id:[0-9]+}/fields/{id:[0-9]+}', \App\GIS\Http\GisLayerFieldController::class . ':delete')
              ->add($layerAuthed('gis.layer.create'))->add(AuthenticateMiddleware::class);

        // ---- GIS Layer Styles ----
        $group->get('/layers/{layer_id:[0-9]+}/styles', \App\GIS\Http\GisLayerStyleController::class . ':list')
              ->add(AuthenticateMiddleware::class);
        $group->post('/layers/{layer_id:[0-9]+}/styles', \App\GIS\Http\GisLayerStyleController::class . ':create')
              ->add($layerAuthed('gis.layer.create'))->add(AuthenticateMiddleware::class);
        $group->put('/layers/{layer_id:[0-9]+}/styles/{id:[0-9]+}', \App\GIS\Http\GisLayerStyleController::class . ':update')
              ->add($layerAuthed('gis.layer.create'))->add(AuthenticateMiddleware::class);

        // ---- GIS Features (layer.manage) ----
        $group->get('/layers/{layer_id:[0-9]+}/features', \App\GIS\Http\GisFeatureController::class . ':list')
              ->add(AuthenticateMiddleware::class);
        $group->get('/layers/{layer_id:[0-9]+}/features/{id}', \App\GIS\Http\GisFeatureController::class . ':getFeature')
              ->add(AuthenticateMiddleware::class);
        $group->post('/layers/{layer_id:[0-9]+}/features', \App\GIS\Http\GisFeatureController::class . ':create')
              ->add($layerAuthed('gis.layer.create'))->add(AuthenticateMiddleware::class);
        $group->patch('/layers/{layer_id:[0-9]+}/features/{id}', \App\GIS\Http\GisFeatureController::class . ':update')
              ->add($layerAuthed('gis.layer.create'))->add(AuthenticateMiddleware::class);
        $group->delete('/layers/{layer_id:[0-9]+}/features/{id}', \App\GIS\Http\GisFeatureController::class . ':delete')
              ->add($layerAuthed('gis.layer.create'))->add(AuthenticateMiddleware::class);
        $group->get('/layers/{layer_id:[0-9]+}/features.geojson', \App\GIS\Http\GisFeatureController::class . ':geojson')
              ->add(AuthenticateMiddleware::class);
        $group->get('/layers/{layer_id:[0-9]+}/mvt/{z:\d+}/{x:\d+}/{y:\d+}.mvt', \App\GIS\Http\GisFeatureController::class . ':mvt')
                      ->add(AuthenticateMiddleware::class);

        // ---- Parcels (TASK-068) ----
        $parcelAuthed = fn (string $permission) => (new AuthorizeMiddleware($permission, $container->get(PermissionResolver::class)));
        $group->get('/parcels', \App\Parcels\Http\ParcelController::class . ':list')
              ->add($parcelAuthed('parcel.view'))->add(AuthenticateMiddleware::class);
        $group->get('/parcels/{id}', \App\Parcels\Http\ParcelController::class . ':get')
              ->add($parcelAuthed('parcel.view'))->add(AuthenticateMiddleware::class);
        $group->post('/parcels', \App\Parcels\Http\ParcelController::class . ':create')
              ->add($parcelAuthed('parcel.create'))->add(AuthenticateMiddleware::class);
        $group->patch('/parcels/{id}', \App\Parcels\Http\ParcelController::class . ':update')
              ->add($parcelAuthed('parcel.update'))->add(AuthenticateMiddleware::class);
        $group->delete('/parcels/{id}', \App\Parcels\Http\ParcelController::class . ':delete')
              ->add($parcelAuthed('parcel.delete'))->add(AuthenticateMiddleware::class);

        // ---- Parcel versioning (TASK-069) ----
        $group->get('/parcels/{id}/versions', \App\Parcels\Http\ParcelController::class . ':versions')
              ->add($parcelAuthed('parcel.lineage.view'))->add(AuthenticateMiddleware::class);
        $group->get('/parcels/{id}/versions/{v:[0-9]+}', \App\Parcels\Http\ParcelController::class . ':version')
              ->add($parcelAuthed('parcel.lineage.view'))->add(AuthenticateMiddleware::class);
        $group->post('/parcels/{id}/versions/{v:[0-9]+}/restore', \App\Parcels\Http\ParcelController::class . ':restore')
              ->add($parcelAuthed('parcel.version.restore'))->add(AuthenticateMiddleware::class);

                // ---- Spatial queries (TASK-063) ----
        $group->post('/spatial/query', \App\GIS\Http\SpatialQueryController::class . ':query')
              ->add(AuthenticateMiddleware::class);
        $group->get('/spatial/query/bbox', \App\GIS\Http\SpatialQueryController::class . ':bbox')
              ->add(AuthenticateMiddleware::class);

        // ---- CRS registry (TASK-014/056) ----
        $group->get('/crs', \App\GIS\Http\CrsController::class . ':list')
              ->add(AuthenticateMiddleware::class);
        $group->get('/crs/{id:[0-9]+}', \App\GIS\Http\CrsController::class . ':get')
              ->add(AuthenticateMiddleware::class);

        // ---- Spatial tools (TASK-062) ----
                $group->post('/spatial/measure', \App\GIS\Http\SpatialToolController::class . ':measure')
                      ->add(AuthenticateMiddleware::class);
                $group->get('/spatial/identify', \App\GIS\Http\SpatialToolController::class . ':identify')
                      ->add(AuthenticateMiddleware::class);
                $group->get('/spatial/identify-nearby', \App\GIS\Http\SpatialToolController::class . ':identifyNearby')
                      ->add(AuthenticateMiddleware::class);

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
        $group->get('/users/{id}/mfa', \App\Users\Http\UserAdminController::class . ':getMfa')
            ->add($authed('user.manage'))->add(AuthenticateMiddleware::class);
        $group->post('/users/{id}/mfa/enroll', \App\Users\Http\UserAdminController::class . ':enrollMfa')
            ->add($authed('user.manage'))->add(AuthenticateMiddleware::class);
        $group->post('/users/{id}/mfa/disable', \App\Users\Http\UserAdminController::class . ':disableMfa')
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

        // ---- Audit Logs (audit.view) ----
        $group->get('/audit-logs/export', \App\Audit\Http\AuditQueryController::class . ':export')
            ->add($authed('audit.export'))->add(AuthenticateMiddleware::class);
        $group->get('/audit-logs', \App\Audit\Http\AuditQueryController::class . ':list')
            ->add($authed('audit.view'))->add(AuthenticateMiddleware::class);
        $group->get('/audit-logs/{id}', \App\Audit\Http\AuditQueryController::class . ':get')
            ->add($authed('audit.view'))->add(AuthenticateMiddleware::class);

        // ---- Control points (Phase 9, TASK-073; 074 adds verify/dependents, 075 adds nearest) ----
        $cpAuthed = fn (string $permission) => (new AuthorizeMiddleware($permission, $container->get(PermissionResolver::class)));
        $cp = \App\Survey\Http\ControlPointController::class;
        $group->get('/control-points', $cp . ':list')
            ->add($cpAuthed('control_point.view'))->add(AuthenticateMiddleware::class);
        $group->post('/control-points', $cp . ':create')
            ->add($cpAuthed('control_point.create'))->add(AuthenticateMiddleware::class);
        $group->get('/control-points/nearest', $cp . ':nearest')
            ->add($cpAuthed('control_point.view'))->add(AuthenticateMiddleware::class);
        $group->get('/control-points/{id:[0-9]+}', $cp . ':get')
            ->add($cpAuthed('control_point.view'))->add(AuthenticateMiddleware::class);
        $group->put('/control-points/{id:[0-9]+}', $cp . ':update')
            ->add($cpAuthed('control_point.update'))->add(AuthenticateMiddleware::class);
        $group->delete('/control-points/{id:[0-9]+}', $cp . ':delete')
            ->add($cpAuthed('control_point.update'))->add(AuthenticateMiddleware::class);
        $group->post('/control-points/{id:[0-9]+}/verify', $cp . ':verify')
            ->add($cpAuthed('control_point.verify'))->add(AuthenticateMiddleware::class);
        $group->get('/control-points/{id:[0-9]+}/dependents', $cp . ':dependents')
            ->add($cpAuthed('control_point.view'))->add(AuthenticateMiddleware::class);

        // ---- Survey plans (Phase 9, TASK-077) ----
        $spAuthed = fn (string $permission) => (new AuthorizeMiddleware($permission, $container->get(PermissionResolver::class)));
        $sp = \App\Survey\Http\SurveyPlanController::class;
        $group->get('/survey-plans', $sp . ':list')
            ->add($spAuthed('survey.view'))->add(AuthenticateMiddleware::class);
        $group->post('/survey-plans', $sp . ':create')
            ->add($spAuthed('survey.create'))->add(AuthenticateMiddleware::class);
        $group->get('/survey-plans/{id:[0-9]+}', $sp . ':get')
            ->add($spAuthed('survey.view'))->add(AuthenticateMiddleware::class);
        $group->put('/survey-plans/{id:[0-9]+}', $sp . ':update')
            ->add($spAuthed('survey.update'))->add(AuthenticateMiddleware::class);
        $group->delete('/survey-plans/{id:[0-9]+}', $sp . ':delete')
            ->add($spAuthed('survey.update'))->add(AuthenticateMiddleware::class);
        $group->get('/survey-plans/{id:[0-9]+}/parcels', $sp . ':parcels')
            ->add($spAuthed('survey.view'))->add(AuthenticateMiddleware::class);
    });
};

