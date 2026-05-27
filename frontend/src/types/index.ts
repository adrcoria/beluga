// ── Enumeraciones del dominio ──────────────────────────────────────────────

export type Modalidad = 'PART' | 'GRUPAL' | 'MATRO'

export type DiaSemana = 'L' | 'M' | 'X' | 'J' | 'V' | 'S'

export type EstadoAsignacion =
  | 'regular'
  | 'reposicion'
  | 'no_asiste'
  | 'toca_pago'
  | 'prueba'
  | 'mensaje'

export type RolUsuario =
  | 'super_admin'
  | 'gerencia'
  | 'caja'
  | 'coordinacion'
  | 'secretaria'

export type MetodoPago = 'efectivo' | 'transferencia' | 'debito' | 'credito' | 'oxxo'

export type ConceptoPago = 'mensualidad' | 'inscripcion' | 'reposicion_extra' | 'otro'

export type StatusAlumno = 'activo' | 'baja' | 'prueba'

// ── Entidades principales ──────────────────────────────────────────────────

export interface Alumno {
  folio: string
  nombre: string
  fechaNacimiento?: string        // ISO date string
  foto?: string
  contacto: {
    tutor?: string
    telefono: string
    email?: string
  }
  modalidad: Modalidad
  plan: {
    clasesSemana: number          // 1 | 2 | 3 | 5
    mensualidad: number
  }
  fechaInicio: string             // ISO date string
  diaPago: number                 // 1–31, derivado de fechaInicio
  ultimoPago?: string
  vencimiento: string
  inscripcionPagada: boolean
  descuento?: {
    pct: number
    motivo: string
  }
  disponibilidad: {
    dias: DiaSemana[]
    horaInicio: string            // '16:00'
    horaFin: string               // '20:00'
  }
  status: StatusAlumno
  sucursalId: string
  creadoEn: string
}

export interface Instructor {
  id: string
  nombre: string
  iniciales: string
  telefono: string
  email?: string
  niveles: string[]               // 'bebes' | 'infantil 1' | 'infantil 2' | 'jovenes' | 'adultos' | 'competencia' | 'matro'
  sucursales: string[]
  activo: boolean
}

export interface Slot {
  id: string
  sucursalId: string
  dia: DiaSemana
  horaInicio: string
  horaFin: string
  instructorId: string
  modalidad: Modalidad
  cupoMax: number
  cupoDisponible: number          // calculado en el servidor
  activo: boolean
}

export interface Asignacion {
  id: string
  alumnoFolio: string
  slotId: string
  fecha: string                   // ISO date string
  estado: EstadoAsignacion
  nota?: string
  generadaPorInhabilId?: string
  registradaEn: string
  // relaciones opcionales incluidas en respuestas enriquecidas
  alumno?: Pick<Alumno, 'folio' | 'nombre' | 'modalidad' | 'vencimiento'>
  slot?: Pick<Slot, 'id' | 'dia' | 'horaInicio' | 'horaFin' | 'instructorId' | 'modalidad'>
}

export interface Pago {
  id: string
  alumnoFolio: string
  concepto: ConceptoPago
  monto: number
  metodo: MetodoPago
  fecha: string
  ticket: string
  cicloDesde?: string
  cicloHasta?: string
  usuarioId: string
  sucursalId: string
}

export interface Inhabil {
  id: string
  fecha: string
  motivo: string
  sucursalId?: string
  estado: 'pendiente' | 'aplicado'
  alumnosAfectados: number
  reposicionesGeneradas: number
  declaradoPor: string
  declaradoEn: string
}

export interface Usuario {
  id: string
  nombre: string
  email: string
  rol: RolUsuario
  sucursales: string[]            // ['*'] para todas
  activo: boolean
}

export interface Sucursal {
  id: string
  nombre: string
  direccion: string
  activa: boolean
}

export interface PlanCatalogo {
  id: string
  modalidad: Modalidad
  clasesSemana: number
  mensualidad: number
  label: string                   // 'PART · 2 clases/sem'
  activo: boolean
}

// ── Helpers de UI ──────────────────────────────────────────────────────────

export interface CeldaCalendario {
  slotId: string
  instructorId: string
  instructorNombre: string
  instructorIniciales: string
  horaInicio: string
  horaFin: string
  modalidad: Modalidad
  asignaciones: Asignacion[]
  cupoMax: number
  cupoDisponible: number
}

// Colores de estado del calendario (sincronizados con CLAUDE.md)
export const COLOR_ESTADO: Record<EstadoAsignacion | Modalidad, { bg: string; text: string; border?: string }> = {
  PART:       { bg: '#DBEAFE', text: '#1E3A8A' },
  GRUPAL:     { bg: '#FCE7F3', text: '#9D174D' },
  MATRO:      { bg: '#FED7AA', text: '#9A3412' },
  regular:    { bg: '#DBEAFE', text: '#1E3A8A' },          // usa color de modalidad en runtime
  reposicion: { bg: '#FED7AA', text: '#9A3412', border: '#EA580C' },
  no_asiste:  { bg: '#BBF7D0', text: '#14532D' },
  toca_pago:  { bg: '#E5E7EB', text: '#1F2937' },
  prueba:     { bg: '#FBCFE8', text: '#831843' },
  mensaje:    { bg: '#DDD6FE', text: '#4C1D95' },
}

export const LABEL_MODALIDAD: Record<Modalidad, string> = {
  PART:   'Particular',
  GRUPAL: 'Grupal',
  MATRO:  'Matrona­tación',
}

export const LABEL_DIA: Record<DiaSemana, string> = {
  L: 'Lunes',
  M: 'Martes',
  X: 'Miércoles',
  J: 'Jueves',
  V: 'Viernes',
  S: 'Sábado',
}
