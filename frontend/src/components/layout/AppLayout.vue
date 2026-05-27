<script setup lang="ts">
import { onMounted } from 'vue'
import AppSidebar from './AppSidebar.vue'
import AppTopBar from './AppTopBar.vue'
import { useSucursalStore } from '@/stores/sucursal'
import { useApi } from '@/composables/useApi'
import type { Sucursal } from '@/types'

const sucursalStore = useSucursalStore()
const api = useApi()

onMounted(async () => {
  if (sucursalStore.sucursales.length) return
  try {
    const data = await api.get<Sucursal[]>('/api/sucursales')
    sucursalStore.setSucursales(data)
  } catch {}
})
</script>

<style>
.app-main {
  background:
    radial-gradient(ellipse at 85% 0%,   #c8e4ff 0%, transparent 50%),
    radial-gradient(ellipse at 0%  70%,  #d6fff0 0%, transparent 45%),
    linear-gradient(180deg, #f3fbff 0%, #e8f4ff 60%, #d8ecff 100%);
  background-attachment: fixed;
}
</style>

<template>
  <AppSidebar />
  <AppTopBar />
  <v-main class="app-main" style="min-height:100vh">
    <v-container fluid class="pa-6">
      <router-view />
    </v-container>
  </v-main>
</template>
