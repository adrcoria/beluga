import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import type { Sucursal } from '@/types'

const STORAGE_KEY = 'beluga_sucursal'

export const useSucursalStore = defineStore('sucursal', () => {
  const sucursales = ref<Sucursal[]>([])
  const sucursalActivaId = ref<string>(localStorage.getItem(STORAGE_KEY) ?? '')

  const sucursalActiva = computed(() =>
    sucursales.value.find(s => s.id === sucursalActivaId.value) ?? null
  )

  function setSucursales(data: Sucursal[]) {
    sucursales.value = data
    if (data.length && !sucursalActivaId.value) {
      cambiarSucursal(data[0].id)
    }
  }

  function cambiarSucursal(id: string) {
    sucursalActivaId.value = id
    localStorage.setItem(STORAGE_KEY, id)
  }

  function limpiar() {
    sucursales.value = []
    sucursalActivaId.value = ''
    localStorage.removeItem(STORAGE_KEY)
  }

  return { sucursales, sucursalActivaId, sucursalActiva, setSucursales, cambiarSucursal, limpiar }
})
