import { useApi } from './useApi'

export interface Asignacion {
  id: string
  alumnoFolio: string
  alumnoNombre: string | null
  alumnoVencimiento: string | null
  instructorId: string
  instructorNombre: string | null
  instructorIniciales: string | null
  slotId: string
  fecha: string
  estado: string
  nota: string | null
  tieneNota: boolean
}

export interface SlotPizarron {
  slotId: string
  sucursalId: string
  dia: string
  horaInicio: string
  horaFin: string
  modalidad: string
  cupoMax: number
  cupoDisponible: number
  asignaciones: Asignacion[]
}

export function useCalendario() {
  const api = useApi()

  function obtener(sucursalId: string, dia: string, fecha: string): Promise<SlotPizarron[]> {
    const q = new URLSearchParams({ sucursal: sucursalId, dia, fecha })
    return api.get<SlotPizarron[]>(`/api/calendario?${q}`)
  }

  function asignar(data: { alumnoFolio: string; slotId: string; instructorId: string; fecha: string; estado?: string }): Promise<Asignacion> {
    return api.post<Asignacion>('/api/calendario/asignaciones', data)
  }

  function actualizarAsignacion(id: string, cambios: { estado?: string; nota?: string | null; instructorId?: string }): Promise<Asignacion> {
    return api.patch<Asignacion>(`/api/calendario/asignaciones/${id}`, cambios)
  }

  function eliminarAsignacion(id: string): Promise<{ ok: boolean }> {
    return api.delete<{ ok: boolean }>(`/api/calendario/asignaciones/${id}`)
  }

  return { obtener, asignar, actualizarAsignacion, eliminarAsignacion }
}
