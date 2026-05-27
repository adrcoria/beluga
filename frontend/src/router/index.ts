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
          meta: { titulo: 'Detalle del Alumno', seccion: 'personas' },
        },
        {
          path: 'instructores',
          name: 'instructores',
          component: () => import('@/views/personas/InstructoresView.vue'),
          meta: { titulo: 'Instructores', seccion: 'personas' },
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
  ],
})

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (!to.meta.publica && !auth.estaAutenticado) {
    return { name: 'login' }
  }
  if (to.name === 'login' && auth.estaAutenticado) {
    return { name: 'dashboard' }
  }
})

export default router
