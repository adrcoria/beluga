import { useApi } from './useApi'

export interface CeldaHorario {
  id: string
  sucursalId: string | null
  instructorId: string
  instructorNombre: string | null
  instructorIniciales: string | null
  dia: string
  horaInicio: string          // 'HH:MM'
  duracionMin: number
  modalidad: string
  alumnoFolio: string | null
  alumnoNombre: string | null
  grupoId: string | null
  grupoNombre: string | null
  grupoAlumnos: { folio: string; nombre: string }[]
  vigenteDesde: string
  vigenteHasta: string | null
  esReposicion?: boolean
  reposicionId?: string
  fecha?: string
  motivo?: string | null
  /** Clase de prueba de un prealumno: ocupa la celda una sola fecha */
  esMuestra?: boolean
}

export interface InstructorPizarron {
  id: string
  nombre: string
  iniciales: string
  niveles: string[]          // claves de modalidades que atiende
  /** Rango por día. Día ausente = no viene. Mapa vacío = sin restricción. */
  horarioPorDia?: Record<string, { horaInicio: string; horaFin: string }>
}

/**
 * Día en que un alumno faltó y por el que tiene una reposición. No es una celda:
 * marca la clase habitual que se está reponiendo, para ubicarla en el pizarrón.
 */
export interface FaltaPizarron {
  reposicionId: string
  alumnoFolio:  string
  alumnoNombre: string
  fecha:        string
  dia:          string
  motivo:       string | null
  estado:       string
}

export interface Pizarron {
  semana: { lunes: string; sabado: string }
  config: {
    horaInicio: string
    horaFin: string
    cupoGrupal: number
    /** Horas de anticipación con las que avisar da derecho a reposición */
    horasAviso: number
  }
  instructores: InstructorPizarron[]
  celdas: CeldaHorario[]
  faltas: FaltaPizarron[]
  /** Avisos de «no viene» de la semana, con su veredicto de derecho */
  ausencias: import('./useAusencias').Ausencia[]
}

export interface NuevaAsignacion {
  sucursalId: string
  instructorId: string
  dia: string
  horaInicio: string
  duracionMin: number
  modalidad: string
  alumnoFolio?: string | null
  grupoId?: string | null
  vigenteDesde: string
  vigenteHasta?: string | null
  /** true para una clase muestra: una sola fecha, no se repite */
  esMuestra?: boolean
}

export function useHorarios() {
  const api = useApi()

  function pizarron(sucursalId: string, semana: string): Promise<Pizarron> {
    return api.get(`/api/horarios/pizarron?sucursal=${encodeURIComponent(sucursalId)}&semana=${semana}`)
  }

  function crear(datos: NuevaAsignacion): Promise<CeldaHorario> {
    return api.post('/api/horarios', datos)
  }

  function mover(
    id: string,
    cambios: { instructorId?: string; dia?: string; horaInicio?: string; duracionMin?: number; desdeLunes: string },
  ): Promise<CeldaHorario> {
    return api.put(`/api/horarios/${id}`, cambios)
  }

  function eliminar(id: string, desde?: string): Promise<void> {
    const q = desde ? `?desde=${desde}` : ''
    return api.delete(`/api/horarios/${id}${q}`)
  }

  return { pizarron, crear, mover, eliminar }
}
