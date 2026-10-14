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
use Beluga\Application\Actions\Alumnos\CrearProgramaAction;
use Beluga\Application\Actions\Alumnos\ActualizarProgramaAction;
use Beluga\Application\Actions\Alumnos\BajaProgramaAction;
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
use Beluga\Application\Actions\Cobros\PagoAdelantadoAction;
use Beluga\Application\Actions\Cobros\ObtenerPagosCobroAction;
use Beluga\Application\Actions\Cobros\MarcarFacturaAction;
use Beluga\Application\Actions\Cobros\CondonarCobroAction;
use Beluga\Application\Actions\Alumnos\CambiarDiaPagoAction;
use Beluga\Application\Actions\Alumnos\ReactivarProgramaAction;
use Beluga\Application\Actions\Alumnos\ObtenerDatosFiscalesAction;
use Beluga\Application\Actions\Alumnos\GuardarDatosFiscalesAction;
use Beluga\Application\Actions\Usuarios\ListarUsuariosAction;
use Beluga\Application\Actions\Usuarios\CrearUsuarioAction;
use Beluga\Application\Actions\Usuarios\ActualizarUsuarioAction;
use Beluga\Application\Actions\Usuarios\EliminarUsuarioAction;
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
use Beluga\Application\Actions\Asistencia\RegistrarAsistenciaAction;
use Beluga\Application\Actions\Asistencia\ListarAsistenciasAction;
use Beluga\Application\Actions\Reportes\IngresosAction;
use Beluga\Application\Actions\Reportes\CarteraAction;
use Beluga\Application\Actions\Reportes\AsistenciaReporteAction;
use Beluga\Application\Actions\Reportes\OcupacionAction;
use Beluga\Application\Actions\Public\ObtenerAlumnoQrAction;
use Beluga\Application\Actions\Ausencias\RegistrarAusenciaAction;
use Beluga\Application\Actions\Ausencias\AnularAusenciaAction;
use Beluga\Application\Actions\Reportes\ReposicionesReporteAction;
use Beluga\Application\Actions\Reportes\PrealumnosReporteAction;
use Beluga\Application\Middleware\AuthMiddleware;
use Beluga\Application\Middleware\PermisosMiddleware;
use Beluga\Application\Actions\Auth\MisPermisosAction;

return function (App $app): void {

    // OPTIONS pre-flight
    $app->options('/{routes:.+}', function ($request, $response) {
        return $response;
    });

    // ── Auth (pública) ────────────────────────────────────────────────────
    $app->post('/api/auth/login',         LoginAction::class);
    $app->post('/api/auth/verificar-otp', VerificarOtpAction::class);

    // ── Público: datos mínimos para la página del QR del alumno ─────────────
    $app->get('/api/public/qr/{folio}', ObtenerAlumnoQrAction::class);

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
            // Programas (multimodalidad)
            $g->post('/{folio}/programas',        CrearProgramaAction::class);
            $g->put('/programas/{id}',            ActualizarProgramaAction::class);
            $g->post('/programas/{id}/baja',      BajaProgramaAction::class);
            $g->put('/programas/{id}/dia-pago',   CambiarDiaPagoAction::class);
            $g->post('/programas/{id}/reactivar', ReactivarProgramaAction::class);
            // Datos de facturación del alumno
            $g->get('/{folio}/datos-fiscales', ObtenerDatosFiscalesAction::class);
            $g->put('/{folio}/datos-fiscales', GuardarDatosFiscalesAction::class);
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
            $g->post('/adelantado',  PagoAdelantadoAction::class);
            $g->get('/{id}',         ObtenerCobroAction::class);
            $g->get('/{id}/pagos',   ObtenerPagosCobroAction::class);
            $g->post('/{id}/abonos', RegistrarAbonoAction::class);
            $g->put('/{id}/factura', MarcarFacturaAction::class);
            // "No pedir cobro": condona el mes con motivo
            $g->post('/{id}/condonar', CondonarCobroAction::class);
            // El ticket se imprime en el cliente, por el diálogo del navegador
            // (frontend/src/utils/ticket58.ts). El servidor no participa en la
            // impresión: mandarle texto ESC/POS a la térmica exigiría un
            // programa instalado en cada caja, y se descartó por ese costo.
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

        // Asistencia
        $group->group('/asistencias', function (RouteCollectorProxy $g) {
            $g->get('',  ListarAsistenciasAction::class);   // ?sucursal=&fecha=
            $g->post('', RegistrarAsistenciaAction::class);
        });

        // Ausencias: avisar que un alumno no viene a una clase
        $group->group('/ausencias', function (RouteCollectorProxy $g) {
            $g->post('',        RegistrarAusenciaAction::class);
            $g->delete('/{id}', AnularAusenciaAction::class);
        });

        // Reportes
        $group->group('/reportes', function (RouteCollectorProxy $g) {
            $g->get('/ingresos',     IngresosAction::class);            // ?sucursal=&desde=&hasta=
            $g->get('/cartera',      CarteraAction::class);             // ?sucursal=
            $g->get('/asistencia',   AsistenciaReporteAction::class);   // ?sucursal=&desde=&hasta=
            $g->get('/ocupacion',    OcupacionAction::class);           // ?sucursal=
            $g->get('/reposiciones', ReposicionesReporteAction::class); // ?sucursal=&desde=&hasta=&derecho=si|no
            $g->get('/prealumnos',   PrealumnosReporteAction::class);   // ?sucursal=&desde=&hasta=
        });

        // Usuarios del sistema
        $group->group('/usuarios', function (RouteCollectorProxy $g) {
            $g->get('',         ListarUsuariosAction::class);   // ?inactivos=1
            $g->post('',        CrearUsuarioAction::class);
            $g->put('/{id}',    ActualizarUsuarioAction::class);
            $g->delete('/{id}', EliminarUsuarioAction::class);
        });

        // Permisos del usuario con la sesión abierta, para que el frontend
        // sepa qué menús pintar. No decide nada: la validación real la hace
        // PermisosMiddleware en cada petición.
        $group->get('/permisos', MisPermisosAction::class);

    // Slim corre los middleware en orden inverso al que se agregan: primero
    // AuthMiddleware (que deja el rol en la petición) y luego el de permisos,
    // que lo necesita.
    })->add(PermisosMiddleware::class)
      ->add(AuthMiddleware::class);
};
