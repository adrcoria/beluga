<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useHorarios, type Pizarron, type CeldaHorario, type InstructorPizarron } from '@/composables/useHorarios'
import { useGrupos, esModalidadGrupo, type Grupo } from '@/composables/useGrupos'
import { useReposiciones, type Reposicion } from '@/composables/useReposiciones'
import { useAlumnos, type AlumnoResumen, DIAS_SEMANA } from '@/composables/useAlumnos'
import { useModalidades, type Modalidad } from '@/composables/useModalidades'
import { useSwal } from '@/composables/useSwal'
import { useSucursalStore } from '@/stores/sucursal'
import { useApi } from '@/composables/useApi'
import { useAusencias, type Ausencia } from '@/composables/useAusencias'
import { usePermisos } from '@/composables/usePermisos'
import SoloLectura from '@/components/SoloLectura.vue'
import GruposManager from '@/components/horarios/GruposManager.vue'

const horariosApi    = useHorarios()
const gruposApi      = useGrupos()
const reposicionesApi = useReposiciones()
const alumnosApi     = useAlumnos()
const modApi         = useModalidades()
const sucursalStore  = useSucursalStore()
const { exito, error: notifError, confirmar } = useSwal()

const { puedeEditar } = usePermisos('calendario')
const api           = useApi()
const ausenciasApi  = useAusencias()

const COL_REPOSICION = { bg: '#FED7AA', text: '#9A3412', borde: '#EA580C' }
// CLASE PRUEBA, según la leyenda del Excel del cliente (CLAUDE.md)
const COL_MUESTRA    = { bg: '#FBCFE8', text: '#831843', borde: '#DB2777' }

// ── Colores por modalidad (replica el Excel) ───────────────────────────────
const COL_MOD: Record<string, { bg: string; text: string }> = {
  PART:   { bg: '#DBEAFE', text: '#1E3A8A' },
  GRUPAL: { bg: '#FCE7F3', text: '#9D174D' },
  MATRO:  { bg: '#FED7AA', text: '#9A3412' },
  MINI:   { bg: '#E0E7FF', text: '#3730A3' },
}
const colMod = (m: string) => COL_MOD[m] ?? { bg: '#E5E7EB', text: '#374151' }

// ── Estado ──────────────────────────────────────────────────────────────────
const pizarron     = ref<Pizarron | null>(null)
const cargando     = ref(false)
const diaActivo    = ref('L')
const semanaRef    = ref<string>(new Date().toISOString().slice(0, 10))  // cualquier fecha de la semana
const modalidades  = ref<Modalidad[]>([])
const grupos       = ref<Grupo[]>([])
const alumnosTodos = ref<AlumnoResumen[]>([])
const alumnoResaltado = ref<string | null>(null)
const reposicionesPendientes = ref<Reposicion[]>([])

// Pantalla completa + filtro por modalidades
const maximizado = ref(false)
const filtroModalidades = ref<string[]>([])

function toggleModalidad(clave: string) {
  const i = filtroModalidades.value.indexOf(clave)
  if (i >= 0) filtroModalidades.value.splice(i, 1)
  else filtroModalidades.value.push(clave)
}

// Celda atenuada cuando hay filtro de modalidades activo y no coincide
function celdaAtenuadaModalidad(c: CeldaHorario): boolean {
  return filtroModalidades.value.length > 0 && !filtroModalidades.value.includes(c.modalidad)
}

const sucursalId = computed(() => sucursalStore.sucursalActivaId)
const lunes      = computed(() => pizarron.value?.semana.lunes ?? semanaRef.value)

// ── Carga ─────────────────────────────────────────────────────────────────────
async function cargar() {
  if (!sucursalId.value) return
  cargando.value = true
  try {
    pizarron.value = await horariosApi.pizarron(sucursalId.value, semanaRef.value)
    umbralHoras.value = pizarron.value.config.horasAviso ?? 2
  } catch (e: any) {
    notifError('Error al cargar el pizarrón', e.message)
  } finally {
    cargando.value = false
  }
}

async function cargarAux() {
  try { modalidades.value = await modApi.listar() } catch {}
  try { grupos.value = await gruposApi.listar(sucursalId.value) } catch {}
  try { alumnosTodos.value = await alumnosApi.listar({ status: 'activo' }) } catch {}
  await cargarReposicionesPendientes()
}

async function cargarReposicionesPendientes() {
  try { reposicionesPendientes.value = await reposicionesApi.listar(sucursalId.value, 'pendiente') } catch {}
}

// Fecha concreta del día activo en la semana visible (lunes + offset)
const fechaActiva = computed(() => {
  const offset = Math.max(0, DIAS_SEMANA.findIndex(d => d.key === diaActivo.value))
  const base = new Date(lunes.value + 'T12:00:00')
  base.setDate(base.getDate() + offset)
  return base.toISOString().slice(0, 10)
})

onMounted(() => { cargar(); cargarAux() })
watch(sucursalId, () => { cargar(); cargarAux() })

// ── Navegación de semanas ───────────────────────────────────────────────────
function cambiarSemana(deltaSemanas: number) {
  const base = new Date(lunes.value + 'T12:00:00')
  base.setDate(base.getDate() + deltaSemanas * 7)
  semanaRef.value = base.toISOString().slice(0, 10)
  cargar()
}
function irHoy() {
  semanaRef.value = new Date().toISOString().slice(0, 10)
  cargar()
}

const rangoSemana = computed(() => {
  if (!pizarron.value) return ''
  const fmt = (f: string) =>
    new Date(f + 'T12:00:00').toLocaleDateString('es-MX', { day: '2-digit', month: 'short' })
  return `${fmt(pizarron.value.semana.lunes)} – ${fmt(pizarron.value.semana.sabado)}`
})

const esSemanaActual = computed(() => {
  if (!pizarron.value) return false
  const hoy = new Date()
  const l = new Date(pizarron.value.semana.lunes + 'T00:00:00')
  const s = new Date(pizarron.value.semana.sabado + 'T23:59:59')
  return hoy >= l && hoy <= s
})

// ── Cuadrícula de horas ───────────────────────────────────────────────────────
const horas = computed<string[]>(() => {
  const cfg = pizarron.value?.config
  if (!cfg) return []
  const out: string[] = []
  let [h, m] = cfg.horaInicio.split(':').map(Number)
  const [hf, mf] = cfg.horaFin.split(':').map(Number)
  while (h < hf || (h === hf && m < mf)) {
    out.push(`${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`)
    m += 30
    if (m >= 60) { m -= 60; h++ }
  }
  return out
})

// Columnas del pizarrón: solo los instructores que trabajan el día activo.
// Sin horario configurado se muestran siempre (sin restricción).
const instructores = computed(() =>
  (pizarron.value?.instructores ?? []).filter(i => {
    const horario = i.horarioPorDia
    if (!horario || Object.keys(horario).length === 0) return true
    return diaActivo.value in horario
  }))

// ── Celdas del día activo, indexadas por instructor|hora ──────────────────────
// Se filtra por la FECHA concreta del día, no solo por el día de la semana: el
// backend entrega las reglas que tocan cualquier parte de la semana, y una que
// empieza el miércoles o se cierra el martes no debe verse los demás días.
// Sin esto, un alumno dado de baja a media semana seguía ocupando la celda.
const celdasDia = computed(() => {
  const fecha = fechaActiva.value
  return (pizarron.value?.celdas ?? []).filter(c =>
    c.dia === diaActivo.value &&
    c.vigenteDesde <= fecha &&
    (!c.vigenteHasta || c.vigenteHasta >= fecha))
})

const mapaCeldas = computed(() => {
  const m: Record<string, CeldaHorario> = {}
  for (const c of celdasDia.value) m[`${c.instructorId}|${c.horaInicio}`] = c
  return m
})

// Marca filas-continuación para clases de 60 min (no se muestran como vacías)
const continuaciones = computed(() => {
  const set = new Set<string>()
  for (const c of celdasDia.value) {
    if (c.duracionMin >= 60) {
      const [h, mm] = c.horaInicio.split(':').map(Number)
      let nm = mm + 30, nh = h
      if (nm >= 60) { nm -= 60; nh++ }
      set.add(`${c.instructorId}|${String(nh).padStart(2, '0')}:${String(nm).padStart(2, '0')}`)
    }
  }
  return set
})

function celdaEn(instructorId: string, hora: string): CeldaHorario | null {
  return mapaCeldas.value[`${instructorId}|${hora}`] ?? null
}
function esContinuacion(instructorId: string, hora: string): boolean {
  return continuaciones.value.has(`${instructorId}|${hora}`)
}

// ¿El instructor trabaja a esta hora, el día que se está viendo?
// Sin horario configurado no se restringe; un día ausente del mapa es un día
// que no viene, y entonces ninguna hora está disponible.
function instructorDisponible(inst: InstructorPizarron, hora: string): boolean {
  const horario = inst.horarioPorDia
  if (!horario || Object.keys(horario).length === 0) return true

  const rango = horario[diaActivo.value]
  if (!rango) return false

  return hora >= rango.horaInicio && hora < rango.horaFin
}

// ── Resaltar el horario de un alumno ──────────────────────────────────────────
const alumnosResaltarOpts = computed(() =>
  alumnosTodos.value.map(a => ({ title: `${a.nombre} · #${a.folio}`, value: a.folio })))

function celdaEsDe(c: CeldaHorario, folio: string): boolean {
  return c.alumnoFolio === folio || c.grupoAlumnos.some(a => a.folio === folio)
}

// Días (L–S) donde el alumno resaltado tiene clase
const diasAlumno = computed<Set<string>>(() => {
  const set = new Set<string>()
  if (!alumnoResaltado.value) return set
  for (const c of pizarron.value?.celdas ?? []) {
    if (celdaEsDe(c, alumnoResaltado.value)) set.add(c.dia)
  }
  return set
})

// Resumen del alumno resaltado: sus clases ordenadas L→S, hora
const resumenAlumno = computed(() => {
  if (!alumnoResaltado.value) return null
  const orden = DIAS_SEMANA.map(d => d.key)
  const celdas = (pizarron.value?.celdas ?? [])
    .filter(c => celdaEsDe(c, alumnoResaltado.value!))
    .sort((a, b) => (orden.indexOf(a.dia) - orden.indexOf(b.dia)) || a.horaInicio.localeCompare(b.horaInicio))
  const a = alumnosTodos.value.find(x => x.folio === alumnoResaltado.value)
  return { nombre: a?.nombre ?? celdas[0]?.alumnoNombre ?? '', celdas }
})

// Estado de resalte de una celda: 'match' | 'dim' | 'none'
function resalte(c: CeldaHorario): 'match' | 'dim' | 'none' {
  if (!alumnoResaltado.value) return 'none'
  return celdaEsDe(c, alumnoResaltado.value) ? 'match' : 'dim'
}

// Folios de alumnos con adeudo (mensualidad vencida)
const foliosConAdeudo = computed<Set<string>>(() => {
  const set = new Set<string>()
  for (const a of alumnosTodos.value) if (a.diasMora > 0) set.add(a.folio)
  return set
})

// ¿La celda incluye algún alumno con adeudo? (directo o miembro del grupo)
function celdaConAdeudo(c: CeldaHorario): boolean {
  if (c.alumnoFolio && foliosConAdeudo.value.has(c.alumnoFolio)) return true
  return c.grupoAlumnos.some(a => foliosConAdeudo.value.has(a.folio))
}

// ── Faltas con reposición ────────────────────────────────────────────────────
// El alumno faltó ese día y tiene un comodín. Se pinta su clase habitual de esa
// fecha para ubicar de un vistazo qué se está reponiendo. La clave es
// alumno+día, porque la falta es de una fecha concreta de esta semana.
const COL_FALTA = { bg: '#BBF7D0', text: '#14532D' }   // NO ASISTE, según CLAUDE.md

const faltasPorAlumnoDia = computed<Map<string, string>>(() => {
  const mapa = new Map<string, string>()
  for (const f of pizarron.value?.faltas ?? []) {
    mapa.set(`${f.alumnoFolio}|${f.dia}`, f.motivo ?? 'Faltó')
  }
  return mapa
})

// ── Avisos de «no viene» ─────────────────────────────────────────────────────
// A diferencia de las faltas de arriba (que vienen de una reposición), estas son
// el aviso puntual de que el alumno no asistirá a una clase concreta.
const ausenciasPorClase = computed<Map<string, Ausencia>>(() => {
  const mapa = new Map<string, Ausencia>()
  for (const a of pizarron.value?.ausencias ?? []) {
    mapa.set(`${a.alumnoFolio}|${a.fecha}|${a.horaClase}`, a)
  }
  return mapa
})

/** Los alumnos que ocupan esta celda: el individual, o los del grupo. */
function foliosDeCelda(c: CeldaHorario): string[] {
  return c.alumnoFolio ? [c.alumnoFolio] : c.grupoAlumnos.map(a => a.folio)
}

/** El aviso de un alumno concreto en esta celda, si lo hay. */
function ausenciaDeAlumno(c: CeldaHorario, folio: string): Ausencia | null {
  if (c.esReposicion) return null
  return ausenciasPorClase.value.get(`${folio}|${fechaActiva.value}|${c.horaInicio}`) ?? null
}

/** Todos los avisos de la celda. En un grupo pueden faltar varios. */
function ausenciasDeCelda(c: CeldaHorario): Ausencia[] {
  if (c.esReposicion) return []
  return foliosDeCelda(c)
    .map(f => ausenciasPorClase.value.get(`${f}|${fechaActiva.value}|${c.horaInicio}`))
    .filter((a): a is Ausencia => !!a)
}

/**
 * El aviso de la celda cuando se la puede tratar como una sola unidad.
 *
 * En una clase individual es el aviso del alumno. En un grupo solo cuenta si
 * faltan TODOS: si falta uno de seis la clase se da igual, y teñir la celda
 * entera haría creer que no hay clase.
 */
function ausenciaDeCelda(c: CeldaHorario): Ausencia | null {
  const avisos = ausenciasDeCelda(c)
  if (!avisos.length) return null
  return avisos.length === foliosDeCelda(c).length ? avisos[0] : null
}

/** Motivo de la falta si esta celda corresponde a la clase que el alumno perdió. */
function faltaDeCelda(c: CeldaHorario): string | null {
  if (c.esReposicion) return null   // la reposición en sí no es una falta
  const folios  = foliosDeCelda(c)
  const motivos = folios
    .map(f => faltasPorAlumnoDia.value.get(`${f}|${c.dia}`))
    .filter((m): m is string => m !== undefined)

  // En un grupo, solo si la perdieron todos: si no, la clase sí se dio
  if (!motivos.length) return null
  return motivos.length === folios.length ? motivos[0] : null
}

/**
 * Color de la celda. El orden importa: lo excepcional del día gana sobre lo
 * habitual, porque es lo que el coordinador necesita distinguir de un vistazo.
 *
 *   1. Modalidad (PART, GRUPAL…) — el color de siempre
 *   2. Reposición  → naranja con borde
 *   3. Clase muestra → rosa con borde
 *   4. Falta avisada → verde, gana sobre todo lo anterior
 *   5. Adeudo → ya no recolorea: se marca con un icono, que no compite
 */
function celdaEstilo(c: CeldaHorario): Record<string, string> {
  let estilo: Record<string, string>

  if (c.esReposicion) {
    estilo = { background: COL_REPOSICION.bg, color: COL_REPOSICION.text, boxShadow: `inset 0 0 0 2px ${COL_REPOSICION.borde}` }
  } else if (c.esMuestra) {
    estilo = { background: COL_MUESTRA.bg, color: COL_MUESTRA.text, boxShadow: `inset 0 0 0 2px ${COL_MUESTRA.borde}` }
  } else {
    const m = colMod(c.modalidad)
    estilo = { background: m.bg, color: m.text }
  }

  if (ausenciaDeCelda(c) || faltaDeCelda(c)) {
    estilo.background = COL_FALTA.bg
    estilo.color      = COL_FALTA.text
  }

  return estilo
}

// ── Avisar que el alumno no viene ────────────────────────────────────────────
// Si avisa con la anticipación mínima configurada, la falta da derecho a
// reposición y el comodín se genera solo. Si avisa tarde, queda registrada sin
// derecho: sirve para el reporte y por si el papá reclama.
const modalAusencia  = ref(false)
const motivoAusencia = ref('')
const guardandoAus   = ref(false)
const umbralHoras    = ref(2)

/** Horas que faltan para la clase de la celda abierta. */
const horasParaClase = computed(() => {
  if (!celdaSel.value) return 0
  const inicio = new Date(`${fechaActiva.value}T${celdaSel.value.horaInicio}:00`).getTime()
  return Math.max(0, (inicio - Date.now()) / 3600000)
})

const avisoDaDerecho = computed(() => horasParaClase.value >= umbralHoras.value)

// A quién se le va a registrar la falta. En una clase individual es el
// alumno de la celda; en un grupo, el integrante que se eligió.
const alumnoAusencia = ref<{ folio: string; nombre: string } | null>(null)

function abrirAusencia(alumno?: { folio: string; nombre: string }) {
  const c = celdaSel.value
  if (!c) return

  // Se exige el folio y no solo que venga algo: si este método se usa
  // directo en un @click, Vue pasa el evento del clic como argumento y
  // terminaríamos mandando un folio vacío al servidor.
  const elegido = alumno?.folio ? alumno : null

  alumnoAusencia.value = elegido
    ?? (c.alumnoFolio ? { folio: c.alumnoFolio, nombre: c.alumnoNombre ?? c.alumnoFolio } : null)

  if (!alumnoAusencia.value) {
    notifError('No se pudo abrir', 'No se identificó al alumno de esta clase.')
    return
  }
  motivoAusencia.value = ''
  modalAusencia.value  = true
}

async function confirmarAusencia() {
  const quien = alumnoAusencia.value
  if (!quien || !celdaSel.value) return
  guardandoAus.value = true
  try {
    const a = await ausenciasApi.registrar({
      alumnoFolio: quien.folio,
      sucursalId:  sucursalId.value,
      fecha:       fechaActiva.value,
      horaClase:   celdaSel.value.horaInicio,
      horarioId:   celdaSel.value.id,
      motivo:      motivoAusencia.value || null,
    })
    modalAusencia.value = false
    modalDetalle.value  = false
    exito(
      a.conDerecho ? 'Falta registrada con derecho a reposición' : 'Falta registrada',
      a.conDerecho
        ? `Avisó con ${a.horasAviso.toFixed(1)} h. Ya tiene su clase a reponer pendiente de agendar.`
        : `Avisó con ${a.horasAviso.toFixed(1)} h, menos del mínimo de ${a.umbralHoras} h: no genera reposición.`,
      5000,
    )
    await Promise.all([cargar(), cargarReposicionesPendientes()])
  } catch (e: any) {
    notifError('No se pudo registrar', e.message)
  } finally {
    guardandoAus.value = false
  }
}

async function deshacerAusencia(a: Ausencia) {
  const { isConfirmed } = await confirmar(
    '¿Deshacer este aviso?',
    a.conDerecho
      ? 'Se cancelará también la clase a reponer que generó.'
      : 'La falta dejará de aparecer en el reporte.',
  )
  if (!isConfirmed) return
  try {
    await ausenciasApi.anular(a.id)
    modalDetalle.value = false
    exito('Aviso deshecho')
    await Promise.all([cargar(), cargarReposicionesPendientes()])
  } catch (e: any) {
    notifError('No se pudo deshacer', e.message)
  }
}

// ── Modal: Asignar (celda vacía) ──────────────────────────────────────────────
const modalAsignar = ref(false)
const guardando    = ref(false)
const slotSel      = ref<{ instructorId: string; hora: string } | null>(null)
const alumnosOpts  = ref<AlumnoResumen[]>([])

const modoAsignar   = ref<'recurrente' | 'reposicion' | 'muestra'>('recurrente')
const reposicionSel = ref<string | null>(null)

// Prealumnos: alumnos con status 'prueba'. No hay catálogo aparte a propósito
// —el buzón de prospectos es el módulo premium— así que la clase muestra se
// agenda contra un alumno en prueba, sin programa de pago.
const prealumnos = ref<AlumnoResumen[]>([])
const prealumnoSel = ref<string | null>(null)

const prealumnosOpts = computed(() => prealumnos.value.map(a => ({
  title: `${a.nombre} · #${a.folio}`,
  value: a.folio,
})))

async function cargarPrealumnos() {
  try { prealumnos.value = await alumnosApi.listar({ status: 'prueba' }) }
  catch { prealumnos.value = [] }
}

const formAsig = ref({
  modalidad:   'PART',
  alumnoFolio: '' as string | null,
  grupoId:     '' as string | null,
  duracionMin: 30,
})

const esGrupal = computed(() => esModalidadGrupo(formAsig.value.modalidad))

const reposicionesOpts = computed(() => reposicionesPendientes.value.map(r => ({
  title: `${r.alumnoNombre}${r.motivo ? ' · ' + r.motivo : ''}`,
  value: r.id,
})))

// Instructor de la celda seleccionada y las modalidades que atiende
const instructorSel = computed(() =>
  instructores.value.find(i => i.id === slotSel.value?.instructorId) ?? null)

const modalidadesPermitidas = computed(() => {
  const niveles = instructorSel.value?.niveles ?? []
  if (!niveles.length) return modalidades.value          // sin config: no se restringe
  return modalidades.value.filter(m => niveles.includes(m.clave))
})

async function abrirAsignar(instructorId: string, hora: string) {
  slotSel.value = { instructorId, hora }
  modoAsignar.value = 'recurrente'
  reposicionSel.value = null
  prealumnoSel.value = null
  formAsig.value = {
    modalidad: modalidadesPermitidas.value[0]?.clave ?? 'PART',
    alumnoFolio: null, grupoId: null, duracionMin: 30,
  }
  modalAsignar.value = true
  await Promise.all([cargarAlumnosModalidad(), cargarPrealumnos()])
}

async function cargarAlumnosModalidad() {
  if (esGrupal.value) { alumnosOpts.value = []; return }
  try {
    alumnosOpts.value = await alumnosApi.listar({ modalidad: formAsig.value.modalidad, status: 'activo' })
  } catch { alumnosOpts.value = [] }
}
watch(() => formAsig.value.modalidad, cargarAlumnosModalidad)

const gruposOpts = computed(() => grupos.value.map(g => ({
  title: `${g.nombre} (${g.alumnos.length})`, value: g.id,
})))
const alumnosSelectOpts = computed(() => alumnosOpts.value.map(a => ({
  title: `${a.nombre} · #${a.folio}`, value: a.folio,
})))

async function guardarAsignacion() {
  if (!slotSel.value) return

  // ── Modo reposición: colocar un comodín en esta fecha ──────────────────────
  if (modoAsignar.value === 'reposicion') {
    if (!reposicionSel.value) { notifError('Selecciona una clase a reponer'); return }
    guardando.value = true
    try {
      await reposicionesApi.agendar(reposicionSel.value, {
        fecha:        fechaActiva.value,
        instructorId: slotSel.value.instructorId,
        horaInicio:   slotSel.value.hora,
        duracionMin:  formAsig.value.duracionMin,
      })
      modalAsignar.value = false
      exito('Reposición agendada', 'Solo aplica a esta fecha, no se repite.')
      await Promise.all([cargar(), cargarReposicionesPendientes()])
    } catch (e: any) {
      notifError('No se pudo agendar', e.message)
    } finally {
      guardando.value = false
    }
    return
  }

  // ── Modo clase muestra: una sola fecha, para un alumno en prueba ──────────
  if (modoAsignar.value === 'muestra') {
    if (!prealumnoSel.value) { notifError('Selecciona al prealumno'); return }
    guardando.value = true
    try {
      // Vigencia de un solo día: con el filtro por fecha del pizarrón, la celda
      // solo aparece ese día y no se repite como una clase normal.
      await horariosApi.crear({
        sucursalId:   sucursalId.value,
        instructorId: slotSel.value.instructorId,
        dia:          diaActivo.value,
        horaInicio:   slotSel.value.hora,
        duracionMin:  formAsig.value.duracionMin,
        modalidad:    formAsig.value.modalidad,
        alumnoFolio:  prealumnoSel.value,
        vigenteDesde: fechaActiva.value,
        vigenteHasta: fechaActiva.value,
        esMuestra:    true,
      })
      // Queda la fecha en la ficha, para el reporte de seguimiento
      await alumnosApi.actualizar(prealumnoSel.value, { fechaClaseMuestra: fechaActiva.value })
      modalAsignar.value = false
      exito('Clase muestra agendada', 'Solo aplica a esta fecha, no se repite.')
      await cargar()
    } catch (e: any) {
      notifError('No se pudo agendar', e.message)
    } finally {
      guardando.value = false
    }
    return
  }

  // ── Modo recurrente ────────────────────────────────────────────────────────
  if (esGrupal.value && !formAsig.value.grupoId) { notifError('Selecciona un grupo'); return }
  if (!esGrupal.value && !formAsig.value.alumnoFolio) { notifError('Selecciona un alumno'); return }

  guardando.value = true
  try {
    await horariosApi.crear({
      sucursalId:   sucursalId.value,
      instructorId: slotSel.value.instructorId,
      dia:          diaActivo.value,
      horaInicio:   slotSel.value.hora,
      duracionMin:  formAsig.value.duracionMin,
      modalidad:    formAsig.value.modalidad,
      alumnoFolio:  esGrupal.value ? null : formAsig.value.alumnoFolio,
      grupoId:      esGrupal.value ? formAsig.value.grupoId : null,
      vigenteDesde: lunes.value,
    })
    modalAsignar.value = false
    exito('Asignado', 'La clase se repetirá cada semana a partir de esta.')
    await cargar()
  } catch (e: any) {
    notifError('No se pudo asignar', e.message)
  } finally {
    guardando.value = false
  }
}

// ── Modal: Detalle / Mover (celda ocupada) ────────────────────────────────────
const modalDetalle = ref(false)
const celdaSel     = ref<CeldaHorario | null>(null)
const modoMover    = ref(false)
const formMover    = ref({ instructorId: '', dia: 'L', horaInicio: '08:00' })

// ── Días que viene el alumno de la celda ─────────────────────────────────────
// El cliente lo pidió para no tener que irse a la ficha a ver si el niño
// tiene más clases en la semana.
const horarioAlumno = ref<{ dia: string; horaInicio: string; instructorIniciales: string; modalidad: string }[]>([])

async function cargarHorarioAlumno(folio: string | null) {
  horarioAlumno.value = []
  if (!folio) return
  try {
    horarioAlumno.value = await api.get(`/api/alumnos/${folio}/horario`)
  } catch { /* si falla, el detalle se muestra sin esta parte */ }
}

function abrirDetalle(c: CeldaHorario) {
  celdaSel.value = c
  modoMover.value = false
  cargarHorarioAlumno(c.alumnoFolio)
  formMover.value = { instructorId: c.instructorId, dia: c.dia, horaInicio: c.horaInicio }
  modalDetalle.value = true
}

async function moverCelda() {
  if (!celdaSel.value) return
  guardando.value = true
  try {
    await horariosApi.mover(celdaSel.value.id, {
      instructorId: formMover.value.instructorId,
      dia:          formMover.value.dia,
      horaInicio:   formMover.value.horaInicio,
      desdeLunes:   lunes.value,
    })
    modalDetalle.value = false
    exito('Movido', 'El cambio aplica desde esta semana en adelante.')
    await cargar()
  } catch (e: any) {
    notifError('No se pudo mover', e.message)
  } finally {
    guardando.value = false
  }
}

async function quitarCelda() {
  if (!celdaSel.value) return
  const c = celdaSel.value

  // Reposición: se des-agenda (el comodín vuelve a quedar disponible)
  if (c.esReposicion && c.reposicionId) {
    const { isConfirmed } = await confirmar(
      `¿Quitar la reposición de ${c.alumnoNombre}?`,
      'La clase a reponer volverá a quedar disponible para reagendar.',
    )
    if (!isConfirmed) return
    try {
      await reposicionesApi.desagendar(c.reposicionId)
      modalDetalle.value = false
      exito('Reposición quitada', 'La clase a reponer quedó disponible de nuevo.')
      await Promise.all([cargar(), cargarReposicionesPendientes()])
    } catch (e: any) {
      notifError('No se pudo quitar', e.message)
    }
    return
  }

  const quien = c.grupoNombre ?? c.alumnoNombre ?? 'esta clase'
  const { isConfirmed } = await confirmar(
    `¿Quitar a ${quien}?`,
    'Se elimina de esta semana en adelante. Las semanas pasadas se conservan.',
  )
  if (!isConfirmed) return
  try {
    await horariosApi.eliminar(c.id, lunes.value)
    modalDetalle.value = false
    exito('Quitado')
    await cargar()
  } catch (e: any) {
    notifError('No se pudo quitar', e.message)
  }
}

// ── Grupos ────────────────────────────────────────────────────────────────────
const modalGrupos = ref(false)
async function onGruposActualizados() {
  grupos.value = await gruposApi.listar(sucursalId.value)
}

const horasSelectOpts = computed(() => horas.value.map(h => ({ title: h, value: h })))
const instructoresSelectOpts = computed(() =>
  instructores.value.map(i => ({ title: i.nombre, value: i.id })))
const modalidadesSelectOpts = computed(() =>
  modalidadesPermitidas.value.map(m => ({ title: `${m.clave} — ${m.label}`, value: m.clave })))
</script>

<template>
  <div class="d-flex flex-column ga-4" :class="{ 'cal-maximizado': maximizado }">
    <SoloLectura modulo="calendario" detalle="asignar ni mover clases" />

    <!-- Barra de control -->
    <v-card rounded="xl" border>
      <v-card-text class="pa-3">
        <div class="d-flex flex-wrap align-center ga-3">
          <!-- Navegación de semana -->
          <div class="d-flex align-center ga-1">
            <v-btn icon="mdi-chevron-left" variant="text" size="small" @click="cambiarSemana(-1)" />
            <div class="text-center" style="min-width:150px">
              <div style="font-size:13px;font-weight:700;color:#0a1e6e">{{ rangoSemana }}</div>
              <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.08em">
                {{ esSemanaActual ? 'Semana actual' : 'Semana' }}
              </div>
            </div>
            <v-btn icon="mdi-chevron-right" variant="text" size="small" @click="cambiarSemana(1)" />
          </div>
          <v-btn v-if="!esSemanaActual" variant="tonal" size="small" rounded="lg" color="primary" @click="irHoy">
            Hoy
          </v-btn>

          <v-spacer />

          <v-autocomplete
            v-model="alumnoResaltado"
            :items="alumnosResaltarOpts"
            placeholder="Resaltar alumno…"
            prepend-inner-icon="mdi-account-search-outline"
            density="compact" variant="outlined" rounded="lg" hide-details clearable
            style="max-width:280px"
          />

          <v-btn variant="outlined" prepend-icon="mdi-account-group-outline" rounded="lg" @click="modalGrupos = true">
            Grupos
          </v-btn>

          <v-btn
            variant="outlined" rounded="lg"
            :prepend-icon="maximizado ? 'mdi-fullscreen-exit' : 'mdi-fullscreen'"
            :color="maximizado ? 'primary' : undefined"
            @click="maximizado = !maximizado"
          >{{ maximizado ? 'Salir' : 'Pantalla completa' }}</v-btn>
        </div>

        <!-- Filtro por modalidad -->
        <div class="d-flex flex-wrap align-center ga-2 mt-3">
          <span style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9ca3af">Modalidades</span>
          <v-chip
            v-for="m in modalidades" :key="m.clave"
            size="small" label
            :variant="filtroModalidades.includes(m.clave) ? 'flat' : 'outlined'"
            :style="filtroModalidades.includes(m.clave)
              ? { background: colMod(m.clave).bg, color: colMod(m.clave).text, fontWeight: '700' }
              : {}"
            style="cursor:pointer"
            @click="toggleModalidad(m.clave)"
          >{{ m.clave }}</v-chip>
          <v-btn
            v-if="filtroModalidades.length"
            variant="text" size="x-small" color="grey"
            @click="filtroModalidades = []"
          >Ver todas</v-btn>
        </div>
      </v-card-text>
    </v-card>

    <!-- Resumen del alumno resaltado -->
    <v-card v-if="resumenAlumno" rounded="xl" border style="background:#f0fdfa;border-color:#99f6e4">
      <v-card-text class="pa-3">
        <div class="d-flex flex-wrap align-center ga-3">
          <div class="d-flex align-center ga-2">
            <v-icon icon="mdi-map-marker-radius-outline" color="#0e3b33" size="20" />
            <span style="font-weight:700;color:#0a1e6e;font-size:14px">{{ resumenAlumno.nombre }}</span>
          </div>
          <!-- Tira L–M–X–J–V–S -->
          <div class="d-flex ga-1">
            <div v-for="d in DIAS_SEMANA" :key="d.key"
              class="dia-pill"
              :class="{ 'dia-pill--on': diasAlumno.has(d.key) }"
            >{{ d.key }}</div>
          </div>
          <v-divider vertical style="height:24px" />
          <!-- Detalle de clases -->
          <div class="d-flex flex-wrap ga-2">
            <v-chip v-for="c in resumenAlumno.celdas" :key="c.id" size="small" variant="flat"
              :style="{ background: colMod(c.modalidad).bg, color: colMod(c.modalidad).text }"
              @click="diaActivo = c.dia">
              {{ DIAS_SEMANA.find(d => d.key === c.dia)?.label.slice(0,3) }} {{ c.horaInicio }} · {{ c.instructorNombre }}
            </v-chip>
            <span v-if="!resumenAlumno.celdas.length" style="font-size:13px;color:#6b7280">
              Sin clases asignadas esta semana
            </span>
          </div>
        </div>
      </v-card-text>
    </v-card>

    <!-- Tabs por día -->
    <v-tabs v-model="diaActivo" color="#0e3b33" density="compact">
      <v-tab v-for="d in DIAS_SEMANA" :key="d.key" :value="d.key">
        {{ d.label }}
        <span v-if="diasAlumno.has(d.key)" class="tab-dot" />
      </v-tab>
    </v-tabs>

    <!-- Pizarrón -->
    <v-card rounded="xl" border class="pizarron-card">
      <div v-if="cargando" class="text-center py-12">
        <v-progress-circular indeterminate color="primary" size="36" />
      </div>

      <div v-else-if="!instructores.length" class="text-center py-12 text-medium-emphasis">
        No hay instructores en esta sucursal.
      </div>

      <div v-else class="pizarron-scroll">
        <table class="pizarron">
          <thead>
            <tr>
              <th class="col-hora">Hora</th>
              <th v-for="i in instructores" :key="i.id" class="col-instr">
                <div style="font-weight:700;color:#0a1e6e;font-size:13px">{{ i.nombre }}</div>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="hora in horas" :key="hora">
              <td class="col-hora">{{ hora }}</td>
              <td v-for="i in instructores" :key="i.id" class="celda">
                <!-- Celda ocupada -->
                <button
                  v-if="celdaEn(i.id, hora)"
                  class="chip-celda"
                  :class="{
                    'chip-match': resalte(celdaEn(i.id, hora)!) === 'match',
                    'chip-dim': resalte(celdaEn(i.id, hora)!) === 'dim',
                    'chip-oculto': celdaAtenuadaModalidad(celdaEn(i.id, hora)!),
                  }"
                  :style="celdaEstilo(celdaEn(i.id, hora)!)"
                  @click="abrirDetalle(celdaEn(i.id, hora)!)"
                >
                  <span class="chip-nombre">
                    <!-- El adeudo es un icono: no compite con el color del
                         estado de la clase, que es lo que se lee primero -->
                    <v-icon
                      v-if="celdaConAdeudo(celdaEn(i.id, hora)!)"
                      icon="mdi-cash-remove"
                      size="13"
                      class="chip-icono"
                      title="Tiene mensualidad vencida"
                    />
                    {{ celdaEn(i.id, hora)!.grupoNombre ?? celdaEn(i.id, hora)!.alumnoNombre }}
                  </span>
                  <span class="chip-meta">
                    <template v-if="celdaEn(i.id, hora)!.esReposicion">⟳ Reposición</template>
                    <template v-else-if="ausenciaDeCelda(celdaEn(i.id, hora)!)">
                      ✕ No viene<template v-if="ausenciaDeCelda(celdaEn(i.id, hora)!)!.conDerecho"> · repone</template>
                    </template>
                    <template v-else-if="faltaDeCelda(celdaEn(i.id, hora)!)">✕ Faltó</template>
                    <!-- Grupo con algunos ausentes: la clase se da igual, así
                         que no se tiñe la celda; solo se dice cuántos faltan -->
                    <template v-else-if="ausenciasDeCelda(celdaEn(i.id, hora)!).length">
                      ✕ {{ ausenciasDeCelda(celdaEn(i.id, hora)!).length }} no
                      {{ ausenciasDeCelda(celdaEn(i.id, hora)!).length === 1 ? 'viene' : 'vienen' }}
                    </template>
                    <template v-else-if="celdaEn(i.id, hora)!.esMuestra">★ Clase muestra</template>
                    <template v-else>{{ celdaEn(i.id, hora)!.modalidad }}<template v-if="celdaEn(i.id, hora)!.duracionMin >= 60"> · 60 min</template></template>
                  </span>
                </button>

                <!-- Continuación de clase de 60 min -->
                <div v-else-if="esContinuacion(i.id, hora)" class="celda-cont"></div>

                <!-- Fuera del horario del instructor -->
                <div v-else-if="!instructorDisponible(i, hora)" class="celda-nodisp" title="Fuera del horario del instructor"></div>

                <!-- Celda vacía. En modo consulta no invita a hacer clic. -->
                <button
                  v-else
                  class="celda-vacia"
                  :disabled="!puedeEditar"
                  :style="puedeEditar ? '' : 'cursor:default'"
                  @click="puedeEditar && abrirAsignar(i.id, hora)"
                >
                  <v-icon icon="mdi-plus" size="16" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </v-card>

    <!-- ── Modal: Asignar ──────────────────────────────────────────────────── -->
    <v-dialog v-model="modalAsignar" max-width="520" persistent>
      <v-card rounded="xl">
        <div class="modal-head">
          <div>
            <div class="modal-eyebrow">Asignar clase</div>
            <div class="modal-title">
              {{ DIAS_SEMANA.find(d => d.key === diaActivo)?.label }} · {{ slotSel?.hora }}
            </div>
          </div>
          <v-btn icon="mdi-close" variant="text" color="#0a1e6e" @click="modalAsignar = false" />
        </div>

        <v-card-text class="pa-5">
          <!-- Tipo de asignación -->
          <v-btn-toggle v-model="modoAsignar" mandatory density="compact" class="mb-4" style="width:100%" divided>
            <v-btn value="recurrente" style="flex:1" size="small">Recurrente</v-btn>
            <v-btn value="reposicion" style="flex:1" size="small">Reposición</v-btn>
            <v-btn value="muestra"    style="flex:1" size="small">Clase muestra</v-btn>
          </v-btn-toggle>

          <!-- Modo clase muestra -->
          <template v-if="modoAsignar === 'muestra'">
            <v-row dense>
              <v-col cols="12" sm="8">
                <v-autocomplete
                  v-model="prealumnoSel"
                  :items="prealumnosOpts"
                  label="Prealumno *"
                  density="compact" variant="outlined" rounded="lg"
                  no-data-text="No hay alumnos en prueba"
                />
              </v-col>
              <v-col cols="12" sm="4">
                <v-select
                  v-model="formAsig.duracionMin"
                  :items="[{ title: '30 min', value: 30 }, { title: '60 min', value: 60 }]"
                  label="Duración"
                  density="compact" variant="outlined" rounded="lg"
                />
              </v-col>
              <v-col cols="12">
                <v-alert type="info" variant="tonal" density="compact" rounded="lg">
                  <div style="font-size:12.5px;line-height:1.5">
                    La clase muestra ocupa esta celda <strong>solo el {{ fechaActiva }}</strong>,
                    no se repite cada semana.
                    <br>
                    ¿No está en la lista? Date de alta al niño en
                    <strong>Alumnos</strong> con estatus <strong>En prueba</strong>.
                  </div>
                </v-alert>
              </v-col>
            </v-row>
          </template>

          <!-- Modo reposición -->
          <template v-if="modoAsignar === 'reposicion'">
            <v-row dense>
              <v-col cols="12" sm="8">
                <v-autocomplete
                  v-model="reposicionSel"
                  :items="reposicionesOpts"
                  label="Clase a reponer *"
                  density="compact" variant="outlined" rounded="lg"
                  no-data-text="No hay reposiciones pendientes"
                  hide-details
                />
              </v-col>
              <v-col cols="12" sm="4">
                <v-select
                  v-model.number="formAsig.duracionMin"
                  :items="[{ title: '30 min', value: 30 }, { title: '60 min', value: 60 }]"
                  label="Duración"
                  density="compact" variant="outlined" rounded="lg" hide-details
                />
              </v-col>
            </v-row>
            <v-alert type="warning" density="compact" variant="tonal" rounded="lg" class="mt-4" icon="mdi-calendar-star">
              Reposición solo para el <strong>{{ fechaActiva }}</strong>. No se repite en otras semanas.
            </v-alert>
          </template>

          <!-- Modo recurrente -->
          <template v-else-if="modoAsignar === 'recurrente'">
            <v-row dense>
              <v-col cols="12" sm="7">
                <v-select
                  v-model="formAsig.modalidad"
                  :items="modalidadesSelectOpts"
                  label="Modalidad"
                  density="compact" variant="outlined" rounded="lg" hide-details
                />
              </v-col>
              <v-col cols="12" sm="5">
                <v-select
                  v-model.number="formAsig.duracionMin"
                  :items="[{ title: '30 min', value: 30 }, { title: '60 min', value: 60 }]"
                  label="Duración"
                  density="compact" variant="outlined" rounded="lg" hide-details
                />
              </v-col>

              <v-col cols="12" v-if="esGrupal">
                <v-select
                  v-model="formAsig.grupoId"
                  :items="gruposOpts"
                  label="Grupo *"
                  density="compact" variant="outlined" rounded="lg"
                  no-data-text="No hay grupos — créalos en el botón Grupos"
                  hide-details
                />
              </v-col>
              <v-col cols="12" v-else>
                <v-autocomplete
                  v-model="formAsig.alumnoFolio"
                  :items="alumnosSelectOpts"
                  label="Alumno *"
                  density="compact" variant="outlined" rounded="lg"
                  no-data-text="Sin alumnos de esta modalidad"
                  hide-details
                />
              </v-col>
            </v-row>

            <v-alert type="info" density="compact" variant="tonal" rounded="lg" class="mt-4" icon="mdi-calendar-sync-outline">
              La clase se repetirá cada semana desde esta. Para mover a alguien solo a futuro, navega a esa semana y reasígnalo.
            </v-alert>
          </template>
        </v-card-text>

        <v-divider />
        <v-card-actions class="pa-4 ga-2 justify-end">
          <v-btn variant="text" @click="modalAsignar = false">Cancelar</v-btn>
          <v-btn color="primary" rounded="lg" :loading="guardando" @click="guardarAsignacion">Asignar</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- ── Modal: Detalle / Mover ──────────────────────────────────────────── -->
    <v-dialog v-model="modalDetalle" max-width="520" persistent>
      <v-card v-if="celdaSel" rounded="xl">
        <div class="modal-head"
          :style="{ background: celdaSel.esReposicion ? COL_REPOSICION.bg : colMod(celdaSel.modalidad).bg }">
          <div>
            <div class="modal-eyebrow" :style="{ color: celdaSel.esReposicion ? COL_REPOSICION.text : colMod(celdaSel.modalidad).text }">
              {{ celdaSel.esReposicion ? 'Reposición' : celdaSel.modalidad }}
            </div>
            <div class="modal-title" :style="{ color: celdaSel.esReposicion ? COL_REPOSICION.text : colMod(celdaSel.modalidad).text }">
              {{ celdaSel.grupoNombre ?? celdaSel.alumnoNombre }}
            </div>
          </div>
          <v-btn icon="mdi-close" variant="text" :color="celdaSel.esReposicion ? COL_REPOSICION.text : colMod(celdaSel.modalidad).text" @click="modalDetalle = false" />
        </div>

        <v-card-text class="pa-5">
          <!-- Vista detalle -->
          <template v-if="!modoMover">
            <div class="info-row"><span>Instructor</span><strong>{{ celdaSel.instructorNombre }}</strong></div>
            <div class="info-row"><span>Día y hora</span><strong>{{ DIAS_SEMANA.find(d => d.key === celdaSel!.dia)?.label }} · {{ celdaSel.horaInicio }}</strong></div>
            <div class="info-row"><span>Duración</span><strong>{{ celdaSel.duracionMin }} min</strong></div>
            <div v-if="celdaSel.esReposicion" class="info-row"><span>Tipo</span><strong style="color:#9A3412">Reposición (solo {{ celdaSel.fecha }})</strong></div>
            <div v-if="celdaSel.esReposicion && celdaSel.motivo" class="info-row"><span>Motivo</span><strong>{{ celdaSel.motivo }}</strong></div>
            <div v-if="celdaSel.esMuestra" class="info-row">
              <span>Tipo</span><strong style="color:#831843">Clase muestra (solo esta fecha)</strong>
            </div>
            <div v-if="faltaDeCelda(celdaSel)" class="info-row">
              <span>Falta</span>
              <strong style="color:#14532D">No asistió · {{ faltaDeCelda(celdaSel) }}</strong>
            </div>

            <!-- Aviso de que no viene -->
            <template v-if="ausenciaDeCelda(celdaSel)">
              <v-alert
                :type="ausenciaDeCelda(celdaSel)!.conDerecho ? 'success' : 'warning'"
                variant="tonal" density="compact" rounded="lg" class="mt-3"
              >
                <div style="font-size:12.5px;line-height:1.5">
                  <strong>No viene este día.</strong>
                  Avisó con {{ ausenciaDeCelda(celdaSel)!.horasAviso.toFixed(1) }} h de anticipación,
                  <template v-if="ausenciaDeCelda(celdaSel)!.conDerecho">
                    así que <strong>tiene derecho a reposición</strong>.
                  </template>
                  <template v-else>
                    menos del mínimo de {{ ausenciaDeCelda(celdaSel)!.umbralHoras }} h:
                    <strong>sin derecho a reposición</strong>.
                  </template>
                  <template v-if="ausenciaDeCelda(celdaSel)!.motivo">
                    <br>Motivo: {{ ausenciaDeCelda(celdaSel)!.motivo }}
                  </template>
                </div>
                <v-btn
                  v-if="puedeEditar"
                  size="x-small" variant="text" class="mt-1"
                  prepend-icon="mdi-undo"
                  @click="deshacerAusencia(ausenciaDeCelda(celdaSel)!)"
                >Deshacer aviso</v-btn>
              </v-alert>
            </template>

            <!-- Los días que viene el alumno en la semana -->
            <div v-if="horarioAlumno.length" class="mt-3">
              <div class="text-caption text-medium-emphasis mb-1">Días que viene</div>
              <v-chip
                v-for="(h, idx) in horarioAlumno" :key="idx"
                size="small" class="mr-1 mb-1" variant="tonal"
                :color="h.dia === celdaSel.dia && h.horaInicio === celdaSel.horaInicio ? 'primary' : undefined"
              >
                {{ DIAS_SEMANA.find(d => d.key === h.dia)?.label }} · {{ h.horaInicio }}
                <span style="opacity:.6;margin-left:4px">{{ h.instructorIniciales }}</span>
              </v-chip>
            </div>
            <!-- Integrantes del grupo: la falta es de cada quien, no de la clase -->
            <div v-if="celdaSel.grupoAlumnos.length" class="mt-3">
              <div class="text-caption text-medium-emphasis mb-1">
                Alumnos del grupo
                <span v-if="ausenciasDeCelda(celdaSel).length" style="color:#14532D;font-weight:700">
                  · {{ ausenciasDeCelda(celdaSel).length }} no {{ ausenciasDeCelda(celdaSel).length === 1 ? 'viene' : 'vienen' }}
                </span>
              </div>

              <div v-for="a in celdaSel.grupoAlumnos" :key="a.folio" class="integrante">
                <div class="flex-grow-1" style="min-width:0">
                  <span style="font-size:13px;font-weight:600;color:#0a1e6e">{{ a.nombre }}</span>
                  <span style="font-size:11px;color:#9ca3af;margin-left:4px">#{{ a.folio }}</span>

                  <div v-if="ausenciaDeAlumno(celdaSel, a.folio)" style="font-size:11px;margin-top:2px"
                    :style="{ color: ausenciaDeAlumno(celdaSel, a.folio)!.conDerecho ? '#047857' : '#b45309' }">
                    No viene · avisó con {{ ausenciaDeAlumno(celdaSel, a.folio)!.horasAviso.toFixed(1) }} h ·
                    <template v-if="ausenciaDeAlumno(celdaSel, a.folio)!.conDerecho">repone</template>
                    <template v-else>sin derecho</template>
                  </div>
                </div>

                <v-btn
                  v-if="puedeEditar && !ausenciaDeAlumno(celdaSel, a.folio)"
                  size="x-small" variant="text" color="#14532D"
                  prepend-icon="mdi-account-off-outline"
                  @click="abrirAusencia({ folio: a.folio, nombre: a.nombre })"
                >No viene</v-btn>

                <v-btn
                  v-else-if="puedeEditar"
                  size="x-small" variant="text"
                  prepend-icon="mdi-undo"
                  @click="deshacerAusencia(ausenciaDeAlumno(celdaSel, a.folio)!)"
                >Deshacer</v-btn>
              </div>
            </div>
          </template>

          <!-- Vista mover -->
          <template v-else>
            <p class="text-caption text-medium-emphasis mb-3">El cambio aplica desde esta semana en adelante.</p>
            <v-row dense>
              <v-col cols="12">
                <v-select v-model="formMover.instructorId" :items="instructoresSelectOpts" label="Instructor"
                  density="compact" variant="outlined" rounded="lg" hide-details />
              </v-col>
              <v-col cols="6">
                <v-select v-model="formMover.dia" :items="DIAS_SEMANA.map(d => ({ title: d.label, value: d.key }))"
                  label="Día" density="compact" variant="outlined" rounded="lg" hide-details />
              </v-col>
              <v-col cols="6">
                <v-select v-model="formMover.horaInicio" :items="horasSelectOpts" label="Hora"
                  density="compact" variant="outlined" rounded="lg" hide-details />
              </v-col>
            </v-row>
          </template>
        </v-card-text>

        <v-divider />
        <v-card-actions class="pa-4 ga-2">
          <v-btn v-if="!modoMover && puedeEditar" color="error" variant="text" prepend-icon="mdi-trash-can-outline" @click="quitarCelda">
            Quitar
          </v-btn>
          <v-btn
            v-if="!modoMover && puedeEditar && celdaSel.alumnoFolio && !celdaSel.esReposicion && !ausenciaDeCelda(celdaSel)"
            color="warning" variant="text" prepend-icon="mdi-account-clock-outline"
            @click="abrirAusencia()"
          >No viene</v-btn>
          <v-spacer />
          <template v-if="!modoMover">
            <v-btn variant="text" @click="modalDetalle = false">Cerrar</v-btn>
            <v-btn v-if="!celdaSel.esReposicion && puedeEditar" color="primary" rounded="lg" prepend-icon="mdi-swap-horizontal" @click="modoMover = true">Mover</v-btn>
          </template>
          <template v-else>
            <v-btn variant="text" @click="modoMover = false">Atrás</v-btn>
            <v-btn color="primary" rounded="lg" :loading="guardando" @click="moverCelda">Guardar cambio</v-btn>
          </template>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- ── Modal: Avisar que no viene ──────────────────────────────────────── -->
    <v-dialog v-model="modalAusencia" max-width="460">
      <v-card v-if="celdaSel" rounded="xl">
        <v-card-title class="pa-5 pb-2">
          <div style="color:#0a1e6e;font-size:18px;font-weight:700;font-family:'Fraunces',serif">
            El alumno no viene
          </div>
          <div style="font-size:13px;color:#6b7280;font-weight:400">
            {{ alumnoAusencia?.nombre ?? celdaSel.alumnoNombre }} ·
            {{ DIAS_SEMANA.find(d => d.key === celdaSel!.dia)?.label }} {{ celdaSel.horaInicio }}
            <template v-if="celdaSel.grupoNombre"> · {{ celdaSel.grupoNombre }}</template>
          </div>
        </v-card-title>
        <v-divider />

        <v-card-text class="pa-5">
          <!-- Se dice ANTES de registrar si va a dar derecho o no: es lo que
               el papá va a preguntar en ese momento. -->
          <v-alert
            :type="avisoDaDerecho ? 'success' : 'warning'"
            variant="tonal" density="compact" rounded="lg" class="mb-4"
          >
            <div style="font-size:12.5px;line-height:1.5">
              Faltan <strong>{{ horasParaClase.toFixed(1) }} horas</strong> para la clase.
              <template v-if="avisoDaDerecho">
                Avisa con el mínimo de {{ umbralHoras }} h, así que
                <strong>sí tiene derecho a reposición</strong> y se le generará su clase a reponer.
              </template>
              <template v-else>
                Es menos del mínimo de {{ umbralHoras }} h, así que
                <strong>no tiene derecho a reposición</strong>. La falta se registra igual.
              </template>
            </div>
          </v-alert>

          <v-text-field
            v-model="motivoAusencia"
            label="Motivo (opcional)"
            placeholder="Se enfermó, viaje, cita médica…"
            variant="outlined" density="compact" rounded="lg" hide-details
          />
        </v-card-text>

        <v-divider />
        <v-card-actions class="pa-4 ga-2">
          <v-btn variant="text" rounded="lg" @click="modalAusencia = false">Cancelar</v-btn>
          <v-spacer />
          <v-btn
            rounded="lg"
            style="background:#0a1e6e;color:#2fe6b0;font-weight:700;min-width:150px"
            :loading="guardandoAus"
            @click="confirmarAusencia"
          >Registrar falta</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- ── Modal: Grupos ───────────────────────────────────────────────────── -->
    <GruposManager v-model="modalGrupos" @actualizado="onGruposActualizados" />

  </div>
</template>

<style scoped>
.pizarron-scroll {
  overflow-x: auto;
  max-height: calc(100vh - 320px);
  overflow-y: auto;
}
.pizarron {
  border-collapse: collapse;
  width: 100%;
  min-width: 600px;
}
.pizarron th, .pizarron td {
  border: 1px solid #eef2f7;
  padding: 0;
}
.pizarron thead th {
  position: sticky;
  top: 0;
  background: #f8fafc;
  z-index: 2;
  padding: 10px 8px;
  text-align: center;
}
.col-hora {
  position: sticky;
  left: 0;
  background: #f8fafc;
  z-index: 1;
  width: 64px;
  min-width: 64px;
  text-align: center;
  font-size: 12px;
  font-weight: 700;
  color: #6b7280;
  font-variant-numeric: tabular-nums;
  padding: 4px !important;
}
thead .col-hora { z-index: 3; }
.col-instr { min-width: 130px; }
.celda { height: 38px; vertical-align: middle; }
.celda-vacia {
  width: 100%;
  height: 38px;
  border: none;
  background: transparent;
  color: #d1d5db;
  cursor: pointer;
  transition: background .12s;
}
.celda-vacia:hover { background: #eff6ff; color: #1a3fe8; }
.celda-cont { height: 38px; background: repeating-linear-gradient(45deg,#fafafa,#fafafa 6px,#f3f4f6 6px,#f3f4f6 12px); }
.celda-nodisp { height: 38px; background: repeating-linear-gradient(45deg,#f3f4f6,#f3f4f6 5px,#e5e7eb 5px,#e5e7eb 10px); cursor: not-allowed; }

/* Atenuado por filtro de modalidad */
.chip-oculto { opacity: .12; pointer-events: none; }

/* Pantalla completa */
.cal-maximizado {
  position: fixed;
  inset: 0;
  z-index: 2000;
  background: linear-gradient(180deg,#f3fbff,#e8f4ff);
  padding: 16px 20px;
  overflow: hidden;
}
/* El pizarrón ocupa el espacio restante (se ajusta con/ sin tarjeta de resumen) */
.cal-maximizado .pizarron-card {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  flex-direction: column;
}
.cal-maximizado .pizarron-card .pizarron-scroll {
  flex: 1 1 auto;
  min-height: 0;
  max-height: none;
}
/* Compacto para ver más de un vistazo */
.cal-maximizado .celda,
.cal-maximizado .chip-celda,
.cal-maximizado .celda-vacia,
.cal-maximizado .celda-cont,
.cal-maximizado .celda-nodisp { height: 30px; min-height: 30px; }
.cal-maximizado .chip-celda { padding: 1px 6px; }
.cal-maximizado .chip-nombre { font-size: 11px; }
.cal-maximizado .chip-meta { font-size: 8.5px; }
.cal-maximizado .col-instr { min-width: 108px; }
.cal-maximizado .pizarron thead th { padding: 6px 6px; font-size: 12px; }
.chip-celda {
  width: 100%;
  height: 100%;
  min-height: 38px;
  border: none;
  cursor: pointer;
  padding: 3px 8px;
  text-align: left;
  display: flex;
  flex-direction: column;
  justify-content: center;
  line-height: 1.1;
}
.chip-nombre { font-size: 12px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chip-meta { font-size: 9.5px; opacity: .75; text-transform: uppercase; letter-spacing: .04em; }
/* El adeudo va como icono junto al nombre, no como color de fondo: así no
   compite con el estado de la clase (falta, reposición, muestra). */
.chip-icono { color: #b91c1c; margin-right: 2px; vertical-align: -1px; }
.chip-match { box-shadow: inset 0 0 0 2.5px #0e3b33; }
.chip-dim { opacity: .28; }

/* Punto en la pestaña del día */
.tab-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #2fe6b0; display: inline-block; margin-left: 6px;
}

/* Tira L–M–X–J–V–S del resumen */
.dia-pill {
  width: 26px; height: 26px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700;
  background: #e5e7eb; color: #9ca3af;
}
.dia-pill--on { background: #0e3b33; color: #2fe6b0; }

.modal-head {
  background: linear-gradient(135deg,#e8f4ff,#dbeafe);
  padding: 18px 22px;
  border-radius: 16px 16px 0 0;
  border-bottom: 1px solid #b8d4ff;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.modal-eyebrow { color:#1a3fe8; font-size:11px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; margin-bottom:3px; }
.modal-title { color:#0a1e6e; font-size:18px; font-weight:700; font-family:'Fraunces',serif; }
.info-row {
  display: flex; justify-content: space-between; align-items: center;
  font-size: 13px; color: #6b7280; padding: 7px 0; border-bottom: 1px solid #f3f4f6;
}
.info-row strong { color: #0a1e6e; }

.integrante {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  margin-bottom: 4px;
}
</style>
