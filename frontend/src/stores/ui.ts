import { ref } from 'vue'
import { defineStore } from 'pinia'

const STORAGE_KEY = 'beluga_sidebar_colapsado'

export const useUiStore = defineStore('ui', () => {
  const sidebarColapsado = ref<boolean>(localStorage.getItem(STORAGE_KEY) === '1')

  // Overlay global de "sincronizando datos" (p. ej. al generar cobros en login)
  const sincronizando = ref(false)

  function toggleSidebar() {
    sidebarColapsado.value = !sidebarColapsado.value
    localStorage.setItem(STORAGE_KEY, sidebarColapsado.value ? '1' : '0')
  }

  function setSincronizando(v: boolean) {
    sincronizando.value = v
  }

  return { sidebarColapsado, toggleSidebar, sincronizando, setSincronizando }
})
