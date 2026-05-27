<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAlumnos, type AlumnoResumen } from '@/composables/useAlumnos'
import { useApi } from '@/composables/useApi'
import { useSwal } from '@/composables/useSwal'

const { confirmar } = useSwal()

const route   = useRoute()
const router  = useRouter()
const alumnos = useAlumnos()
const api     = useApi()

interface SlotHorario {
  slotId: string
  dia: string
  horaInicio: string
  horaFin: string
  modalidad: string
  instructorNombre: string
  instructorIniciales: string
}

const ORDEN_DIAS: Record<string, string> = { L:'Lunes', M:'Martes', X:'Miércoles', J:'Jueves', V:'Viernes', S:'Sábado' }

const folio    = route.params.folio as string
const alumno   = ref<AlumnoResumen | null>(null)
const pagos    = ref<any[]>([])
const horario  = ref<SlotHorario[]>([])
const cargando = ref(true)
const error    = ref('')
const tab      = ref('info')

async function cargar() {
  cargando.value = true; error.value = ''
  try {
    const [a, p, h] = await Promise.all([
      alumnos.obtener(folio),
      api.get<any[]>(`/api/pagos/alumno/${folio}`),
      api.get<SlotHorario[]>(`/api/alumnos/${folio}/horario`),
    ])
    alumno.value  = a
    pagos.value   = p
    horario.value = h
  } catch (e: any) {
    error.value = e.message
  } finally {
    cargando.value = false
  }
}
onMounted(cargar)

const cambiandoStatus = ref(false)
async function cambiarStatus(nuevoStatus: string) {
  if (!alumno.value) return

  if (nuevoStatus === 'baja') {
    const { isConfirmed } = await confirmar(
      '¿Dar de baja a este alumno?',
      `${alumno.value.nombre} quedará inactivo en el sistema.`,
    )
    if (!isConfirmed) return
  }

  cambiandoStatus.value = true
  try {
    alumno.value = await alumnos.actualizar(folio, { status: nuevoStatus })
  } finally {
    cambiandoStatus.value = false
  }
}

const fmt = (n: number) =>
  new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 0 }).format(n)
const fmtFecha = (f?: string | null) =>
  f ? new Date(f + 'T12:00:00').toLocaleDateString('es-MX', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'
const fmtFechaCorta = (f?: string | null) =>
  f ? new Date(f + 'T12:00:00').toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const estadoPago = computed(() => {
  if (!alumno.value) return null
  if (alumno.value.diasMora > 0) return { label: `${alumno.value.diasMora} días de mora`, color: 'error' as const }
  if (alumno.value.tocaPago)     return { label: 'Toca pago esta semana',              color: 'warning' as const }
  return                                { label: 'Al día',                               color: 'success' as const }
})

function iniciales(n: string) {
  return n.split(' ').slice(0, 2).map(x => x[0]).join('').toUpperCase()
}

function colorMod(m: string) {
  return m === 'PART' ? '#dbeafe' : m === 'GRUPAL' ? '#fce7f3' : '#fed7aa'
}
function textMod(m: string) {
  return m === 'PART' ? '#1e3a8a' : m === 'GRUPAL' ? '#9d174d' : '#9a3412'
}
</script>

<template>
  <div class="d-flex flex-column ga-4">

    <!-- Volver -->
    <div>
      <v-btn
        variant="text"
        prepend-icon="mdi-arrow-left"
        color="medium-emphasis"
        size="small"
        @click="router.push('/alumnos')"
      >Volver a alumnos</v-btn>
    </div>

    <!-- Loading / Error -->
    <div v-if="cargando" class="text-center py-16">
      <v-progress-circular indeterminate color="primary" size="40" />
    </div>
    <v-alert v-else-if="error" type="error" rounded="xl" variant="tonal">{{ error }}</v-alert>

    <template v-else-if="alumno">

      <!-- ── Header del alumno ────────────────────────────────────────────── -->
      <v-card rounded="xl" border>
        <v-card-text class="pa-5">
          <div class="d-flex flex-wrap align-center ga-4">
            <!-- Avatar -->
            <v-avatar size="64" color="#0a1e6e">
              <span style="color:#2fe6b0;font-weight:800;font-size:22px">{{ iniciales(alumno.nombre) }}</span>
            </v-avatar>

            <!-- Info principal -->
            <div style="flex:1;min-width:200px">
              <div class="d-flex align-center flex-wrap ga-2 mb-1">
                <span style="font-size:20px;font-weight:700;color:#0a1e6e;font-family:'Fraunces',serif">{{ alumno.nombre }}</span>
                <v-chip size="x-small" color="grey-lighten-3" style="font-family:monospace;color:#9ca3af">#{{ alumno.folio }}</v-chip>
                <v-chip
                  size="x-small"
                  :color="alumno.status === 'activo' ? 'success' : alumno.status === 'prueba' ? 'pink-lighten-3' : 'grey'"
                  variant="tonal"
                  label
                >{{ alumno.status }}</v-chip>
              </div>
              <div style="font-size:13px;color:#6b7280">
                <v-chip
                  size="x-small"
                  :style="{ background: colorMod(alumno.modalidad), color: textMod(alumno.modalidad), fontWeight:'700' }"
                  variant="flat"
                  label
                  class="mr-2"
                >{{ alumno.modalidad }}</v-chip>
                {{ alumno.plan.clasesSemana }} clases/sem
                <span v-if="alumno.descuento" class="ml-2" style="color:#15803d">
                  · {{ alumno.descuento.pct }}% dto. ({{ alumno.descuento.motivo }})
                </span>
              </div>
              <div v-if="alumno.contacto.telefono" style="font-size:12px;color:#9ca3af;margin-top:4px">
                <v-icon icon="mdi-phone-outline" size="14" class="mr-1" />{{ alumno.contacto.telefono }}
              </div>
            </div>

            <!-- Estado pago -->
            <v-chip
              v-if="estadoPago"
              :color="estadoPago.color"
              variant="tonal"
              label
              size="large"
              style="font-weight:700;font-size:13px"
            >{{ estadoPago.label }}</v-chip>

            <!-- Acciones -->
            <div class="d-flex ga-2 flex-wrap">
              <v-btn
                v-if="alumno.status !== 'activo'"
                size="small"
                color="success"
                rounded="lg"
                variant="flat"
                :loading="cambiandoStatus"
                prepend-icon="mdi-account-check"
                @click="cambiarStatus('activo')"
              >Activar</v-btn>
              <v-btn
                v-if="alumno.status !== 'baja'"
                size="small"
                color="grey"
                rounded="lg"
                variant="outlined"
                :loading="cambiandoStatus"
                prepend-icon="mdi-account-off"
                @click="cambiarStatus('baja')"
              >Dar de baja</v-btn>
            </div>
          </div>
        </v-card-text>
      </v-card>

      <!-- ── Tabs ─────────────────────────────────────────────────────────── -->
      <v-tabs v-model="tab" color="primary" bg-color="transparent">
        <v-tab value="info">Información</v-tab>
        <v-tab value="pagos">
          Historial de pagos
          <v-chip v-if="pagos.length" size="x-small" color="primary" class="ml-2">{{ pagos.length }}</v-chip>
        </v-tab>
      </v-tabs>

      <!-- Tab: Información ──────────────────────────────────────────────── -->
      <v-window v-model="tab">
        <v-window-item value="info">
          <v-row dense>
            <!-- Cobranza -->
            <v-col cols="12" md="6">
              <v-card rounded="xl" border height="100%">
                <v-card-title class="section-title pa-4 pb-2">Cobranza</v-card-title>
                <v-card-text class="pa-4 pt-2">
                  <div class="info-row">
                    <span class="info-label">Mensualidad</span>
                    <span style="font-weight:700;color:#0a1e6e;font-size:16px;font-family:'Fraunces',serif">{{ fmt(alumno.montoConDescuento) }}</span>
                  </div>
                  <v-divider class="my-2" />
                  <div class="info-row">
                    <span class="info-label">Inscripción</span>
                    <v-chip
                      size="x-small"
                      :color="alumno.inscripcionPagada ? 'success' : 'error'"
                      variant="tonal"
                      label
                    >{{ alumno.inscripcionPagada ? 'Pagada' : 'Pendiente' }}</v-chip>
                  </div>
                  <v-divider class="my-2" />
                  <div class="info-row">
                    <span class="info-label">Día de pago</span>
                    <span class="info-val">Día {{ alumno.diaPago }} de cada mes</span>
                  </div>
                  <v-divider class="my-2" />
                  <div class="info-row">
                    <span class="info-label">Último pago</span>
                    <span class="info-val">{{ fmtFecha(alumno.ultimoPago) }}</span>
                  </div>
                  <v-divider class="my-2" />
                  <div class="info-row">
                    <span class="info-label">Vencimiento</span>
                    <span :style="{ fontWeight:'600', color: alumno.diasMora > 0 ? '#dc2626' : '#374151' }">
                      {{ fmtFecha(alumno.vencimiento) }}
                    </span>
                  </div>
                </v-card-text>
              </v-card>
            </v-col>

            <!-- Contacto -->
            <v-col cols="12" md="6">
              <v-card rounded="xl" border height="100%">
                <v-card-title class="section-title pa-4 pb-2">Contacto</v-card-title>
                <v-card-text class="pa-4 pt-2">
                  <div class="info-row">
                    <span class="info-label">Tutor</span>
                    <span class="info-val">{{ alumno.contacto.tutor || '—' }}</span>
                  </div>
                  <v-divider class="my-2" />
                  <div class="info-row">
                    <span class="info-label">Teléfono</span>
                    <span class="info-val">{{ alumno.contacto.telefono || '—' }}</span>
                  </div>
                  <v-divider class="my-2" />
                  <div class="info-row">
                    <span class="info-label">Email</span>
                    <span class="info-val">{{ alumno.contacto.email || '—' }}</span>
                  </div>
                </v-card-text>
              </v-card>
            </v-col>

            <!-- Horario asignado -->
            <v-col cols="12" md="6">
              <v-card rounded="xl" border height="100%">
                <v-card-title class="section-title pa-4 pb-2">
                  Horario asignado
                  <v-chip v-if="horario.length" size="x-small" color="primary" class="ml-2">{{ horario.length }}</v-chip>
                </v-card-title>
                <v-card-text class="pa-4 pt-2">
                  <div v-if="!horario.length" style="font-size:13px;color:#9ca3af;text-align:center;padding:12px 0">
                    Sin slots asignados — asignar desde el Calendario
                  </div>
                  <template v-else>
                    <div
                      v-for="(slot, i) in horario"
                      :key="slot.slotId"
                    >
                      <v-divider v-if="i > 0" class="my-2" />
                      <div class="d-flex align-center justify-space-between">
                        <div class="d-flex align-center ga-2">
                          <v-chip
                            size="x-small"
                            variant="flat"
                            label
                            style="font-weight:700;min-width:36px;justify-content:center"
                            :style="{ background: colorMod(slot.modalidad), color: textMod(slot.modalidad) }"
                          >{{ ORDEN_DIAS[slot.dia]?.slice(0,3) ?? slot.dia }}</v-chip>
                          <span style="font-size:13px;color:#111827;font-weight:500">
                            {{ slot.horaInicio }} – {{ slot.horaFin }}
                          </span>
                        </div>
                        <span style="font-size:12px;color:#6b7280">{{ slot.instructorNombre }}</span>
                      </div>
                    </div>
                  </template>
                </v-card-text>
              </v-card>
            </v-col>

            <!-- Otros datos -->
            <v-col cols="12" md="6">
              <v-card rounded="xl" border height="100%">
                <v-card-title class="section-title pa-4 pb-2">Otros datos</v-card-title>
                <v-card-text class="pa-4 pt-2">
                  <div class="info-row">
                    <span class="info-label">Fecha de inicio</span>
                    <span class="info-val">{{ fmtFecha(alumno.fechaInicio) }}</span>
                  </div>
                  <v-divider class="my-2" />
                  <div class="info-row">
                    <span class="info-label">Sucursal</span>
                    <span class="info-val">Beluga Principal</span>
                  </div>
                </v-card-text>
              </v-card>
            </v-col>
          </v-row>
        </v-window-item>

        <!-- Tab: Pagos ────────────────────────────────────────────────────── -->
        <v-window-item value="pagos">
          <v-card rounded="xl" border>
            <v-table density="comfortable">
              <thead>
                <tr>
                  <th class="col-header">Ticket</th>
                  <th class="col-header">Concepto</th>
                  <th class="col-header">Monto</th>
                  <th class="col-header">Método</th>
                  <th class="col-header">Fecha</th>
                  <th class="col-header">Ciclo</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!pagos.length">
                  <td colspan="6" class="text-center py-12 text-medium-emphasis">Sin pagos registrados</td>
                </tr>
                <tr v-for="p in pagos" :key="p.id">
                  <td style="font-family:monospace;font-size:11px;color:#9ca3af">{{ p.ticket }}</td>
                  <td style="font-size:13px;text-transform:capitalize;color:#374151">{{ p.concepto.replace('_', ' ') }}</td>
                  <td style="font-weight:700;color:#0a1e6e;font-size:14px">{{ fmt(p.monto) }}</td>
                  <td style="font-size:13px;text-transform:capitalize;color:#6b7280">{{ p.metodo }}</td>
                  <td style="font-size:12px;color:#6b7280">{{ fmtFechaCorta(p.fecha) }}</td>
                  <td style="font-size:11px;color:#9ca3af">
                    {{ p.cicloDesde ? `${fmtFechaCorta(p.cicloDesde)} → ${fmtFechaCorta(p.cicloHasta)}` : '—' }}
                  </td>
                </tr>
              </tbody>
            </v-table>
          </v-card>
        </v-window-item>
      </v-window>

    </template>
  </div>
</template>

<style scoped>
.section-title {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: #9ca3af;
}
.info-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  min-height: 28px;
}
.info-label {
  font-size: 12px;
  color: #9ca3af;
}
.info-val {
  font-size: 13px;
  color: #111827;
  font-weight: 500;
}
.col-header {
  color: #9ca3af;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
}
</style>
