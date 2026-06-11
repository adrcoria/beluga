<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useSucursalStore } from '@/stores/sucursal'
import { useUiStore } from '@/stores/ui'

const route         = useRoute()
const sucursalStore = useSucursalStore()
const ui            = useUiStore()

const titulo  = computed(() => route.meta.titulo as string ?? '')
const seccion = computed(() => {
  const s = route.meta.seccion as string ?? ''
  return { inicio:'Inicio', operacion:'Operación', personas:'Personas', finanzas:'Finanzas', analisis:'Análisis', config:'Sistema', premium:'Premium' }[s] ?? s
})
const ahora = computed(() => new Date().toLocaleDateString('es-MX', { weekday:'short', day:'2-digit', month:'short', year:'numeric' }))
</script>

<template>
  <v-app-bar elevation="0" color="white" border="b" height="64">
    <template #prepend>
      <v-btn
        :icon="ui.sidebarColapsado ? 'mdi-menu' : 'mdi-menu-open'"
        variant="text"
        color="#0a1e6e"
        @click="ui.toggleSidebar()"
      />
    </template>

    <v-app-bar-title>
      <div style="line-height:1">
        <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:0.12em;color:#9ca3af;margin-bottom:2px">
          {{ seccion }}
        </div>
        <div style="font-size:20px;font-weight:700;color:#0a1e6e;letter-spacing:-0.02em;font-family:'Fraunces',serif">
          {{ titulo }}
        </div>
      </div>
    </v-app-bar-title>

    <template #append>
      <div class="d-flex align-center ga-4 pr-4">
        <!-- Sucursal -->
        <div class="text-right">
          <div style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#9ca3af">Sucursal activa</div>
          <div style="font-size:13px;font-weight:700;color:#0a1e6e">
            {{ sucursalStore.sucursales[0]?.nombre ?? 'Beluga Principal' }}
          </div>
        </div>

        <v-divider vertical style="height:28px" />

        <!-- Fecha -->
        <div class="d-flex align-center ga-1" style="color:#6b7280">
          <v-icon icon="mdi-clock-outline" size="16" />
          <span style="font-size:12.5px;font-weight:500;text-transform:capitalize">{{ ahora }}</span>
        </div>
      </div>
    </template>
  </v-app-bar>
</template>
