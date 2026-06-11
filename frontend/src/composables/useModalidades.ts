import { useApi } from './useApi'

export interface Modalidad {
  clave: string
  label: string
  descripcion: string | null
}

export function useModalidades() {
  const api = useApi()
  function listar(): Promise<Modalidad[]> {
    return api.get('/api/modalidades')
  }
  return { listar }
}
