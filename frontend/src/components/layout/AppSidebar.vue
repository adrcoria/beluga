<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const ui     = useUiStore()

const secciones = [
  {
    id: 'inicio', label: 'Inicio',
    items: [
      { name: 'dashboard', path: '/', label: 'Dashboard', icon: 'mdi-view-dashboard-outline' },
    ],
  },
  {
    id: 'operacion', label: 'Operación',
    items: [
      { name: 'calendario',   path: '/calendario',   label: 'Calendario Inteligente', icon: 'mdi-calendar-month-outline' },
      { name: 'asistencia',   path: '/asistencia',   label: 'Control de Asistencia',  icon: 'mdi-clipboard-check-outline' },
      { name: 'reposiciones', path: '/reposiciones', label: 'Reposiciones',            icon: 'mdi-refresh' },
    ],
  },
  {
    id: 'personas', label: 'Personas',
    items: [
      { name: 'alumnos',      path: '/alumnos',      label: 'Alumnos',      icon: 'mdi-account-group-outline' },
      { name: 'instructores', path: '/instructores', label: 'Instructores', icon: 'mdi-account-tie-outline' },
    ],
  },
  {
    id: 'finanzas', label: 'Finanzas',
    items: [
      { name: 'inscripciones', path: '/inscripciones', label: 'Inscripciones',  icon: 'mdi-file-document-outline', oculto: true },
      { name: 'cobranza',      path: '/cobranza',      label: 'Cobranza',       icon: 'mdi-cash-multiple' },
      { name: 'cambio-plan',   path: '/cambio-plan',   label: 'Cambio de Plan', icon: 'mdi-swap-horizontal',       oculto: true },
    ],
  },
  {
    id: 'analisis', label: 'Análisis',
    items: [
      { name: 'reportes', path: '/reportes', label: 'Reportes', icon: 'mdi-chart-bar' },
    ],
  },
  {
    id: 'config', label: 'Configuración',
    items: [
      { name: 'usuarios',  path: '/usuarios',        label: 'Usuarios del Sistema', icon: 'mdi-shield-account-outline' },
    ],
    grupos: [
      {
        label: 'Catálogos',
        icon: 'mdi-book-open-variant-outline',
        items: [
          { name: 'catalogos',  path: '/config/catalogos',  label: 'Planes de pago', icon: 'mdi-currency-usd' },
          { name: 'horarios',   path: '/config/horarios',   label: 'Horarios',        icon: 'mdi-clock-time-four-outline' },
        ],
      },
    ],
  },
  {
    id: 'premium', label: 'Premium', oculto: true,
    items: [
      { name: 'preinscripcion', path: '/preinscripcion', label: 'Preinscripción QR',  icon: 'mdi-qrcode',               pro: true },
      { name: 'multi-sucursal', path: '/multi-sucursal', label: 'Multi-sucursal',      icon: 'mdi-map-marker-multiple-outline', pro: true },
      { name: 'portal-alumno',  path: '/portal-alumno',  label: 'Portal del Alumno',  icon: 'mdi-tablet-cellphone',     pro: true },
      { name: 'comunicacion',   path: '/comunicacion',   label: 'Comunicación',        icon: 'mdi-message-outline',      pro: true },
    ],
  },
]

const nombreActivo = computed(() => route.name as string)

function cerrarSesion() {
  auth.logout()
  router.push('/login')
}
</script>

<template>
  <v-navigation-drawer permanent width="252" color="#0e3b33" :rail="ui.sidebarColapsado" rail-width="72" expand-on-hover>
    <!-- Logo -->
    <div class="px-4 pt-5 pb-4" style="border-bottom:1px solid rgba(255,255,255,0.08)">
      <div class="d-flex align-center gap-3">
        <div class="logo-wave" aria-hidden="true">
          <svg viewBox="0 0 32 16" fill="none" xmlns="http://www.w3.org/2000/svg" width="32" height="16">
            <path d="M0 8 C4 3, 7 13, 11 8 C15 3, 18 13, 22 8 C26 3, 29 13, 32 8" stroke="#2fe6b0" stroke-width="2.8" stroke-linecap="round"/>
          </svg>
        </div>
        <div>
          <div style="font-size:14.5px;font-weight:700;color:#fff;letter-spacing:-0.01em;line-height:1.2">
            Acuática<span style="color:#2fe6b0">Beluga</span>
          </div>
          <div style="font-size:9px;text-transform:uppercase;letter-spacing:0.15em;color:rgba(255,255,255,0.35);font-weight:600">
            Sistema de Gestión
          </div>
        </div>
      </div>
    </div>

    <!-- Nav -->
    <v-list density="compact" nav class="px-2 py-3">
      <template v-for="sec in secciones.filter(s => !(s as any).oculto)" :key="sec.id">
        <v-list-subheader
          :title="sec.label"
          style="font-size:9.5px;font-weight:700;letter-spacing:0.14em;color:rgba(255,255,255,0.32);text-transform:uppercase;min-height:28px;padding-top:12px"
        />
        <!-- Items planos -->
        <v-list-item
          v-for="item in sec.items.filter(i => !(i as any).oculto)"
          :key="item.name"
          :to="item.path"
          :prepend-icon="item.icon"
          :title="item.label"
          :active="nombreActivo === item.name"
          active-color="#2fe6b0"
          rounded="lg"
          class="nav-item mb-0-5"
          style="color:rgba(255,255,255,0.65);font-size:13px"
        >
          <template #append v-if="(item as any).pro">
            <v-chip size="x-small" color="warning" variant="flat" class="font-weight-bold" style="font-size:9px;letter-spacing:.04em">PRO</v-chip>
          </template>
        </v-list-item>
        <!-- Grupos expandibles (submenús) -->
        <v-list-group
          v-for="grupo in (sec as any).grupos ?? []"
          :key="grupo.label"
          :value="grupo.label"
        >
          <template #activator="{ props }">
            <v-list-item
              v-bind="props"
              :prepend-icon="grupo.icon"
              :title="grupo.label"
              rounded="lg"
              class="nav-item mb-0-5"
              style="color:rgba(255,255,255,0.65);font-size:13px"
            />
          </template>
          <v-list-item
            v-for="sub in grupo.items"
            :key="sub.name"
            :to="sub.path"
            :prepend-icon="sub.icon"
            :title="sub.label"
            :active="nombreActivo === sub.name"
            active-color="#2fe6b0"
            rounded="lg"
            class="nav-item mb-0-5 pl-4"
            style="color:rgba(255,255,255,0.55);font-size:12.5px"
          />
        </v-list-group>
      </template>
    </v-list>

    <!-- Footer usuario -->
    <template #append>
      <div style="border-top:1px solid rgba(255,255,255,0.08)" class="pa-3">
        <div class="d-flex align-center gap-2">
          <v-avatar size="32" color="rgba(47,230,176,0.2)" style="color:#2fe6b0;font-size:13px;font-weight:700">
            {{ auth.usuario?.nombre?.charAt(0) ?? 'A' }}
          </v-avatar>
          <div style="flex:1;min-width:0">
            <div style="font-size:12px;font-weight:600;color:rgba(255,255,255,0.9);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              {{ auth.usuario?.nombre ?? 'Administrador' }}
            </div>
            <div style="font-size:10px;color:rgba(255,255,255,0.38);text-transform:capitalize">
              {{ (auth.usuario?.rol ?? '').replace('_',' ') }}
            </div>
          </div>
          <v-btn icon="mdi-logout" size="x-small" variant="text" style="color:rgba(255,255,255,0.35)" @click="cerrarSesion" />
        </div>
      </div>
    </template>
  </v-navigation-drawer>
</template>

<style scoped>
:deep(.v-list-item--active) {
  background: rgba(47,230,176,0.12) !important;
}
:deep(.v-list-item__prepend .v-icon) {
  color: inherit;
  opacity: 1;
}
:deep(.v-list-item--active .v-list-item__prepend .v-icon) {
  color: #2fe6b0;
}
.mb-0-5 { margin-bottom: 1px; }
</style>
