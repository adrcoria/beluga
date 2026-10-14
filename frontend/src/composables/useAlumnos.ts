import { useApi } from './useApi'

export interface ProgramaAlumno {
  id: string
  modalidad: string
  plan: { clasesSemana: number; mensualidad: number; label: string | null }
  fechaInicio: string
  diaPago: number
  ultimoPago?: string | null
  vencimiento: string
  descuento?: { pct: number; motivo: string } | null
  status: 'activo' | 'baja'
  tocaPago: boolean
  diasMora: number
  montoConDescuento: number
}

export interface AlumnoResumen {
  folio: string
  nombre: string
  fechaNacimiento?: string
  foto?: string
  modalidad: string
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
  programas: ProgramaAlumno[]
  /** Prealumnos: de dónde llegó y qué se le ofreció. */
  notas?: string | null
  /** Cuándo vino a clase muestra. Se conserva aunque se inscriba. */
  fechaClaseMuestra?: string | null
}

/** Datos fiscales vigentes del alumno. Uno por alumno; se reusan en cada factura. */
export interface DatosFiscales {
  alumnoFolio?:   string
  razonSocial:    string
  rfc:            string
  regimenFiscal:  string | null
  usoCfdi:        string | null
  codigoPostal:   string | null
  email:          string | null
  actualizadoEn?: string
}

// Catálogos del SAT. Son claves fijas y no cambian con la operación de la
// escuela, así que viven aquí y no en base de datos. Se listan solo los que
// aplican a una escuela de natación que factura a personas físicas.
export const USOS_CFDI = [
  { value: 'G03', label: 'G03 · Gastos en general' },
  { value: 'D10', label: 'D10 · Pagos por servicios educativos' },
  { value: 'S01', label: 'S01 · Sin efectos fiscales' },
  { value: 'G01', label: 'G01 · Adquisición de mercancías' },
  { value: 'P01', label: 'P01 · Por definir' },
] as const

export const REGIMENES_FISCALES = [
  { value: '605', label: '605 · Sueldos y salarios' },
  { value: '612', label: '612 · Actividad empresarial y profesional' },
  { value: '616', label: '616 · Sin obligaciones fiscales' },
  { value: '626', label: '626 · RESICO' },
  { value: '601', label: '601 · General de ley personas morales' },
  { value: '603', label: '603 · Personas morales sin fines de lucro' },
] as const

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

  // ── Programas (multimodalidad) ──────────────────────────────────────────
  function crearPrograma(folio: string, datos: {
    modalidad: string
    plan: { clasesSemana: number; mensualidad: number }
    descuento?: { pct: number; motivo: string } | null
    fechaInicio: string
  }): Promise<AlumnoResumen> {
    return api.post(`/api/alumnos/${folio}/programas`, datos)
  }

  function actualizarPrograma(id: string, datos: Record<string, unknown>): Promise<ProgramaAlumno> {
    return api.put(`/api/alumnos/programas/${id}`, datos)
  }

  function bajaPrograma(id: string): Promise<ProgramaAlumno> {
    return api.post(`/api/alumnos/programas/${id}/baja`, {})
  }

  // Aplica desde la siguiente mensualidad: los cobros ya generados no cambian
  function cambiarDiaPago(id: string, diaPago: number): Promise<ProgramaAlumno> {
    return api.put(`/api/alumnos/programas/${id}/dia-pago`, { diaPago })
  }

  // La fecha de pago es obligatoria: al volver el alumno se confirma con qué ciclo entra
  function reactivarPrograma(id: string, fechaPago: string): Promise<ProgramaAlumno> {
    return api.post(`/api/alumnos/programas/${id}/reactivar`, { fechaPago })
  }

  // ── Datos de facturación ────────────────────────────────────────────────
  function obtenerDatosFiscales(folio: string): Promise<DatosFiscales | null> {
    return api.get(`/api/alumnos/${folio}/datos-fiscales`)
  }

  function guardarDatosFiscales(folio: string, datos: DatosFiscales): Promise<DatosFiscales> {
    return api.put(`/api/alumnos/${folio}/datos-fiscales`, datos)
  }

  return {
    listar, obtener, crear, actualizar, eliminar,
    listarPlanes, crearPlan, actualizarPlan, eliminarPlan,
    crearPrograma, actualizarPrograma, bajaPrograma,
    cambiarDiaPago, reactivarPrograma,
    obtenerDatosFiscales, guardarDatosFiscales,
  }
}
