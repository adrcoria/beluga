import { useAuthStore } from '@/stores/auth'
import router from '@/router'

// In dev BASE_URL = '/' → prefix = ''. In prod BASE_URL = '/admin/' → prefix = '/admin'
export const apiBase = import.meta.env.BASE_URL.replace(/\/$/, '')

export function useApi() {
  function headers(extra: Record<string, string> = {}): Record<string, string> {
    const auth = useAuthStore()
    return {
      'Content-Type': 'application/json',
      ...(auth.token ? { Authorization: `Bearer ${auth.token}` } : {}),
      ...extra,
    }
  }

  // Sesión expirada/inválida: limpiar y mandar a login
  function sesionInvalida() {
    const auth = useAuthStore()
    if (auth.token) auth.logout()
    if (router.currentRoute.value.name !== 'login') {
      router.push({ name: 'login' })
    }
  }

  async function errorMsg(res: Response): Promise<never> {
    if (res.status === 401) sesionInvalida()
    let msg = `Error ${res.status}`
    try { msg = (await res.json()).error ?? msg } catch { /* response was not JSON */ }
    throw new Error(msg)
  }

  async function get<T>(url: string): Promise<T> {
    const res = await fetch(apiBase + url, { headers: headers() })
    if (!res.ok) return errorMsg(res)
    return res.json()
  }

  async function post<T>(url: string, body: unknown): Promise<T> {
    const res = await fetch(apiBase + url, { method: 'POST', headers: headers(), body: JSON.stringify(body) })
    if (!res.ok) return errorMsg(res)
    return res.json()
  }

  async function put<T>(url: string, body: unknown): Promise<T> {
    const res = await fetch(apiBase + url, { method: 'PUT', headers: headers(), body: JSON.stringify(body) })
    if (!res.ok) return errorMsg(res)
    return res.json()
  }

  async function patch<T>(url: string, body: unknown): Promise<T> {
    const res = await fetch(apiBase + url, { method: 'PATCH', headers: headers(), body: JSON.stringify(body) })
    if (!res.ok) return errorMsg(res)
    return res.json()
  }

  async function del<T>(url: string): Promise<T> {
    const res = await fetch(apiBase + url, { method: 'DELETE', headers: headers() })
    if (!res.ok) return errorMsg(res)
    return res.json()
  }

  return { get, post, put, patch, delete: del }
}
