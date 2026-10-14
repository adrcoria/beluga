import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import type { Usuario } from '@/types'

const TOKEN_KEY    = 'beluga_token'
const USUARIO_KEY  = 'beluga_usuario'
const PERMISOS_KEY = 'beluga_permisos'

/** Nivel de acceso a un módulo, según la matriz que definió el cliente. */
export type NivelAcceso = 'total' | 'ver' | 'no'

// Se calcula aquí y no se importa de useApi para no crear un ciclo: useApi
// importa este store.
const apiBase = import.meta.env.BASE_URL.replace(/\/$/, '')

function leer<T>(llave: string): T | null {
  try {
    const raw = localStorage.getItem(llave)
    return raw ? JSON.parse(raw) : null
  } catch { return null }
}

export const useAuthStore = defineStore('auth', () => {
  const usuario  = ref<Usuario | null>(leer<Usuario>(USUARIO_KEY))
  const token    = ref<string | null>(localStorage.getItem(TOKEN_KEY))
  const permisos = ref<Record<string, NivelAcceso>>(leer<Record<string, NivelAcceso>>(PERMISOS_KEY) ?? {})

  /** Lee el claim `exp` (segundos epoch) del JWT, o null si no se puede. */
  function expToken(): number | null {
    if (!token.value) return null
    try {
      const payload = JSON.parse(atob(token.value.split('.')[1]))
      return typeof payload.exp === 'number' ? payload.exp : null
    } catch { return null }
  }

  /** true si hay token pero ya caducó (sesión de 1 día vencida). */
  function sesionExpirada(): boolean {
    const exp = expToken()
    return exp !== null && Date.now() / 1000 >= exp
  }

  const estaAutenticado = computed(() => !!token.value && !!usuario.value && !sesionExpirada())

  // ── Permisos ──────────────────────────────────────────────────────────
  // La matriz la define el cliente (docs/matriz_accesos_por_rol.xlsx) y el
  // servidor la aplica en cada petición. Lo de aquí solo decide qué se pinta:
  // esconder un botón no protege nada, por eso la validación de verdad está
  // en PermisosMiddleware.

  /**
   * Un módulo que no aparece en la matriz queda sin gobernar y se muestra: son
   * los que el cliente sacó del alcance (ocultos y premium), no módulos a los
   * que se les haya negado el acceso. Para los que sí están, manda la matriz.
   */
  const nivel = (modulo: string): NivelAcceso =>
    modulo in permisos.value ? permisos.value[modulo] : 'total'

  const puedeVer    = (modulo: string) => nivel(modulo) !== 'no'
  const puedeEditar = (modulo: string) => nivel(modulo) === 'total'

  /** true cuando el módulo se puede consultar pero no modificar. */
  const soloLectura = (modulo: string) => nivel(modulo) === 'ver'

  /** Trae del servidor los permisos del rol con la sesión abierta. */
  async function cargarPermisos(): Promise<void> {
    if (!token.value) return
    try {
      const res = await fetch(`${apiBase}/api/permisos`, {
        headers: { Authorization: `Bearer ${token.value}` },
      })
      if (!res.ok) return
      const data = await res.json()
      permisos.value = data.modulos ?? {}
      localStorage.setItem(PERMISOS_KEY, JSON.stringify(permisos.value))
    } catch {
      // Sin red se conserva lo último conocido; el servidor valida igual.
    }
  }

  function login(u: Usuario, t: string) {
    usuario.value = u
    token.value   = t
    localStorage.setItem(TOKEN_KEY, t)
    localStorage.setItem(USUARIO_KEY, JSON.stringify(u))
  }

  function logout() {
    usuario.value  = null
    token.value    = null
    permisos.value = {}
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(USUARIO_KEY)
    localStorage.removeItem(PERMISOS_KEY)
  }

  return {
    usuario, token, permisos,
    estaAutenticado, sesionExpirada,
    nivel, puedeVer, puedeEditar, soloLectura, cargarPermisos,
    login, logout,
  }
})
