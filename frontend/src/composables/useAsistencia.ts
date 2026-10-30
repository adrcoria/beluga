import { useApi } from './useApi'

export interface RegistroAsistencia {
  alumnoFolio: string
  alumnoNombre: string
  modalidad: string
  hora: string
  yaRegistrado: boolean
  /** La clase a la que se le asignó el escaneo */
  clase: {
    horaClase: string
    instructorNombre: string | null
    esReposicion: boolean
    esMuestra: boolean
  }
}

export type EstadoAsistencia = 'asistio' | 'no_asistio'

/** Una clase programada de un alumno en un día, con su estado. */
export interface ClaseDelDia {
  alumnoFolio: string
  alumnoNombre: string
  alumnoTelefono: string | null
  alumnoStatus: string
  horaClase: string
  horarioId: string | null
  reposicionId: string | null
  duracionMin: number
  modalidad: string
  esMuestra: boolean
  esReposicion: boolean
  instructorId: string
  instructorNombre: string | null
  instructorIniciales: string | null
  grupoNombre: string | null
  /** null = todavía sin resolver (el día no ha cerrado) */
  estado: EstadoAsistencia | null
  metodo: 'qr' | 'manual' | 'auto' | null
  horaRegistro: string | null
  /** Si avisó que no venía, con su veredicto de derecho a reposición */
  aviso: { conDerecho: boolean; motivo: string | null } | null
}

export interface PasadaDeLista {
  fecha: string
  abierto: boolean
  cierre: {
    fecha: string
    cerradoEn: string
    clases: number
    asistieron: number
    faltaron: number
  } | null
  resumen: {
    clases: number
    asistieron: number
    faltaronAvisando: number
    faltaronSinAvisar: number
    pendientes: number
    porcentajeAsistencia: number | null
  }
  clases: ClaseDelDia[]
  /** Ventana en la que vale el escaneo de entrada */
  ventana: { minAntes: number; retardoMax: number }
}

export function useAsistencia() {
  const api = useApi()

  /** Registra la llegada. El backend resuelve a qué clase corresponde. */
  function registrar(datos: {
    alumnoFolio: string
    sucursalId: string
    metodo: 'qr' | 'manual'
  }): Promise<RegistroAsistencia> {
    return api.post('/api/asistencias', datos)
  }

  /** Pasada de lista del día: clases programadas con su estado. */
  function pasadaDeLista(sucursalId: string, fecha: string): Promise<PasadaDeLista> {
    return api.get(`/api/asistencias?sucursal=${encodeURIComponent(sucursalId)}&fecha=${fecha}`)
  }

  /** Pone el estado de un alumno en una clase concreta. */
  function marcar(datos: {
    alumnoFolio: string
    fecha: string
    horaClase: string
    estado: EstadoAsistencia
    sucursalId: string
  }): Promise<{ alumnoFolio: string; fecha: string; horaClase: string; estado: EstadoAsistencia }> {
    return api.put('/api/asistencias/marcar', datos)
  }

  return { registrar, pasadaDeLista, marcar }
}
