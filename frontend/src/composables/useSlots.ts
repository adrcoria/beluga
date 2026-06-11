import { useApi } from './useApi'

export interface Slot {
  id: string
  sucursalId: string
  dia: string
  horaInicio: string
  horaFin: string
  instructorId: string
  instructorNombre: string | null
  instructorIniciales: string | null
  modalidad: string
  cupoMax: number
  activo: boolean
}

export interface SlotPayload {
  sucursalId: string
  dia: string
  horaInicio: string
  horaFin: string
  modalidad: string
  cupoMax: number
  activo?: boolean
}

export function useSlots() {
  const api = useApi()

  function listar(params?: { sucursal?: string; dia?: string }): Promise<Slot[]> {
    const q = new URLSearchParams()
    if (params?.sucursal) q.set('sucursal', params.sucursal)
    if (params?.dia)      q.set('dia', params.dia)
    const qs = q.toString() ? `?${q}` : ''
    return api.get<Slot[]>(`/api/slots${qs}`)
  }

  function crear(datos: SlotPayload): Promise<Slot> {
    return api.post<Slot>('/api/slots', datos)
  }

  function actualizar(id: string, datos: SlotPayload): Promise<Slot> {
    return api.put<Slot>(`/api/slots/${id}`, datos)
  }

  function eliminar(id: string): Promise<{ ok: boolean }> {
    return api.delete<{ ok: boolean }>(`/api/slots/${id}`)
  }

  return { listar, crear, actualizar, eliminar }
}
