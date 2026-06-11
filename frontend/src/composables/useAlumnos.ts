import { useApi } from './useApi'

export interface AlumnoResumen {
  folio: string
  nombre: string
  fechaNacimiento?: string
  foto?: string
  modalidad: 'PART' | 'GRUPAL' | 'MATRO'
  plan: { clasesSemana: number; mensualidad: number; label: string | null }
  vencimiento: string
  ultimoPago?: string
  inscripcionPagada: boolean
  descuento?: { pct: number; motivo: string }
  status: 'activo' | 'baja' | 'prueba'
  tocaPago: boolean
  diasMora: number
  montoConDescuento: number
  contacto: { tutor?: string; telefono: string; email?: string }
  disponibilidad: { dias: string[]; horaInicio?: string; horaFin?: string }
  fechaInicio: string
  diaPago: number
}

export interface PlanCatalogo {
  id: string
  modalidad: 'PART' | 'GRUPAL' | 'MATRO'
  clasesSemana: number
  mensualidad: number
  label: string
  activo: boolean
}

export const DIAS_SEMANA = [
  { key: 'L', label: 'Lunes' },
  { key: 'M', label: 'Martes' },
  { key: 'X', label: 'Miércoles' },
  { key: 'J', label: 'Jueves' },
  { key: 'V', label: 'Viernes' },
  { key: 'S', label: 'Sábado' },
]

/** Convierte lista de planes en mapa [modalidad][clasesSemana] = mensualidad */
export function planesAMapa(planes: PlanCatalogo[]): Record<string, Record<number, number>> {
  const mapa: Record<string, Record<number, number>> = {}
  for (const p of planes) {
    if (!mapa[p.modalidad]) mapa[p.modalidad] = {}
    mapa[p.modalidad][p.clasesSemana] = p.mensualidad
  }
  return mapa
}

export function useAlumnos() {
  const api = useApi()

  function listar(filtros: Record<string, string> = {}): Promise<AlumnoResumen[]> {
    const params = new URLSearchParams(filtros).toString()
    return api.get(`/api/alumnos${params ? '?' + params : ''}`)
  }

  function obtener(folio: string): Promise<AlumnoResumen> {
    return api.get(`/api/alumnos/${folio}`)
  }

  function crear(datos: Record<string, unknown>): Promise<AlumnoResumen> {
    return api.post('/api/alumnos', datos)
  }

  function actualizar(folio: string, datos: Record<string, unknown>): Promise<AlumnoResumen> {
    return api.put(`/api/alumnos/${folio}`, datos)
  }

  function listarPlanes(): Promise<PlanCatalogo[]> {
    return api.get('/api/planes')
  }

  function crearPlan(datos: { modalidad: string; clasesSemana: number; mensualidad: number; label: string }): Promise<PlanCatalogo> {
    return api.post('/api/planes', datos)
  }

  function actualizarPlan(id: string, datos: { label?: string; mensualidad?: number; clasesSemana?: number }): Promise<PlanCatalogo> {
    return api.put(`/api/planes/${id}`, datos)
  }

  function eliminarPlan(id: string): Promise<void> {
    return api.delete(`/api/planes/${id}`)
  }

  function eliminar(folio: string): Promise<void> {
    return api.delete(`/api/alumnos/${folio}`)
  }

  return { listar, obtener, crear, actualizar, eliminar, listarPlanes, crearPlan, actualizarPlan, eliminarPlan }
}
