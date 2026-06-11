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
use Beluga\Application\Actions\Alumnos\EliminarAlumnoAction;
use Beluga\Application\Actions\Instructores\ListarInstructoresAction;
use Beluga\Application\Actions\Instructores\CrearInstructorAction;
use Beluga\Application\Actions\Instructores\ActualizarInstructorAction;
use Beluga\Application\Actions\Instructores\EliminarInstructorAction;
use Beluga\Application\Actions\Calendario\ObtenerCalendarioAction;
use Beluga\Application\Actions\Calendario\AsignarAlumnoAction;
use Beluga\Application\Actions\Calendario\ActualizarAsignacionAction;
use Beluga\Application\Actions\Calendario\EliminarAsignacionAction;
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
use Beluga\Application\Actions\Cobros\ObtenerPagosCobroAction;
use Beluga\Application\Actions\Cobros\MarcarFacturaAction;
use Beluga\Application\Actions\Usuarios\ListarUsuariosAction;
use Beluga\Application\Actions\Usuarios\CrearUsuarioAction;
use Beluga\Application\Actions\Usuarios\ActualizarUsuarioAction;
use Beluga\Application\Actions\Sucursales\ListarSucursalesAction;
use Beluga\Application\Actions\Modalidades\ListarModalidadesAction;
use Beluga\Application\Actions\Slots\ListarSlotsAction;
use Beluga\Application\Actions\Slots\CrearSlotAction;
use Beluga\Application\Actions\Slots\ActualizarSlotAction;
use Beluga\Application\Actions\Slots\EliminarSlotAction;
use Beluga\Application\Actions\Horarios\ObtenerPizarronAction;
use Beluga\Application\Actions\Horarios\CrearAsignacionAction as CrearHorarioAction;
use Beluga\Application\Actions\Horarios\MoverAsignacionAction;
use Beluga\Application\Actions\Horarios\EliminarAsignacionAction as EliminarHorarioAction;
use Beluga\Application\Actions\Grupos\ListarGruposAction;
use Beluga\Application\Actions\Grupos\CrearGrupoAction;
use Beluga\Application\Actions\Grupos\ActualizarGrupoAction;
use Beluga\Application\Actions\Grupos\EliminarGrupoAction;
use Beluga\Application\Actions\Configuracion\ObtenerConfiguracionAction;
use Beluga\Application\Actions\Configuracion\ActualizarConfiguracionAction;
use Beluga\Application\Actions\Configuracion\ObtenerFolioPagoAction;
use Beluga\Application\Actions\Configuracion\ActualizarFolioPagoAction;
use Beluga\Application\Actions\Reposiciones\ListarReposicionesAction;
use Beluga\Application\Actions\Reposiciones\CrearReposicionAction;
use Beluga\Application\Actions\Reposiciones\AgendarReposicionAction;
use Beluga\Application\Actions\Reposiciones\DesagendarReposicionAction;
use Beluga\Application\Actions\Reposiciones\CancelarReposicionAction;
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
            $g->delete('/{folio}',        EliminarAlumnoAction::class);
        });

        // Instructores
        $group->group('/instructores', function (RouteCollectorProxy $g) {
            $g->get('',        ListarInstructoresAction::class);
            $g->post('',       CrearInstructorAction::class);
            $g->put('/{id}',    ActualizarInstructorAction::class);
            $g->delete('/{id}', EliminarInstructorAction::class);
        });

        // Calendario
        $group->group('/calendario', function (RouteCollectorProxy $g) {
            $g->get('',                       ObtenerCalendarioAction::class);    // ?sucursal=&dia=
            $g->post('/asignaciones',         AsignarAlumnoAction::class);
            $g->patch('/asignaciones/{id}',   ActualizarAsignacionAction::class);
            $g->delete('/asignaciones/{id}',  EliminarAsignacionAction::class);
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
            $g->get('/{id}/pagos',   ObtenerPagosCobroAction::class);
            $g->post('/{id}/abonos', RegistrarAbonoAction::class);
            $g->put('/{id}/factura', MarcarFacturaAction::class);
        });

        // Sucursales
        $group->get('/sucursales',  ListarSucursalesAction::class);

        // Modalidades (catálogo)
        $group->get('/modalidades', ListarModalidadesAction::class);

        // Slots / Gestión de horarios
        $group->group('/slots', function (RouteCollectorProxy $g) {
            $g->get('',        ListarSlotsAction::class);
            $g->post('',       CrearSlotAction::class);
            $g->put('/{id}',   ActualizarSlotAction::class);
            $g->delete('/{id}',EliminarSlotAction::class);
        });

        // Horarios (pizarrón recurrente — modelo nuevo)
        $group->group('/horarios', function (RouteCollectorProxy $g) {
            $g->get('/pizarron', ObtenerPizarronAction::class);  // ?sucursal=&semana=
            $g->post('',         CrearHorarioAction::class);
            $g->put('/{id}',     MoverAsignacionAction::class);
            $g->delete('/{id}',  EliminarHorarioAction::class);  // ?desde=
        });

        // Grupos (GRUPAL persistentes)
        $group->group('/grupos', function (RouteCollectorProxy $g) {
            $g->get('',         ListarGruposAction::class);      // ?sucursal=
            $g->post('',        CrearGrupoAction::class);
            $g->put('/{id}',    ActualizarGrupoAction::class);
            $g->delete('/{id}', EliminarGrupoAction::class);
        });

        // Reposiciones (comodines)
        $group->group('/reposiciones', function (RouteCollectorProxy $g) {
            $g->get('',                ListarReposicionesAction::class);     // ?sucursal=&estado=
            $g->post('',               CrearReposicionAction::class);
            $g->put('/{id}/agendar',   AgendarReposicionAction::class);
            $g->put('/{id}/desagendar',DesagendarReposicionAction::class);
            $g->delete('/{id}',        CancelarReposicionAction::class);
        });

        // Configuración global
        $group->get('/configuracion', ObtenerConfiguracionAction::class);
        $group->put('/configuracion', ActualizarConfiguracionAction::class);

        // Folio consecutivo de recibos de pago (por sucursal)
        $group->get('/folio-pago', ObtenerFolioPagoAction::class);   // ?sucursal=
        $group->put('/folio-pago', ActualizarFolioPagoAction::class);

        // Usuarios del sistema
        $group->group('/usuarios', function (RouteCollectorProxy $g) {
            $g->get('',        ListarUsuariosAction::class);
            $g->post('',       CrearUsuarioAction::class);
            $g->put('/{id}',   ActualizarUsuarioAction::class);
        });

    })->add(AuthMiddleware::class);
};
