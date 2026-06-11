import { ref } from 'vue'
import { defineStore } from 'pinia'

const STORAGE_KEY = 'beluga_sidebar_colapsado'

export const useUiStore = defineStore('ui', () => {
  const sidebarColapsado = ref<boolean>(localStorage.getItem(STORAGE_KEY) === '1')

  function toggleSidebar() {
    sidebarColapsado.value = !sidebarColapsado.value
    localStorage.setItem(STORAGE_KEY, sidebarColapsado.value ? '1' : '0')
  }

  return { sidebarColapsado, toggleSidebar }
})
