import { useApi } from './useApi'

export interface Grupo {
  id: string
  nombre: string
  sucursalId: string
  activo: boolean
  alumnos: { folio: string; nombre: string }[]
}

/** Modalidades que se asignan por grupo (no por alumno individual). */
export const MODALIDADES_GRUPO = ['GRUPAL', 'MINI']
export const esModalidadGrupo = (clave: string): boolean => MODALIDADES_GRUPO.includes(clave)

export function useGrupos() {
  const api = useApi()

  function listar(sucursalId: string): Promise<Grupo[]> {
    return api.get(`/api/grupos?sucursal=${encodeURIComponent(sucursalId)}`)
  }

  function crear(datos: { nombre: string; sucursalId: string; alumnos: string[] }): Promise<Grupo> {
    return api.post('/api/grupos', datos)
  }

  function actualizar(id: string, datos: { nombre?: string; alumnos?: string[] }): Promise<Grupo> {
    return api.put(`/api/grupos/${id}`, datos)
  }

  function eliminar(id: string): Promise<void> {
    return api.delete(`/api/grupos/${id}`)
  }

  return { listar, crear, actualizar, eliminar }
}
