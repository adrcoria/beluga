<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Beluga\Application\Actions\Auth\LoginAction;
use Beluga\Application\Actions\Auth\VerificarOtpAction;
use Beluga\Application\Actions\Auth\CambiarPasswordAction;
use Beluga\Application\Actions\Alumnos\ListarAlumnosAction;
use Beluga\Application\Actions\Alumnos\ObtenerAlumnoAction;
use Beluga\Application\Actions\Alumnos\CrearAlumnoAction;
use Beluga\Application\Actions\Alumnos\ActualizarAlumnoAction;
use Beluga\Application\Actions\Instructores\ListarInstructoresAction;
use Beluga\Application\Actions\Instructores\CrearInstructorAction;
use Beluga\Application\Actions\Calendario\ObtenerCalendarioAction;
use Beluga\Application\Actions\Calendario\AsignarAlumnoAction;
use Beluga\Application\Actions\Calendario\ActualizarAsignacionAction;
use Beluga\Application\Actions\Calendario\ObtenerHorarioAlumnoAction;
use Beluga\Application\Actions\Pagos\ListarPagosAlumnoAction;
use Beluga\Application\Actions\Pagos\ListarPagosAction;
use Beluga\Application\Actions\Pagos\RegistrarPagoAction;
use Beluga\Application\Actions\Planes\ListarPlanesAction;
use Beluga\Application\Actions\Planes\CrearPlanAction;
use Beluga\Application\Actions\Planes\ActualizarPlanAction;
use Beluga\Application\Actions\Planes\EliminarPlanAction;
use Beluga\Application\Actions\Inhabiles\DeclararInhabilAction;
use Beluga\Application\Actions\Inhabiles\ListarInhabilesAction;
use Beluga\Application\Actions\Cobros\GenerarCobrosAction;
use Beluga\Application\Actions\Cobros\ListarCobrosAction;
use Beluga\Application\Actions\Cobros\ObtenerCobroAction;
use Beluga\Application\Actions\Cobros\RegistrarAbonoAction;
use Beluga\Application\Actions\Usuarios\ListarUsuariosAction;
use Beluga\Application\Actions\Usuarios\CrearUsuarioAction;
use Beluga\Application\Actions\Usuarios\ActualizarUsuarioAction;
use Beluga\Application\Middleware\AuthMiddleware;

return function (App $app): void {

    // OPTIONS pre-flight
    $app->options('/{routes:.+}', function ($request, $response) {
        return $response;
    });

    // ── Auth (pública) ────────────────────────────────────────────────────
    $app->post('/api/auth/login',         LoginAction::class);
    $app->post('/api/auth/verificar-otp', VerificarOtpAction::class);

    // ── Cambiar contraseña (requiere JWT válido) ───────────────────────────
    $app->post('/api/auth/cambiar-password', CambiarPasswordAction::class)
        ->add(AuthMiddleware::class);

    // ── Rutas protegidas ─────────────────────────────────────────────────
    $app->group('/api', function (RouteCollectorProxy $group) {

        // Alumnos
        $group->group('/alumnos', function (RouteCollectorProxy $g) {
            $g->get('',                   ListarAlumnosAction::class);
            $g->post('',                  CrearAlumnoAction::class);
            $g->get('/{folio}',           ObtenerAlumnoAction::class);
            $g->put('/{folio}',           ActualizarAlumnoAction::class);
            $g->get('/{folio}/horario',   ObtenerHorarioAlumnoAction::class);
        });

        // Instructores
        $group->group('/instructores', function (RouteCollectorProxy $g) {
            $g->get('',  ListarInstructoresAction::class);
            $g->post('', CrearInstructorAction::class);
        });

        // Calendario
        $group->group('/calendario', function (RouteCollectorProxy $g) {
            $g->get('',                       ObtenerCalendarioAction::class);    // ?sucursal=&dia=
            $g->post('/asignaciones',         AsignarAlumnoAction::class);
            $g->patch('/asignaciones/{id}',   ActualizarAsignacionAction::class);
        });

        // Pagos
        $group->group('/pagos', function (RouteCollectorProxy $g) {
            $g->get('',                ListarPagosAction::class);
            $g->get('/alumno/{folio}', ListarPagosAlumnoAction::class);
            $g->post('',               RegistrarPagoAction::class);
        });

        // Planes / Catálogo de mensualidades
        $group->group('/planes', function (RouteCollectorProxy $g) {
            $g->get('',        ListarPlanesAction::class);
            $g->post('',       CrearPlanAction::class);
            $g->put('/{id}',   ActualizarPlanAction::class);
            $g->delete('/{id}',EliminarPlanAction::class);
        });

        // Inhábiles / Reposiciones
        $group->group('/inhabiles', function (RouteCollectorProxy $g) {
            $g->get('',   ListarInhabilesAction::class);
            $g->post('',  DeclararInhabilAction::class);
        });

        // Cobros / Cobranza
        $group->group('/cobros', function (RouteCollectorProxy $g) {
            $g->get('',              ListarCobrosAction::class);
            $g->post('/generar',     GenerarCobrosAction::class);
            $g->get('/{id}',         ObtenerCobroAction::class);
            $g->post('/{id}/abonos', RegistrarAbonoAction::class);
        });

        // Usuarios del sistema
        $group->group('/usuarios', function (RouteCollectorProxy $g) {
            $g->get('',        ListarUsuariosAction::class);
            $g->post('',       CrearUsuarioAction::class);
            $g->put('/{id}',   ActualizarUsuarioAction::class);
        });

    })->add(AuthMiddleware::class);
};
