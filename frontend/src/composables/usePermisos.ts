import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Permisos de la pantalla actual, según la matriz que definió el cliente
 * (docs/matriz_accesos_por_rol.xlsx).
 *
 * Esto solo decide qué se pinta. Quien realmente protege es el servidor:
 * PermisosMiddleware rechaza toda escritura de un rol en «solo ver», aunque
 * alguien se salte la interfaz. Por eso una pantalla a la que se le olvide
 * esconder un botón no abre un hueco de seguridad: el usuario recibe un aviso
 * del servidor en vez de que la acción pase.
 *
 *    const { puedeEditar, soloLectura } = usePermisos('alumnos')
 */
export function usePermisos(modulo: string) {
  const auth = useAuthStore()

  return {
    /** Puede modificar: capturar, editar, borrar. */
    puedeEditar: computed(() => auth.puedeEditar(modulo)),
    /** Entra y consulta, pero no puede cambiar nada. */
    soloLectura: computed(() => auth.soloLectura(modulo)),
    /** Para consultar otro módulo distinto al de esta pantalla. */
    puedeVer:    (otro: string) => auth.puedeVer(otro),
    puedeEditarOtro: (otro: string) => auth.puedeEditar(otro),
  }
}
