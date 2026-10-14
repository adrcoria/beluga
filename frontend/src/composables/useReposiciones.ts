import { useApi } from './useApi'

export interface Reposicion {
  id: string
  alumnoFolio: string
  alumnoNombre: string | null
  alumnoModalidad: string | null
  sucursalId: string
  motivo: string | null
  origen: 'inhabil' | 'manual'
  /** 'tomada' la pone el sistema solo, en cuanto la fecha agendada queda atrás */
  estado: 'pendiente' | 'agendada' | 'tomada' | 'vencida' | 'cancelada'
  /** Qué día faltó el alumno. El pizarrón pinta esa celda. */
  fechaFalta: string | null
  vence: string | null
  fecha: string | null
  instructorId: string | null
  instructorNombre: string | null
  instructorIniciales: string | null
  horaInicio: string | null
  duracionMin: number | null
  creadaEn: string
}

export function useReposiciones() {
  const api = useApi()

  function listar(sucursalId: string, estado?: string): Promise<Reposicion[]> {
    const q = estado ? `&estado=${estado}` : ''
    return api.get(`/api/reposiciones?sucursal=${encodeURIComponent(sucursalId)}${q}`)
  }

  function crear(datos: { alumnoFolio: string; sucursalId: string; motivo?: string; fechaFalta: string }): Promise<Reposicion> {
    return api.post('/api/reposiciones', datos)
  }

  function agendar(
    id: string,
    datos: { fecha: string; instructorId: string; horaInicio: string; duracionMin: number },
  ): Promise<Reposicion> {
    return api.put(`/api/reposiciones/${id}/agendar`, datos)
  }

  function desagendar(id: string): Promise<Reposicion> {
    return api.put(`/api/reposiciones/${id}/desagendar`, {})
  }

  function cancelar(id: string): Promise<void> {
    return api.delete(`/api/reposiciones/${id}`)
  }

  return { listar, crear, agendar, desagendar, cancelar }
}
