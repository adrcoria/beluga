import { useApi } from './useApi'

export interface RegistroAsistencia {
  alumnoFolio: string
  alumnoNombre: string
  modalidad: string
  hora: string
  yaRegistrado: boolean
}

export interface Asistencia {
  id: string
  alumnoFolio: string
  alumnoNombre: string
  modalidad: string
  fecha: string
  hora: string
  metodo: 'qr' | 'manual'
}

export function useAsistencia() {
  const api = useApi()

  function registrar(datos: { alumnoFolio: string; sucursalId: string; metodo: 'qr' | 'manual' }): Promise<RegistroAsistencia> {
    return api.post('/api/asistencias', datos)
  }

  function listar(sucursalId: string, fecha: string): Promise<Asistencia[]> {
    return api.get(`/api/asistencias?sucursal=${encodeURIComponent(sucursalId)}&fecha=${fecha}`)
  }

  return { registrar, listar }
}
