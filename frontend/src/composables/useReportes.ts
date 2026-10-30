import { useApi } from './useApi'

export interface Ingresos {
  desde: string
  hasta: string
  total: number
  totalCount: number
  porMetodo: { metodo: string; c: number; total: number }[]
  porConcepto: { concepto: string; c: number; total: number }[]
  porDia: { fecha: string; c: number; total: number }[]
  detalle: { fecha: string; ticket: string | null; monto: number; metodo: string; concepto: string; folio: string; alumno: string }[]
}

export interface Cartera {
  total: number
  alumnos: number
  cartera: { folio: string; nombre: string; telefono: string | null; pendiente: number; desde: string; diasMora: number; cobros: number }[]
}

/**
 * Asistencia medida contra lo programado: no cuenta escaneos sueltos sino
 * clases esperadas frente a clases a las que el alumno se presentó.
 */
export interface AsistenciaReporte {
  desde: string
  hasta: string
  resumen: {
    clases: number
    asistieron: number
    /** Faltó, pero avisó con anticipación */
    avisaron: number
    /** Faltó sin avisar */
    faltaron: number
    /** Clases de días que todavía no cierran */
    pendientes: number
    porcentajeAsistencia: number | null
  }
  porDia: {
    fecha: string
    clases: number
    asistieron: number
    avisaron: number
    faltaron: number
    pendientes: number
  }[]
  alumnos: {
    folio: string
    nombre: string
    telefono: string | null
    clases: number
    asistio: number
    aviso: number
    falto: number
    pendiente: number
    ultima: string | null
    porcentaje: number | null
  }[]
  /** Activos que no se presentaron ni una vez en el periodo */
  ausentes: { folio: string; nombre: string; telefono: string | null; ultima: string | null }[]
}

export interface Ocupacion {
  instructores: { id: string; nombre: string; iniciales: string; clases: number; alumnos: number }[]
  totalClases: number
  totalAlumnos: number
}

/** Faltas del periodo, separando las que dieron derecho a reposición. */
export interface ReposicionesReporte {
  umbralHoras: number
  resumen: { total: number; conDerecho: number; sinDerecho: number; porUsar: number }
  faltas: {
    id: string
    alumnoFolio: string
    alumnoNombre: string | null
    fecha: string
    horaClase: string
    horasAviso: number
    conDerecho: boolean
    umbralHoras: number
    motivo: string | null
    reposicionEstado: string | null
  }[]
}

/** Prealumnos: quién vino a clase muestra y si terminó inscribiéndose. */
export interface PrealumnosReporte {
  resumen: { enPrueba: number; agendados: number; sinAgendar: number; convertidos: number }
  prealumnos: {
    folio: string
    nombre: string
    telefono: string
    email: string | null
    tutor: string | null
    notas: string | null
    modalidad: string
    fechaClaseMuestra: string | null
    registradoEn: string
    clasesMuestra: number
    agendado: boolean
  }[]
}

export function useReportes() {
  const api = useApi()
  const q = (params: Record<string, string>) => new URLSearchParams(params).toString()

  function ingresos(sucursalId: string, desde: string, hasta: string): Promise<Ingresos> {
    return api.get(`/api/reportes/ingresos?${q({ sucursal: sucursalId, desde, hasta })}`)
  }
  function cartera(sucursalId: string): Promise<Cartera> {
    return api.get(`/api/reportes/cartera?${q({ sucursal: sucursalId })}`)
  }
  function asistencia(sucursalId: string, desde: string, hasta: string): Promise<AsistenciaReporte> {
    return api.get(`/api/reportes/asistencia?${q({ sucursal: sucursalId, desde, hasta })}`)
  }
  function ocupacion(sucursalId: string): Promise<Ocupacion> {
    return api.get(`/api/reportes/ocupacion?${q({ sucursal: sucursalId })}`)
  }

  /** @param derecho 'si' | 'no' para filtrar; vacío trae todas */
  function reposiciones(sucursalId: string, desde: string, hasta: string, derecho = ''): Promise<ReposicionesReporte> {
    const params: Record<string, string> = { sucursal: sucursalId, desde, hasta }
    if (derecho) params.derecho = derecho
    return api.get(`/api/reportes/reposiciones?${q(params)}`)
  }
  function prealumnos(sucursalId: string, desde: string, hasta: string): Promise<PrealumnosReporte> {
    return api.get(`/api/reportes/prealumnos?${q({ sucursal: sucursalId, desde, hasta })}`)
  }

  return { ingresos, cartera, asistencia, ocupacion, reposiciones, prealumnos }
}
