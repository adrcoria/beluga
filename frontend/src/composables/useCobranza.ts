import { useApi } from './useApi'

export interface Cobro {
  id: string
  alumnoFolio: string
  programaId?: string | null
  programaModalidad?: string | null
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
  estado: 'pendiente' | 'parcial' | 'pagado' | 'condonado'
  /** Por qué no se cobró este mes. Solo viene en los condonados. */
  motivoNoCobro?: string | null
  requiereFactura: boolean
  facturaRealizada: boolean
  /** ¿El alumno ya tiene datos fiscales capturados? Para avisar antes de cobrar. */
  alumnoTieneDatosFiscales: boolean
  /** Copia congelada de los datos fiscales con los que se pidió la factura */
  datosFactura?: {
    razonSocial:   string
    rfc:           string
    regimenFiscal: string | null
    usoCfdi:       string | null
    codigoPostal:  string | null
    email:         string | null
  } | null
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

  // Pago adelantado de un programa (N meses), aunque no tenga cobros pendientes
  function registrarPagoAdelantado(datos: { programaId: string; meses: number; metodo: string; sucursalId: string; requiereFactura?: boolean }): Promise<Cobro> {
    return api.post('/api/cobros/adelantado', datos)
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

  // "No pedir cobro": condona el mes. Se da por cubierto y el vencimiento avanza.
  function condonar(id: string, motivo: string): Promise<Cobro> {
    return api.post(`/api/cobros/${id}/condonar`, { motivo })
  }

  // El ticket se imprime en el cliente (utils/ticket58.ts) por el diálogo del
  // navegador; el backend no tiene endpoint de impresión.

  return {
    listar, obtener, registrarAbono, registrarPagoAdelantado, marcarFactura,
    generarCobros, pagosDe, condonar,
  }
}
