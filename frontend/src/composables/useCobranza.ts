import { useApi } from './useApi'

export interface Cobro {
  id: string
  alumnoFolio: string
  concepto: 'mensualidad' | 'inscripcion'
  alumnoNombre: string
  alumnoFoto?: string
  alumnoTelefono?: string
  alumnoEmail?: string
  alumnoSaldoFavor: number
  montoBase: number
  saldoFavorAplicado: number
  montoNeto: number
  montoPagado: number
  estado: 'pendiente' | 'parcial' | 'pagado'
  requiereFactura: boolean
  facturaRealizada: boolean
  periodoDesde: string
  periodoHasta: string
  generadoEn: string
  ticket?: string
}

export interface PagoCobro {
  id: string
  monto: number
  metodo: string
  fecha: string
  ticket: string | null
  concepto: string
}

export const METODOS_PAGO = [
  { value: 'efectivo',      label: 'Efectivo' },
  { value: 'transferencia', label: 'Transferencia' },
  { value: 'credito',       label: 'Tarjeta crédito' },
  { value: 'debito',        label: 'Tarjeta débito' },
] as const

export function useCobranza() {
  const api = useApi()

  function listar(filtros: Record<string, string> = {}): Promise<Cobro[]> {
    const params = new URLSearchParams(filtros).toString()
    return api.get(`/api/cobros${params ? '?' + params : ''}`)
  }

  function obtener(id: string): Promise<Cobro> {
    return api.get(`/api/cobros/${id}`)
  }

  function registrarAbono(id: string, datos: { monto: number; metodo: string; sucursalId: string; requiereFactura?: boolean }): Promise<Cobro> {
    return api.post(`/api/cobros/${id}/abonos`, datos)
  }

  function marcarFactura(id: string, datos: { requiereFactura?: boolean; facturaRealizada?: boolean }): Promise<Cobro> {
    return api.put(`/api/cobros/${id}/factura`, datos)
  }

  function generarCobros(): Promise<{ generados: number }> {
    return api.post('/api/cobros/generar', {})
  }

  function pagosDe(cobroId: string): Promise<PagoCobro[]> {
    return api.get(`/api/cobros/${cobroId}/pagos`)
  }

  return { listar, obtener, registrarAbono, marcarFactura, generarCobros, pagosDe }
}
