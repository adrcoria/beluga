import { useApi } from './useApi'

export interface Configuracion {
  horario_grid_inicio?: string
  horario_grid_fin?: string
  horario_cupo_grupal?: string
  /** Horas de anticipación mínimas para que una falta dé derecho a reposición. */
  ausencia_horas_aviso?: string
  [key: string]: string | undefined
}

export function useConfiguracion() {
  const api = useApi()

  function obtener(): Promise<Configuracion> {
    return api.get('/api/configuracion')
  }

  function actualizar(datos: Configuracion): Promise<Configuracion> {
    return api.put('/api/configuracion', datos)
  }

  function obtenerFolioPago(sucursalId: string): Promise<{ siguiente: number }> {
    return api.get(`/api/folio-pago?sucursal=${encodeURIComponent(sucursalId)}`)
  }

  function actualizarFolioPago(sucursalId: string, siguiente: number): Promise<{ siguiente: number }> {
    return api.put('/api/folio-pago', { sucursalId, siguiente })
  }

  return { obtener, actualizar, obtenerFolioPago, actualizarFolioPago }
}
