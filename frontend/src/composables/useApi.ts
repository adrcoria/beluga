import { useAuthStore } from '@/stores/auth'

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

  async function get<T>(url: string): Promise<T> {
    const res = await fetch(apiBase + url, { headers: headers() })
    if (!res.ok) throw new Error((await res.json()).error ?? `Error ${res.status}`)
    return res.json()
  }

  async function post<T>(url: string, body: unknown): Promise<T> {
    const res = await fetch(apiBase + url, { method: 'POST', headers: headers(), body: JSON.stringify(body) })
    if (!res.ok) throw new Error((await res.json()).error ?? `Error ${res.status}`)
    return res.json()
  }

  async function put<T>(url: string, body: unknown): Promise<T> {
    const res = await fetch(apiBase + url, { method: 'PUT', headers: headers(), body: JSON.stringify(body) })
    if (!res.ok) throw new Error((await res.json()).error ?? `Error ${res.status}`)
    return res.json()
  }

  async function del<T>(url: string): Promise<T> {
    const res = await fetch(apiBase + url, { method: 'DELETE', headers: headers() })
    if (!res.ok) throw new Error((await res.json()).error ?? `Error ${res.status}`)
    return res.json()
  }

  return { get, post, put, delete: del }
}
