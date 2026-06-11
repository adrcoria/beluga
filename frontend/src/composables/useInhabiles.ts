import { useApi } from './useApi'

export interface Inhabil {
  id: string
  fecha: string
  motivo: string
  sucursalId: string | null
  estado: 'pendiente' | 'aplicado'
  alumnosAfectados: number
  reposicionesGeneradas: number
  declaradoPorNombre: string
  declaradoEn: string
}

export interface PreviewInhabil {
  fecha: string
  alumnosAfectados: number
  clasesAfectadas: number
  detalleAlumnos: { folio: string; nombre: string; hora: string; instructor: string }[]
}

export interface ResultadoInhabil {
  inhabilId: string
  fecha: string
  alumnosAfectados: number
  reposicionesGeneradas: number
}

export function useInhabiles() {
  const api = useApi()

  function listar(): Promise<Inhabil[]> {
    return api.get('/api/inhabiles')
  }

  function preview(datos: { fecha: string; motivo?: string; sucursalId: string }): Promise<PreviewInhabil> {
    return api.post('/api/inhabiles?preview=1', datos)
  }

  function declarar(datos: { fecha: string; motivo: string; sucursalId: string }): Promise<ResultadoInhabil> {
    return api.post('/api/inhabiles', datos)
  }

  return { listar, preview, declarar }
}
