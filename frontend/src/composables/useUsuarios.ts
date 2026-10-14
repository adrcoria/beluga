import { useApi } from './useApi'

export type RolUsuario =
  | 'administrador' | 'gerencia' | 'operativo' | 'instructor' | 'coordinador'

export interface Usuario {
  id: string
  nombre: string
  email: string
  rol: RolUsuario
  sucursales: string[]
  activo: boolean
  creadoEn?: string
  /** Solo viene en los inactivos: false si dejó movimientos a su nombre. */
  puedeEliminarse?: boolean
}

// Deben coincidir con el ENUM de usuarios.rol y con las claves de
// backend/config/permisos.php. Los permisos de cada uno salen de la matriz
// que llenó el cliente, no de aquí.
export const ROLES = [
  { value: 'administrador', label: 'Administrador', color: '#1a3fe8', bg: '#dbeafe' },
  { value: 'gerencia',      label: 'Gerencia',      color: '#6d28d9', bg: '#ede9fe' },
  { value: 'coordinador',   label: 'Coordinador',   color: '#b45309', bg: '#fef3c7' },
  { value: 'operativo',     label: 'Operativo',     color: '#0e7a5e', bg: '#d1fae5' },
  { value: 'instructor',    label: 'Instructor',    color: '#0369a1', bg: '#e0f2fe' },
]

export function rolInfo(rol: string) {
  return ROLES.find(r => r.value === rol) ?? { value: rol, label: rol, color: '#6b7280', bg: '#f3f4f6' }
}

export function useUsuarios() {
  const api = useApi()

  function listar(incluirInactivos = false): Promise<Usuario[]> {
    return api.get(`/api/usuarios${incluirInactivos ? '?inactivos=1' : ''}`)
  }

  /** Borrado real. Solo pasa en inactivos sin movimientos a su nombre. */
  function eliminar(id: string): Promise<{ eliminado: boolean }> {
    return api.delete(`/api/usuarios/${id}`)
  }

  function crear(datos: Record<string, unknown>): Promise<Usuario> {
    return api.post('/api/usuarios', datos)
  }

  function actualizar(id: string, datos: Record<string, unknown>): Promise<Usuario> {
    return api.put(`/api/usuarios/${id}`, datos)
  }

  return { listar, crear, actualizar, eliminar }
}
