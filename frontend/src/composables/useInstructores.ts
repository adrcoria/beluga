import { useApi } from './useApi'

export interface Instructor {
  id: string
  nombre: string
  iniciales: string
  telefono: string
  email?: string | null
  niveles: string[]
  sucursales: string[]
  activo: boolean
  /**
   * Horario real del maestro: un rango por día. Sin días = sin restricción.
   * Un día ausente del mapa significa que no viene ese día.
   */
  horarioPorDia: Record<string, { horaInicio: string; horaFin: string }>
  /** @deprecated Rango único de la etapa anterior. La fuente de verdad es horarioPorDia. */
  dispHoraInicio?: string | null
  /** @deprecated Ver horarioPorDia */
  dispHoraFin?: string | null
  /** @deprecated Ver horarioPorDia */
  diasAsiste?: string[]
}

export function useInstructores() {
  const api = useApi()

  function listar(params?: { sucursalId?: string; todos?: boolean }): Promise<Instructor[]> {
    const q = new URLSearchParams()
    if (params?.sucursalId) q.set('sucursal', params.sucursalId)
    if (params?.todos)      q.set('todos', 'true')
    const qs = q.toString() ? `?${q}` : ''
    return api.get<Instructor[]>(`/api/instructores${qs}`)
  }

  function crear(datos: Omit<Instructor, 'id' | 'activo'>): Promise<Instructor> {
    return api.post<Instructor>('/api/instructores', datos)
  }

  function actualizar(id: string, datos: Partial<Instructor>): Promise<Instructor> {
    return api.put<Instructor>(`/api/instructores/${id}`, datos)
  }

  function eliminar(id: string): Promise<void> {
    return api.delete<void>(`/api/instructores/${id}`)
  }

  return { listar, crear, actualizar, eliminar }
}
