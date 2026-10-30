<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { QrcodeStream } from 'vue-qrcode-reader'
import {
  useAsistencia,
  type ClaseDelDia,
  type PasadaDeLista,
  type RegistroAsistencia,
  type EstadoAsistencia,
} from '@/composables/useAsistencia'
import { useAlumnos, type AlumnoResumen } from '@/composables/useAlumnos'
import { useSwal } from '@/composables/useSwal'
import { useSucursalStore } from '@/stores/sucursal'
import { usePermisos } from '@/composables/usePermisos'
import SoloLectura from '@/components/SoloLectura.vue'

const { puedeEditar } = usePermisos('asistencia')

const asistenciaApi = useAsistencia()
const alumnosApi    = useAlumnos()
const sucursalStore = useSucursalStore()
const { error: notifError } = useSwal()

const sucursalId = computed(() => sucursalStore.sucursalActivaId)
const hoy = new Date().toISOString().slice(0, 10)

const tab   = ref<'lista' | 'kiosco'>('lista')
const fecha = ref(hoy)

// ── Pasada de lista ───────────────────────────────────────────────────────────
const dia      = ref<PasadaDeLista | null>(null)
const cargando = ref(false)
const guardando = ref<string | null>(null)

async function cargar() {
  if (!sucursalId.value) return
  cargando.value = true
  try {
    dia.value = await asistenciaApi.pasadaDeLista(sucursalId.value, fecha.value)
  } catch (e: any) {
    dia.value = null
    notifError('No se pudo cargar la lista', e.message)
  } finally {
    cargando.value = false
  }
}

watch([fecha, sucursalId], cargar)

/** Clave única de una clase dentro del día: un alumno puede tener varias. */
const claveDe = (c: ClaseDelDia) => `${c.alumnoFolio}|${c.horaClase}`

async function marcar(c: ClaseDelDia, estado: EstadoAsistencia) {
  if (!puedeEditar.value) return
  guardando.value = claveDe(c)
  try {
    await asistenciaApi.marcar({
      alumnoFolio: c.alumnoFolio,
      fecha:       fecha.value,
      horaClase:   c.horaClase,
      estado,
      sucursalId:  sucursalId.value,
    })
    await cargar()
  } catch (e: any) {
    notifError('No se pudo guardar', e.message)
  } finally {
    guardando.value = null
  }
}

// Agrupadas por hora, que es como el equipo lee el pizarrón
const porHora = computed(() => {
  const mapa = new Map<string, ClaseDelDia[]>()
  for (const c of dia.value?.clases ?? []) {
    if (!mapa.has(c.horaClase)) mapa.set(c.horaClase, [])
    mapa.get(c.horaClase)!.push(c)
  }
  return [...mapa.entries()].sort((a, b) => a[0].localeCompare(b[0]))
})

const filtro = ref('')
const porHoraFiltrado = computed(() => {
  const q = filtro.value.trim().toLowerCase()
  if (!q) return porHora.value
  return porHora.value
    .map(([hora, cs]) => [
      hora,
      cs.filter(c =>
        c.alumnoNombre?.toLowerCase().includes(q) ||
        c.alumnoFolio.toLowerCase().includes(q) ||
        c.instructorNombre?.toLowerCase().includes(q)
      ),
    ] as [string, ClaseDelDia[]])
    .filter(([, cs]) => cs.length)
})

// ── Kiosco: lector de QR tipo teclado ─────────────────────────────────────────
// El lector "teclea" el folio y da Enter. El campo tiene que estar siempre
// enfocado o los escaneos se pierden, así que se le devuelve el foco solo.
const campoLector = ref<HTMLInputElement | null>(null)
const buffer      = ref('')
const procesando  = ref(false)
const ultimo      = ref<RegistroAsistencia | null>(null)
const ultimoError = ref('')
let  reenfocar: number | undefined

/**
 * Devuelve el foco al campo del lector.
 *
 * El lector "teclea" donde esté el foco, así que el campo tiene que
 * recuperarlo solo. Pero si el operador está escribiendo en otro lado —el
 * buscador de alumnos, por ejemplo— quitárselo haría imposible teclear.
 * Por eso solo se recupera cuando nadie más lo está usando.
 */
function enfocar(forzar = false) {
  if (tab.value !== 'kiosco') return

  if (!forzar) {
    const activo = document.activeElement as HTMLElement | null
    const ocupado = activo
      && activo !== campoLector.value
      && (activo.tagName === 'INPUT' || activo.tagName === 'TEXTAREA' || activo.isContentEditable)
    if (ocupado) return
  }

  campoLector.value?.focus()
}

/**
 * Al salir del campo el foco todavía no llegó a su destino, así que se
 * espera un instante antes de decidir si hay que recuperarlo.
 */
function alSalirDelLector() {
  setTimeout(() => enfocar(), 150)
}

function folioDesde(raw: string): string {
  // El QR codifica el folio; si viniera como URL .../qr/043, extrae el folio
  const m = raw.match(/qr\/([^/?#]+)/i)
  return (m ? m[1] : raw).trim()
}

async function registrar(raw: string, metodo: 'qr' | 'manual') {
  const folio = folioDesde(raw)
  if (!folio || procesando.value) return

  procesando.value = true
  ultimoError.value = ''
  try {
    ultimo.value = await asistenciaApi.registrar({
      alumnoFolio: folio,
      sucursalId:  sucursalId.value,
      metodo,
    })
    if (fecha.value === hoy) await cargar()
  } catch (e: any) {
    ultimo.value = null
    ultimoError.value = e.message
  } finally {
    procesando.value = false
    buffer.value = ''
    // Tras registrar, el lector debe quedar listo para el siguiente alumno
    nextTick(() => enfocar(true))
  }
}

function onEnter() {
  const v = buffer.value.trim()
  buffer.value = ''
  if (v) registrar(v, 'qr')
}

watch(tab, t => {
  if (t === 'kiosco') {
    nextTick(() => enfocar(true))
    reenfocar = window.setInterval(() => enfocar(), 1500)
  } else if (reenfocar) {
    clearInterval(reenfocar)
    reenfocar = undefined
  }
})

onBeforeUnmount(() => { if (reenfocar) clearInterval(reenfocar) })

// ── Cámara (alternativa al lector físico) ─────────────────────────────────────
const camara   = ref(false)
const pausado  = ref(false)
const camError = ref('')

async function onDetect(codes: { rawValue: string }[]) {
  if (!codes.length || pausado.value || procesando.value) return
  pausado.value = true
  await registrar(codes[0].rawValue, 'qr')
  setTimeout(() => { pausado.value = false }, 1800)
}

function onCamError(err: any) {
  camError.value =
    err?.name === 'NotAllowedError' ? 'Permiso de cámara denegado. Actívalo en el navegador.'
    : err?.name === 'NotFoundError' ? 'No se encontró cámara en este dispositivo.'
    : 'No se pudo iniciar la cámara: ' + (err?.message ?? err)
}

// ── Alta manual por buscador ──────────────────────────────────────────────────
const alumnos   = ref<AlumnoResumen[]>([])
const manualSel = ref<string | null>(null)

const alumnosOpts = computed(() =>
  alumnos.value.map(a => ({
    value:     a.folio,
    title:     a.nombre,
    folio:     a.folio,
    telefono:  a.contacto.telefono ?? '',
    modalidad: a.modalidad,
    prueba:    a.status === 'prueba',
    // Lo que se compara al escribir. Va en el item y no en el título para
    // que el teléfono sea buscable sin ensuciar lo que se ve en la lista.
    busca: [a.nombre, a.folio, a.contacto.telefono ?? '', a.contacto.tutor ?? '']
      .join(' ').toLowerCase(),
  }))
)

/** Busca por nombre, folio o teléfono, ignorando acentos y signos. */
function normaliza(s: string): string {
  return s.toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[\s\-().]/g, '')
}

function filtrarAlumno(_valor: string, consulta: string, item?: any): boolean {
  const q = normaliza(consulta ?? '')
  if (!q) return true
  return normaliza(item?.raw?.busca ?? '').includes(q)
}

async function registrarManual() {
  if (!manualSel.value) return
  await registrar(manualSel.value, 'manual')
  manualSel.value = null
}

onMounted(async () => {
  await cargar()
  try {
    // Los prealumnos también se registran: vienen a su clase muestra.
    const [activos, prueba] = await Promise.all([
      alumnosApi.listar({ status: 'activo' }),
      alumnosApi.listar({ status: 'prueba' }),
    ])
    alumnos.value = [...activos, ...prueba]
  } catch {}
})

// ── Presentación ──────────────────────────────────────────────────────────────
const COL_MOD: Record<string, string> = {
  PART: '#1e3a8a', GRUPAL: '#9d174d', MATRO: '#9a3412', MINI: '#3730a3',
}

const fmtHoraReg = (h: string | null) =>
  h ? new Date(h.includes('T') ? h : h.replace(' ', 'T'))
        .toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })
    : ''

const fmtFechaLarga = (f: string) =>
  new Date(f + 'T12:00:00').toLocaleDateString('es-MX', {
    weekday: 'long', day: '2-digit', month: 'long',
  })

/** Qué se ve en la fila según el estado. */
function pinta(c: ClaseDelDia) {
  if (c.estado === 'asistio') {
    return { bg: '#ecfdf5', borde: '#a7f3d0', icono: 'mdi-check-circle', color: '#047857', texto: 'Asistió' }
  }
  if (c.estado === 'no_asistio') {
    return c.aviso
      ? { bg: '#fffbeb', borde: '#fde68a', icono: 'mdi-message-alert', color: '#b45309', texto: 'Avisó que no venía' }
      : { bg: '#fef2f2', borde: '#fecaca', icono: 'mdi-close-circle', color: '#b91c1c', texto: 'No asistió' }
  }
  if (c.aviso) {
    return { bg: '#fffbeb', borde: '#fde68a', icono: 'mdi-message-alert', color: '#b45309', texto: 'Avisó que no venía' }
  }
  return { bg: '#ffffff', borde: '#e5e7eb', icono: 'mdi-clock-outline', color: '#9ca3af', texto: 'Sin registrar' }
}
</script>

<template>
  <div class="d-flex flex-column ga-4">
    <SoloLectura modulo="asistencia" detalle="pasar lista" />

    <v-tabs v-model="tab" color="primary" density="compact">
      <v-tab value="lista" prepend-icon="mdi-clipboard-check-outline">Pasar lista</v-tab>
      <v-tab value="kiosco" prepend-icon="mdi-qrcode-scan">Kiosco de entrada</v-tab>
    </v-tabs>

    <!-- ══════════════════ PASAR LISTA ══════════════════ -->
    <template v-if="tab === 'lista'">
      <v-card rounded="xl" border>
        <div class="pa-4" style="border-bottom:1px solid #f1f5f9">
          <v-row dense align="center">
            <v-col cols="12" sm="4">
              <v-text-field
                v-model="fecha"
                label="Fecha"
                type="date"
                :max="hoy"
                density="compact" variant="outlined" rounded="lg" hide-details
              />
            </v-col>
            <v-col cols="12" sm="5">
              <v-text-field
                v-model="filtro"
                placeholder="Buscar alumno, folio o instructor…"
                density="compact" variant="outlined" rounded="lg" hide-details
                prepend-inner-icon="mdi-magnify"
                clearable
              />
            </v-col>
            <v-col cols="12" sm="3" class="text-sm-right">
              <div style="font-size:13px;color:#6b7280;text-transform:capitalize">
                {{ fmtFechaLarga(fecha) }}
              </div>
              <v-chip
                v-if="dia"
                size="x-small" label variant="tonal" class="mt-1"
                :color="dia.abierto ? 'primary' : 'grey'"
              >
                {{ dia.abierto ? 'Día abierto' : 'Día cerrado' }}
              </v-chip>
            </v-col>
          </v-row>
        </div>

        <!-- Resumen -->
        <div v-if="dia && dia.resumen.clases" class="pa-4 d-flex flex-wrap ga-3"
          style="border-bottom:1px solid #f1f5f9;background:#fafbfc">
          <div class="tile">
            <div class="tile-n" style="color:#0a1e6e">{{ dia.resumen.clases }}</div>
            <div class="tile-l">Clases</div>
          </div>
          <div class="tile">
            <div class="tile-n" style="color:#047857">{{ dia.resumen.asistieron }}</div>
            <div class="tile-l">Asistieron</div>
          </div>
          <div class="tile">
            <div class="tile-n" style="color:#b45309">{{ dia.resumen.faltaronAvisando }}</div>
            <div class="tile-l">Avisaron</div>
          </div>
          <div class="tile">
            <div class="tile-n" style="color:#b91c1c">{{ dia.resumen.faltaronSinAvisar }}</div>
            <div class="tile-l">Faltaron</div>
          </div>
          <div v-if="dia.resumen.pendientes" class="tile">
            <div class="tile-n" style="color:#9ca3af">{{ dia.resumen.pendientes }}</div>
            <div class="tile-l">Sin registrar</div>
          </div>
          <div v-if="dia.resumen.porcentajeAsistencia !== null" class="tile">
            <div class="tile-n" style="color:#1a3fe8">{{ dia.resumen.porcentajeAsistencia }}%</div>
            <div class="tile-l">Asistencia</div>
          </div>
        </div>

        <div v-if="cargando" class="text-center py-12">
          <v-progress-circular indeterminate color="primary" size="32" />
        </div>

        <div v-else-if="!dia?.clases.length" class="text-center py-12 text-medium-emphasis" style="font-size:13px">
          No había clases programadas este día.
        </div>

        <div v-else-if="!porHoraFiltrado.length" class="text-center py-12 text-medium-emphasis" style="font-size:13px">
          Ningún alumno coincide con la búsqueda.
        </div>

        <v-card-text v-else class="pa-4">
          <div v-for="[hora, clases] in porHoraFiltrado" :key="hora" class="mb-5">
            <div class="d-flex align-center ga-2 mb-2">
              <v-icon icon="mdi-clock-outline" size="16" color="#9ca3af" />
              <span style="font-weight:800;color:#0a1e6e;font-size:14px;font-variant-numeric:tabular-nums">{{ hora }}</span>
              <span style="font-size:11px;color:#9ca3af">{{ clases.length }} {{ clases.length === 1 ? 'alumno' : 'alumnos' }}</span>
            </div>

            <div
              v-for="c in clases" :key="claveDe(c)"
              class="fila"
              :style="{ background: pinta(c).bg, borderColor: pinta(c).borde }"
            >
              <v-icon :icon="pinta(c).icono" :color="pinta(c).color" size="20" class="flex-shrink-0" />

              <div class="flex-grow-1" style="min-width:0">
                <div class="d-flex align-center ga-2 flex-wrap">
                  <span style="font-weight:700;color:#0a1e6e;font-size:13px">{{ c.alumnoNombre }}</span>
                  <span style="font-size:11px;color:#9ca3af">#{{ c.alumnoFolio }}</span>
                  <span :style="{ color: COL_MOD[c.modalidad] ?? '#374151', fontWeight:700, fontSize:'11px' }">
                    {{ c.modalidad }}
                  </span>
                  <v-chip v-if="c.esReposicion" size="x-small" label variant="flat"
                    style="background:#fed7aa;color:#9a3412;font-weight:700">Reposición</v-chip>
                  <v-chip v-if="c.esMuestra" size="x-small" label variant="flat"
                    style="background:#fbcfe8;color:#831843;font-weight:700">Clase muestra</v-chip>
                </div>
                <div style="font-size:11.5px;color:#6b7280;margin-top:2px">
                  {{ c.instructorNombre }}
                  <template v-if="c.grupoNombre"> · {{ c.grupoNombre }}</template>
                  <template v-if="c.estado === 'asistio' && c.horaRegistro">
                    · llegó {{ fmtHoraReg(c.horaRegistro) }}
                  </template>
                  <template v-if="c.metodo === 'auto'"> · marcado por el sistema</template>
                  <template v-if="c.aviso"> · {{ c.aviso.conDerecho ? 'con derecho a reposición' : 'sin derecho' }}</template>
                </div>
                <div v-if="c.aviso?.motivo" style="font-size:11px;color:#b45309;font-style:italic;margin-top:2px">
                  "{{ c.aviso.motivo }}"
                </div>
              </div>

              <div class="d-flex ga-1 flex-shrink-0">
                <v-btn
                  v-if="puedeEditar"
                  size="small" variant="flat" rounded="lg"
                  :color="c.estado === 'asistio' ? 'success' : undefined"
                  :loading="guardando === claveDe(c)"
                  :style="c.estado === 'asistio' ? undefined : 'background:#fff;border:1px solid #e5e7eb;color:#047857'"
                  @click="marcar(c, 'asistio')"
                >
                  Asistió
                </v-btn>
                <v-btn
                  v-if="puedeEditar"
                  size="small" variant="flat" rounded="lg"
                  :color="c.estado === 'no_asistio' ? 'error' : undefined"
                  :loading="guardando === claveDe(c)"
                  :style="c.estado === 'no_asistio' ? undefined : 'background:#fff;border:1px solid #e5e7eb;color:#b91c1c'"
                  @click="marcar(c, 'no_asistio')"
                >
                  No
                </v-btn>
                <v-chip v-if="!puedeEditar" size="small" label variant="tonal"
                  :style="{ color: pinta(c).color }">{{ pinta(c).texto }}</v-chip>
              </div>
            </div>
          </div>

          <v-alert
            v-if="dia && !dia.abierto"
            type="info" density="compact" variant="tonal" rounded="lg" icon="mdi-lock-check"
            style="font-size:12px"
          >
            Este día ya se cerró: a quien tenía clase y no se registró se le marcó <strong>no asistió</strong>
            automáticamente. Puedes corregir cualquier registro con los botones.
          </v-alert>
        </v-card-text>
      </v-card>
    </template>

    <!-- ══════════════════ KIOSCO ══════════════════ -->
    <template v-else>
      <v-row dense>
        <v-col cols="12" md="7">
          <v-card rounded="xl" border height="100%" @click="enfocar(true)">
            <div style="background:linear-gradient(135deg,#e8f4ff,#dbeafe);padding:16px 20px;border-bottom:1px solid #b8d4ff">
              <div style="color:#1a3fe8;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase">
                Entrada de alumnos
              </div>
              <div style="color:#0a1e6e;font-size:17px;font-weight:700;font-family:'Fraunces',serif">
                Escanea tu código
              </div>
            </div>

            <v-card-text class="pa-6">
              <!-- Campo del lector: siempre enfocado -->
              <div
                class="lector"
                :style="{ borderColor: procesando ? '#1a3fe8' : '#b8d4ff' }"
              >
                <v-icon icon="mdi-qrcode-scan" size="32" :color="procesando ? '#1a3fe8' : '#b8d4ff'" />
                <input
                  ref="campoLector"
                  v-model="buffer"
                  class="lector-input"
                  :placeholder="procesando ? 'Registrando…' : 'Esperando escaneo…'"
                  autocomplete="off"
                  inputmode="none"
                  @keyup.enter="onEnter"
                  @blur="alSalirDelLector"
                />
              </div>
              <div style="font-size:11px;color:#9ca3af;margin-top:6px;text-align:center">
                Deja esta pantalla abierta. El lector escribe aquí solo.
              </div>

              <!-- Resultado del último escaneo -->
              <div v-if="ultimoError" class="resultado" style="background:#fef2f2;border-color:#fecaca">
                <v-icon icon="mdi-alert-circle" color="#b91c1c" size="40" />
                <div>
                  <div style="font-weight:800;color:#b91c1c;font-size:18px">No se registró</div>
                  <div style="font-size:13px;color:#7f1d1d">{{ ultimoError }}</div>
                </div>
              </div>

              <div
                v-else-if="ultimo"
                class="resultado"
                :style="ultimo.yaRegistrado
                  ? 'background:#eff6ff;border-color:#bfdbfe'
                  : 'background:#ecfdf5;border-color:#a7f3d0'"
              >
                <v-icon
                  :icon="ultimo.yaRegistrado ? 'mdi-information' : 'mdi-check-circle'"
                  :color="ultimo.yaRegistrado ? '#1a3fe8' : '#047857'"
                  size="40"
                />
                <div style="min-width:0">
                  <div style="font-weight:800;color:#0a1e6e;font-size:20px;line-height:1.2">
                    {{ ultimo.alumnoNombre }}
                  </div>
                  <div style="font-size:13px;margin-top:4px"
                    :style="{ color: ultimo.yaRegistrado ? '#1e40af' : '#047857' }">
                    {{ ultimo.yaRegistrado ? 'Ya se había registrado' : '¡Listo! Asistencia registrada' }}
                  </div>
                  <div style="font-size:12px;color:#6b7280;margin-top:4px">
                    Clase de {{ ultimo.clase.horaClase }}
                    <template v-if="ultimo.clase.instructorNombre"> · {{ ultimo.clase.instructorNombre }}</template>
                    <template v-if="ultimo.clase.esReposicion"> · reposición</template>
                    <template v-if="ultimo.clase.esMuestra"> · clase muestra</template>
                  </div>
                </div>
              </div>

              <div v-else class="resultado" style="background:#f8fafc;border-color:#e5e7eb">
                <v-icon icon="mdi-account-arrow-right-outline" color="#cbd5e1" size="40" />
                <div style="font-size:14px;color:#9ca3af">Aún no hay escaneos en esta sesión.</div>
              </div>
            </v-card-text>
          </v-card>
        </v-col>

        <v-col cols="12" md="5">
          <v-card rounded="xl" border height="100%">
            <div class="pa-4" style="border-bottom:1px solid #f1f5f9">
              <div class="section-label">Otras formas de registrar</div>
            </div>
            <v-card-text class="pa-4">
              <!-- Buscador manual -->
              <div class="section-label mb-2">Buscar al alumno</div>
              <div class="d-flex ga-2">
                <v-autocomplete
                  v-model="manualSel"
                  :items="alumnosOpts"
                  :custom-filter="filtrarAlumno"
                  placeholder="Nombre, folio o teléfono…"
                  density="compact" variant="outlined" rounded="lg" hide-details
                  prepend-inner-icon="mdi-magnify"
                  menu-icon=""
                  clearable
                  auto-select-first
                  no-data-text="Ningún alumno coincide"
                  @keyup.enter="registrarManual"
                >
                  <template #item="{ props, item }">
                    <v-list-item v-bind="props" :title="undefined">
                      <div class="d-flex align-center ga-2">
                        <span style="font-weight:600;color:#0a1e6e;font-size:13px">{{ item.raw.title }}</span>
                        <span style="font-size:11px;color:#9ca3af">#{{ item.raw.folio }}</span>
                        <span :style="{ color: COL_MOD[item.raw.modalidad] ?? '#374151', fontWeight:700, fontSize:'10.5px' }">
                          {{ item.raw.modalidad }}
                        </span>
                        <v-chip v-if="item.raw.prueba" size="x-small" label variant="flat"
                          style="background:#fbcfe8;color:#831843;font-weight:700">Prueba</v-chip>
                      </div>
                      <div v-if="item.raw.telefono" style="font-size:11px;color:#9ca3af">
                        {{ item.raw.telefono }}
                      </div>
                    </v-list-item>
                  </template>
                </v-autocomplete>
                <v-btn
                  v-if="puedeEditar" color="primary" variant="flat" rounded="lg"
                  :disabled="!manualSel" :loading="procesando" @click="registrarManual"
                >
                  Marcar
                </v-btn>
              </div>

              <v-divider class="my-4" />

              <!-- Cámara, por si no hay lector físico -->
              <div class="section-label mb-2">Cámara del dispositivo</div>
              <div v-if="camara" style="position:relative;border-radius:12px;overflow:hidden;background:#000;aspect-ratio:1">
                <qrcode-stream :paused="pausado" @detect="onDetect" @error="onCamError" />
                <div v-if="pausado"
                  style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.45)">
                  <v-icon icon="mdi-check-circle" color="#2fe6b0" size="48" />
                </div>
              </div>

              <v-alert v-if="camError" type="error" density="compact" variant="tonal" rounded="lg" class="mt-2"
                style="font-size:12px">{{ camError }}</v-alert>

              <v-btn
                v-if="puedeEditar"
                :color="camara ? 'error' : 'primary'"
                variant="outlined" rounded="lg" block class="mt-2"
                :prepend-icon="camara ? 'mdi-camera-off' : 'mdi-camera'"
                @click="camara = !camara; camError = ''"
              >
                {{ camara ? 'Apagar cámara' : 'Usar la cámara' }}
              </v-btn>

              <v-alert type="info" density="compact" variant="tonal" rounded="lg" class="mt-4"
                icon="mdi-information-outline" style="font-size:12px">
                El escaneo cuenta desde <strong>{{ dia?.ventana.minAntes ?? 30 }} minutos antes</strong>
                de su clase y hasta <strong>{{ dia?.ventana.retardoMax ?? 20 }} minutos después de que empieza</strong>.
                Fuera de esa ventana no se registra y la pantalla dice por qué.
                Se ajusta en <strong>Configuración → Horarios</strong>.
              </v-alert>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>
    </template>
  </div>
</template>

<style scoped>
.col-header { color:#9ca3af; font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
.section-label { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:#9ca3af; }

.tile {
  min-width: 88px;
  padding: 8px 14px;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  background: #fff;
}
.tile-n { font-size: 22px; font-weight: 800; font-family: 'Fraunces', serif; line-height: 1.1; font-variant-numeric: tabular-nums; }
.tile-l { font-size: 10.5px; color: #9ca3af; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }

.fila {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  border: 1px solid;
  border-radius: 12px;
  margin-bottom: 6px;
}

.lector {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 18px 20px;
  border: 2px dashed;
  border-radius: 16px;
  background: #f8fbff;
  transition: border-color .2s;
}
.lector-input {
  flex: 1;
  border: none;
  outline: none;
  background: transparent;
  font-size: 20px;
  font-weight: 700;
  color: #0a1e6e;
  letter-spacing: .05em;
}
.lector-input::placeholder { color: #b8d4ff; font-weight: 600; letter-spacing: 0; }

.resultado {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 20px;
  border: 1px solid;
  border-radius: 16px;
  margin-top: 20px;
  min-height: 108px;
}
</style>
