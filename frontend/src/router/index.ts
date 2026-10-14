import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      component: () => import('@/components/layout/AppLayout.vue'),
      children: [
        // ── Inicio ────────────────────────────────────────────────────────
        {
          path: '',
          name: 'dashboard',
          component: () => import('@/views/DashboardView.vue'),
          meta: { titulo: 'Inicio', seccion: 'inicio' },
        },

        // ── Operación ─────────────────────────────────────────────────────
        {
          path: 'calendario',
          name: 'calendario',
          component: () => import('@/views/operacion/CalendarioView.vue'),
          meta: { titulo: 'Calendario', seccion: 'operacion' },
        },
        {
          path: 'asistencia',
          name: 'asistencia',
          component: () => import('@/views/operacion/AsistenciaView.vue'),
          meta: { titulo: 'Control de Asistencia', seccion: 'operacion' },
        },
        {
          path: 'reposiciones',
          name: 'reposiciones',
          component: () => import('@/views/operacion/ReposicionesView.vue'),
          meta: { titulo: 'Reposiciones', seccion: 'operacion' },
        },

        // ── Personas ──────────────────────────────────────────────────────
        {
          path: 'alumnos',
          name: 'alumnos',
          component: () => import('@/views/personas/AlumnosView.vue'),
          meta: { titulo: 'Alumnos', seccion: 'personas' },
        },
        {
          path: 'alumnos/:folio',
          name: 'alumno-detalle',
          component: () => import('@/views/personas/AlumnoDetalleView.vue'),
          // Hereda los permisos de Alumnos: no es un menú aparte
          meta: { titulo: 'Detalle del Alumno', seccion: 'personas', modulo: 'alumnos' },
        },
        {
          path: 'instructores',
          name: 'instructores',
          component: () => import('@/views/personas/InstructoresView.vue'),
          meta: { titulo: 'Instructores', seccion: 'personas' },
        },
        {
          path: 'cumpleanos',
          name: 'cumpleanos',
          component: () => import('@/views/personas/CumpleanosView.vue'),
          meta: { titulo: 'Cumpleañeros', seccion: 'personas' },
        },

        // ── Finanzas ──────────────────────────────────────────────────────
        {
          path: 'cobranza',
          name: 'cobranza',
          component: () => import('@/views/finanzas/CobranzaView.vue'),
          meta: { titulo: 'Cobranza', seccion: 'finanzas' },
        },
        {
          path: 'inscripciones',
          name: 'inscripciones',
          component: () => import('@/views/finanzas/InscripcionesView.vue'),
          meta: { titulo: 'Inscripciones', seccion: 'finanzas' },
        },
        {
          path: 'cambio-plan',
          name: 'cambio-plan',
          component: () => import('@/views/finanzas/CambioPlanView.vue'),
          meta: { titulo: 'Cambio de Plan', seccion: 'finanzas' },
        },

        // ── Análisis ──────────────────────────────────────────────────────
        {
          path: 'reportes',
          name: 'reportes',
          component: () => import('@/views/analisis/ReportesView.vue'),
          meta: { titulo: 'Reportes', seccion: 'analisis' },
        },

        // ── Configuración ─────────────────────────────────────────────────
        {
          path: 'usuarios',
          name: 'usuarios',
          component: () => import('@/views/config/UsuariosView.vue'),
          meta: { titulo: 'Usuarios del Sistema', seccion: 'config' },
        },
        {
          path: 'config/catalogos',
          name: 'catalogos',
          component: () => import('@/views/config/CatalogosView.vue'),
          meta: { titulo: 'Catálogos', seccion: 'config' },
        },
        {
          path: 'config/horarios',
          name: 'horarios',
          component: () => import('@/views/config/HorariosView.vue'),
          // El nombre de la ruta dice 'horarios' por historia; en la matriz de
          // permisos este menú es 'parametros'
          meta: { titulo: 'Parámetros generales', seccion: 'config', modulo: 'parametros' },
        },

        // ── Premium ───────────────────────────────────────────────────────
        {
          path: 'preinscripcion',
          name: 'preinscripcion',
          component: () => import('@/views/premium/PreinscripcionView.vue'),
          meta: { titulo: 'Preinscripción QR', seccion: 'premium' },
        },
        {
          path: 'portal-alumno',
          name: 'portal-alumno',
          component: () => import('@/views/premium/PortalAlumnoView.vue'),
          meta: { titulo: 'Portal del Alumno', seccion: 'premium' },
        },
        {
          path: 'comunicacion',
          name: 'comunicacion',
          component: () => import('@/views/premium/ComunicacionView.vue'),
          meta: { titulo: 'Comunicación', seccion: 'premium' },
        },
      ],
    },

    // ── Auth ──────────────────────────────────────────────────────────────
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { titulo: 'Iniciar sesión', publica: true },
    },

    // ── QR público del alumno (sin sesión) ──────────────────────────────────
    {
      path: '/qr/:folio',
      name: 'qr-publico',
      component: () => import('@/views/QrView.vue'),
      meta: { titulo: 'Mi código', publica: true },
    },
  ],
})

/**
 * Orden en que se busca a dónde mandar a un rol que no tiene Dashboard.
 * Sigue el orden del menú: cae en lo primero que pueda ver.
 */
const DESTINOS = [
  'dashboard', 'calendario', 'asistencia', 'reposiciones',
  'alumnos', 'instructores', 'cumpleanos', 'cobranza',
  'reportes', 'usuarios', 'catalogos', 'parametros',
] as const

/** Primera pantalla que el rol tiene permitida. */
function inicioDe(auth: ReturnType<typeof useAuthStore>): string {
  return DESTINOS.find(m => auth.puedeVer(m)) ?? 'login'
}

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  // Sesión de 1 día vencida: limpiar token caduco antes de evaluar acceso
  if (auth.token && auth.sesionExpirada()) {
    auth.logout()
  }
  if (!to.meta.publica && !auth.estaAutenticado) {
    return { name: 'login' }
  }
  if (to.name === 'login' && auth.estaAutenticado) {
    return { name: inicioDe(auth) }
  }
  if (to.meta.publica) return

  // Al recargar la página los permisos vienen de localStorage; si no hay
  // (primera vez, o se limpió el navegador) se piden antes de decidir.
  if (Object.keys(auth.permisos).length === 0) {
    await auth.cargarPermisos()
  }

  // El módulo que gobierna la ruta. Sin `modulo` la ruta no está sujeta a
  // permisos (el detalle de alumno hereda el de Alumnos, por ejemplo).
  const modulo = (to.meta.modulo as string | undefined) ?? (to.name as string)
  if (modulo && !auth.puedeVer(modulo)) {
    const destino = inicioDe(auth)
    // Si tampoco puede ver su propio inicio, no hay a dónde mandarlo
    return destino === (to.name as string) ? false : { name: destino }
  }
})

export default router
