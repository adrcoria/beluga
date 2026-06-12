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

export interface AsistenciaReporte {
  desde: string
  hasta: string
  total: number
  porDia: { fecha: string; total: number }[]
  ausentes: { folio: string; nombre: string; ultima: string | null }[]
}

export interface Ocupacion {
  instructores: { id: string; nombre: string; iniciales: string; clases: number; alumnos: number }[]
  totalClases: number
  totalAlumnos: number
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

  return { ingresos, cartera, asistencia, ocupacion }
}
