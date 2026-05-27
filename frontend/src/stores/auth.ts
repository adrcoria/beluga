import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import type { Usuario } from '@/types'

const TOKEN_KEY  = 'beluga_token'
const USUARIO_KEY = 'beluga_usuario'

function cargarUsuarioGuardado(): Usuario | null {
  try {
    const raw = localStorage.getItem(USUARIO_KEY)
    return raw ? JSON.parse(raw) : null
  } catch { return null }
}

export const useAuthStore = defineStore('auth', () => {
  const usuario = ref<Usuario | null>(cargarUsuarioGuardado())
  const token   = ref<string | null>(localStorage.getItem(TOKEN_KEY))

  const estaAutenticado = computed(() => !!token.value && !!usuario.value)

  const puedeAcceder = (modulo: string) => {
    if (!usuario.value) return false
    if (usuario.value.rol === 'super_admin') return true
    const permisos: Record<string, string[]> = {
      administrador: ['dashboard', 'reportes', 'alumnos', 'instructores', 'cobranza', 'inscripciones', 'calendario', 'usuarios', 'catalogos'],
      gerencia:      ['dashboard', 'reportes', 'alumnos', 'instructores', 'cobranza', 'inscripciones', 'calendario'],
      operativo:     ['dashboard', 'calendario', 'asistencia', 'reposiciones', 'alumnos', 'instructores', 'cobranza', 'inscripciones', 'cambio-plan'],
    }
    return permisos[usuario.value.rol]?.includes(modulo) ?? false
  }

  function login(u: Usuario, t: string) {
    usuario.value = u
    token.value   = t
    localStorage.setItem(TOKEN_KEY, t)
    localStorage.setItem(USUARIO_KEY, JSON.stringify(u))
  }

  function logout() {
    usuario.value = null
    token.value   = null
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(USUARIO_KEY)
  }

  return { usuario, token, estaAutenticado, puedeAcceder, login, logout }
})
