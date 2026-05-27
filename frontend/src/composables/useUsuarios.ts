import { useApi } from './useApi'

export interface Usuario {
  id: string
  nombre: string
  email: string
  rol: 'administrador' | 'gerencia' | 'operativo'
  sucursales: string[]
  activo: boolean
  creadoEn?: string
}

export const ROLES = [
  { value: 'administrador', label: 'Administrador', color: '#1a3fe8', bg: '#dbeafe' },
  { value: 'gerencia',      label: 'Gerencia',      color: '#6d28d9', bg: '#ede9fe' },
  { value: 'operativo',     label: 'Operativo',     color: '#0e7a5e', bg: '#d1fae5' },
]

export function rolInfo(rol: string) {
  return ROLES.find(r => r.value === rol) ?? { value: rol, label: rol, color: '#6b7280', bg: '#f3f4f6' }
}

export function useUsuarios() {
  const api = useApi()

  function listar(): Promise<Usuario[]> {
    return api.get('/api/usuarios')
  }

  function crear(datos: Record<string, unknown>): Promise<Usuario> {
    return api.post('/api/usuarios', datos)
  }

  function actualizar(id: string, datos: Record<string, unknown>): Promise<Usuario> {
    return api.put(`/api/usuarios/${id}`, datos)
  }

  return { listar, crear, actualizar }
}
