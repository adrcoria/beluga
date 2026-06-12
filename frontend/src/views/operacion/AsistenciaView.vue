<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { QrcodeStream } from 'vue-qrcode-reader'
import { useAsistencia, type Asistencia, type RegistroAsistencia } from '@/composables/useAsistencia'
import { useAlumnos, type AlumnoResumen } from '@/composables/useAlumnos'
import { useSwal } from '@/composables/useSwal'
import { useSucursalStore } from '@/stores/sucursal'

const asistenciaApi = useAsistencia()
const alumnosApi    = useAlumnos()
const sucursalStore = useSucursalStore()
const { error: notifError } = useSwal()

const sucursalId = computed(() => sucursalStore.sucursalActivaId)
const hoy = new Date().toISOString().slice(0, 10)

// ── Escáner ───────────────────────────────────────────────────────────────────
const escaneando = ref(false)
const pausado    = ref(false)
const camError   = ref('')
const ultimo     = ref<RegistroAsistencia | null>(null)
const procesando = ref(false)

function folioDesde(raw: string): string {
  // El QR codifica el folio; si viniera como URL .../qr/043, extrae el folio
  const m = raw.match(/qr\/([^/?#]+)/i)
  return (m ? m[1] : raw).trim()
}

async function onDetect(codes: { rawValue: string }[]) {
  if (!codes.length || pausado.value || procesando.value) return
  const folio = folioDesde(codes[0].rawValue)
  if (folio) await marcar(folio, 'qr')
}

function onCamError(err: any) {
  camError.value =
    err?.name === 'NotAllowedError' ? 'Permiso de cámara denegado. Actívalo en el navegador.'
    : err?.name === 'NotFoundError' ? 'No se encontró cámara en este dispositivo.'
    : 'No se pudo iniciar la cámara: ' + (err?.message ?? err)
}

// ── Registrar asistencia ──────────────────────────────────────────────────────
async function marcar(folio: string, metodo: 'qr' | 'manual') {
  procesando.value = true
  pausado.value = true
  try {
    const r = await asistenciaApi.registrar({ alumnoFolio: folio, sucursalId: sucursalId.value, metodo })
    ultimo.value = r
    await cargarLista()
  } catch (e: any) {
    ultimo.value = null
    notifError('No se pudo registrar', e.message)
  } finally {
    procesando.value = false
    // Reanudar el escaneo tras un momento (evita doble lectura)
    if (metodo === 'qr') setTimeout(() => { pausado.value = false }, 1800)
    else pausado.value = false
  }
}

// ── Búsqueda manual ───────────────────────────────────────────────────────────
const alumnos     = ref<AlumnoResumen[]>([])
const manualSel   = ref<string | null>(null)
const alumnosOpts = computed(() => alumnos.value.map(a => ({ title: `${a.nombre} · #${a.folio}`, value: a.folio })))

async function marcarManual() {
  if (!manualSel.value) return
  await marcar(manualSel.value, 'manual')
  manualSel.value = null
}

// ── Lista del día ─────────────────────────────────────────────────────────────
const lista    = ref<Asistencia[]>([])
const cargando = ref(false)

async function cargarLista() {
  cargando.value = true
  try { lista.value = await asistenciaApi.listar(sucursalId.value, hoy) }
  catch {}
  finally { cargando.value = false }
}

onMounted(async () => {
  await cargarLista()
  try { alumnos.value = await alumnosApi.listar({ status: 'activo' }) } catch {}
})

const fmtHora = (h: string) =>
  new Date(h.includes('T') ? h : h.replace(' ', 'T')).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })

const COL_MOD: Record<string, string> = { PART: '#1e3a8a', GRUPAL: '#9d174d', MATRO: '#9a3412', MINI: '#3730a3' }
</script>

<template>
  <div class="d-flex flex-column ga-4">
    <v-row dense>
      <!-- Escáner -->
      <v-col cols="12" md="5">
        <v-card rounded="xl" border height="100%">
          <div style="background:linear-gradient(135deg,#e8f4ff,#dbeafe);padding:16px 20px;border-bottom:1px solid #b8d4ff">
            <div style="color:#1a3fe8;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase">Registrar asistencia</div>
            <div style="color:#0a1e6e;font-size:17px;font-weight:700;font-family:'Fraunces',serif">Escanear QR</div>
          </div>

          <v-card-text class="pa-4">
            <!-- Cámara -->
            <div v-if="escaneando" style="position:relative;border-radius:12px;overflow:hidden;background:#000;aspect-ratio:1">
              <qrcode-stream :paused="pausado" @detect="onDetect" @error="onCamError" />
              <div v-if="pausado" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.45)">
                <v-icon icon="mdi-check-circle" color="#2fe6b0" size="48" />
              </div>
            </div>
            <div v-else class="d-flex flex-column align-center justify-center"
              style="aspect-ratio:1;border:2px dashed #b8d4ff;border-radius:12px;background:#f8fafc">
              <v-icon icon="mdi-qrcode-scan" size="56" color="#b8d4ff" />
              <div style="font-size:13px;color:#6b7280;margin-top:8px">Cámara apagada</div>
            </div>

            <v-alert v-if="camError" type="error" density="compact" variant="tonal" rounded="lg" class="mt-3">{{ camError }}</v-alert>

            <v-btn
              :color="escaneando ? 'error' : 'primary'"
              variant="flat" rounded="lg" block class="mt-3"
              :prepend-icon="escaneando ? 'mdi-camera-off' : 'mdi-camera'"
              @click="escaneando = !escaneando; camError = ''"
            >
              {{ escaneando ? 'Apagar cámara' : 'Encender cámara' }}
            </v-btn>

            <!-- Último registro -->
            <div v-if="ultimo" class="mt-3 pa-3"
              :style="{ borderRadius:'12px', background: ultimo.yaRegistrado ? '#fff7ed' : '#ecfdf5', border: '1px solid ' + (ultimo.yaRegistrado ? '#fed7aa' : '#a7f3d0') }">
              <div class="d-flex align-center ga-2">
                <v-icon :icon="ultimo.yaRegistrado ? 'mdi-information' : 'mdi-check-circle'" :color="ultimo.yaRegistrado ? 'warning' : 'success'" />
                <div>
                  <div style="font-weight:700;color:#0a1e6e">{{ ultimo.alumnoNombre }}</div>
                  <div style="font-size:12px;color:#6b7280">
                    {{ ultimo.yaRegistrado ? 'Ya estaba registrado hoy' : 'Asistencia registrada' }}
                  </div>
                </div>
              </div>
            </div>

            <!-- Manual -->
            <v-divider class="my-4" />
            <div class="section-label mb-2">Registro manual</div>
            <div class="d-flex ga-2">
              <v-autocomplete
                v-model="manualSel"
                :items="alumnosOpts"
                placeholder="Buscar alumno…"
                density="compact" variant="outlined" rounded="lg" hide-details
              />
              <v-btn color="primary" variant="flat" rounded="lg" :disabled="!manualSel" :loading="procesando" @click="marcarManual">
                Marcar
              </v-btn>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- Lista del día -->
      <v-col cols="12" md="7">
        <v-card rounded="xl" border height="100%">
          <div class="d-flex align-center justify-space-between pa-4" style="border-bottom:1px solid #f1f5f9">
            <div>
              <div class="section-label">Asistencias de hoy</div>
              <div style="font-size:13px;color:#6b7280;text-transform:capitalize">{{ new Date().toLocaleDateString('es-MX', { weekday:'long', day:'2-digit', month:'long' }) }}</div>
            </div>
            <v-chip color="primary" variant="tonal" label size="large" style="font-weight:700">{{ lista.length }}</v-chip>
          </div>

          <div v-if="cargando" class="text-center py-10"><v-progress-circular indeterminate color="primary" size="30" /></div>
          <div v-else-if="!lista.length" class="text-center py-10 text-medium-emphasis" style="font-size:13px">
            Aún no hay asistencias registradas hoy.
          </div>
          <v-table v-else density="comfortable">
            <thead>
              <tr>
                <th class="col-header">Alumno</th>
                <th class="col-header">Modalidad</th>
                <th class="col-header">Hora</th>
                <th class="col-header">Método</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in lista" :key="a.id">
                <td>
                  <span style="font-weight:600;color:#0a1e6e;font-size:13px">{{ a.alumnoNombre }}</span>
                  <span style="font-size:11px;color:#9ca3af;margin-left:4px">#{{ a.alumnoFolio }}</span>
                </td>
                <td><span :style="{ color: COL_MOD[a.modalidad] ?? '#374151', fontWeight:700, fontSize:'12px' }">{{ a.modalidad }}</span></td>
                <td style="font-size:13px;color:#374151">{{ fmtHora(a.hora) }}</td>
                <td>
                  <v-chip size="x-small" variant="tonal" :color="a.metodo === 'qr' ? 'primary' : 'grey'" label>
                    {{ a.metodo === 'qr' ? 'QR' : 'Manual' }}
                  </v-chip>
                </td>
              </tr>
            </tbody>
          </v-table>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<style scoped>
.col-header { color:#9ca3af; font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
.section-label { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:#9ca3af; }
</style>
