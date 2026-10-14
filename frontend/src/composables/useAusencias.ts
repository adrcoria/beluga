import { useApi } from './useApi'

/**
 * Faltas avisadas: el alumno no va a venir a una clase.
 *
 * Si avisa con la anticipación mínima configurada, la falta da derecho a
 * reposición y el comodín se genera solo. Si avisa tarde queda registrada
 * igual, sin derecho, para el reporte y por si reclama.
 */
export interface Ausencia {
  id:               string
  alumnoFolio:      string
  alumnoNombre:     string | null
  horarioId:        string | null
  sucursalId:       string
  fecha:            string
  horaClase:        string
  /** Con cuántas horas de anticipación avisó */
  horasAviso:       number
  conDerecho:       boolean
  /** Umbral vigente cuando se registró; no se recalcula después */
  umbralHoras:      number
  motivo:           string | null
  reposicionId:     string | null
  reposicionEstado: string | null
  dia:              string
  creadoEn?:        string
}

export interface NuevaAusencia {
  alumnoFolio: string
  sucursalId:  string
  fecha:       string
  horaClase:   string
  horarioId?:  string | null
  motivo?:     string | null
}

export function useAusencias() {
  const api = useApi()

  function registrar(datos: NuevaAusencia): Promise<Ausencia> {
    return api.post('/api/ausencias', datos)
  }

  /** Deshace una falta mal capturada y cancela su comodín. */
  function anular(id: string): Promise<{ anulada: boolean }> {
    return api.delete(`/api/ausencias/${id}`)
  }

  return { registrar, anular }
}
