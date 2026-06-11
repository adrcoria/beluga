<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useHorarios, type Pizarron, type CeldaHorario, type InstructorPizarron } from '@/composables/useHorarios'
import { useGrupos, type Grupo } from '@/composables/useGrupos'
import { useReposiciones, type Reposicion } from '@/composables/useReposiciones'
import { useAlumnos, type AlumnoResumen, DIAS_SEMANA } from '@/composables/useAlumnos'
import { useModalidades, type Modalidad } from '@/composables/useModalidades'
import { useSwal } from '@/composables/useSwal'
import { useSucursalStore } from '@/stores/sucursal'
import GruposManager from '@/components/horarios/GruposManager.vue'

const horariosApi    = useHorarios()
const gruposApi      = useGrupos()
const reposicionesApi = useReposiciones()
const alumnosApi     = useAlumnos()
const modApi         = useModalidades()
const sucursalStore  = useSucursalStore()
const { exito, error: notifError, confirmar } = useSwal()

const COL_REPOSICION = { bg: '#FED7AA', text: '#9A3412', borde: '#EA580C' }

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

const sucursalId = computed(() => sucursalStore.sucursalActivaId)
const lunes      = computed(() => pizarron.value?.semana.lunes ?? semanaRef.value)

// ── Carga ─────────────────────────────────────────────────────────────────────
async function cargar() {
  if (!sucursalId.value) return
  cargando.value = true
  try {
    pizarron.value = await horariosApi.pizarron(sucursalId.value, semanaRef.value)
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

const instructores = computed(() => pizarron.value?.instructores ?? [])

// ── Celdas del día activo, indexadas por instructor|hora ──────────────────────
const celdasDia = computed(() => (pizarron.value?.celdas ?? []).filter(c => c.dia === diaActivo.value))

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

// ¿El instructor está disponible (dentro de su rango horario) a esta hora?
function instructorDisponible(inst: InstructorPizarron, hora: string): boolean {
  if (!inst.dispHoraInicio || !inst.dispHoraFin) return true   // sin rango = disponible
  return hora >= inst.dispHoraInicio && hora < inst.dispHoraFin
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

// Estilo de fondo/color de una celda (reposición = naranja con borde)
function celdaEstilo(c: CeldaHorario): Record<string, string> {
  if (c.esReposicion) {
    return { background: COL_REPOSICION.bg, color: COL_REPOSICION.text, boxShadow: `inset 0 0 0 2px ${COL_REPOSICION.borde}` }
  }
  const m = colMod(c.modalidad)
  return { background: m.bg, color: m.text }
}

// ── Modal: Asignar (celda vacía) ──────────────────────────────────────────────
const modalAsignar = ref(false)
const guardando    = ref(false)
const slotSel      = ref<{ instructorId: string; hora: string } | null>(null)
const alumnosOpts  = ref<AlumnoResumen[]>([])

const modoAsignar   = ref<'recurrente' | 'reposicion'>('recurrente')
const reposicionSel = ref<string | null>(null)

const formAsig = ref({
  modalidad:   'PART',
  alumnoFolio: '' as string | null,
  grupoId:     '' as string | null,
  duracionMin: 30,
})

const esGrupal = computed(() => formAsig.value.modalidad === 'GRUPAL')

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
  formAsig.value = {
    modalidad: modalidadesPermitidas.value[0]?.clave ?? 'PART',
    alumnoFolio: null, grupoId: null, duracionMin: 30,
  }
  modalAsignar.value = true
  await cargarAlumnosModalidad()
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

function abrirDetalle(c: CeldaHorario) {
  celdaSel.value = c
  modoMover.value = false
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
  <div class="d-flex flex-column ga-4">

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
    <v-card rounded="xl" border>
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
                  :class="{ 'chip-match': resalte(celdaEn(i.id, hora)!) === 'match', 'chip-dim': resalte(celdaEn(i.id, hora)!) === 'dim' }"
                  :style="celdaEstilo(celdaEn(i.id, hora)!)"
                  @click="abrirDetalle(celdaEn(i.id, hora)!)"
                >
                  <span class="chip-nombre">
                    {{ celdaEn(i.id, hora)!.grupoNombre ?? celdaEn(i.id, hora)!.alumnoNombre }}
                  </span>
                  <span class="chip-meta">
                    <template v-if="celdaEn(i.id, hora)!.esReposicion">⟳ Reposición</template>
                    <template v-else>{{ celdaEn(i.id, hora)!.modalidad }}<template v-if="celdaEn(i.id, hora)!.duracionMin >= 60"> · 60 min</template></template>
                  </span>
                </button>

                <!-- Continuación de clase de 60 min -->
                <div v-else-if="esContinuacion(i.id, hora)" class="celda-cont"></div>

                <!-- Fuera del horario del instructor -->
                <div v-else-if="!instructorDisponible(i, hora)" class="celda-nodisp" title="Fuera del horario del instructor"></div>

                <!-- Celda vacía -->
                <button v-else class="celda-vacia" @click="abrirAsignar(i.id, hora)">
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
            <v-btn value="recurrente" style="flex:1" size="small">Clase recurrente</v-btn>
            <v-btn value="reposicion" style="flex:1" size="small">Reposición</v-btn>
          </v-btn-toggle>

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
          <template v-else>
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
            <div v-if="celdaSel.grupoAlumnos.length" class="mt-3">
              <div class="text-caption text-medium-emphasis mb-1">Alumnos del grupo</div>
              <v-chip v-for="a in celdaSel.grupoAlumnos" :key="a.folio" size="small" class="mr-1 mb-1" variant="tonal">
                {{ a.nombre }}
              </v-chip>
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
          <v-btn v-if="!modoMover" color="error" variant="text" prepend-icon="mdi-trash-can-outline" @click="quitarCelda">
            Quitar
          </v-btn>
          <v-spacer />
          <template v-if="!modoMover">
            <v-btn variant="text" @click="modalDetalle = false">Cerrar</v-btn>
            <v-btn v-if="!celdaSel.esReposicion" color="primary" rounded="lg" prepend-icon="mdi-swap-horizontal" @click="modoMover = true">Mover</v-btn>
          </template>
          <template v-else>
            <v-btn variant="text" @click="modoMover = false">Atrás</v-btn>
            <v-btn color="primary" rounded="lg" :loading="guardando" @click="moverCelda">Guardar cambio</v-btn>
          </template>
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
</style>
