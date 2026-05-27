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
  periodoDesde: string
  periodoHasta: string
  generadoEn: string
  ticket?: string
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

  function registrarAbono(id: string, datos: { monto: number; metodo: string; sucursalId: string }): Promise<Cobro> {
    return api.post(`/api/cobros/${id}/abonos`, datos)
  }

  function generarCobros(): Promise<{ generados: number }> {
    return api.post('/api/cobros/generar', {})
  }

  return { listar, obtener, registrarAbono, generarCobros }
}
