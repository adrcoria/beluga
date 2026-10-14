<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useReposiciones, type Reposicion } from '@/composables/useReposiciones'
import { useInhabiles, type PreviewInhabil } from '@/composables/useInhabiles'
import { useAlumnos, type AlumnoResumen } from '@/composables/useAlumnos'
import { useSwal } from '@/composables/useSwal'
import { useSucursalStore } from '@/stores/sucursal'
import { usePermisos } from '@/composables/usePermisos'
import SoloLectura from '@/components/SoloLectura.vue'

const { puedeEditar } = usePermisos('reposiciones')

const reposicionesApi = useReposiciones()
const inhabilesApi    = useInhabiles()
const alumnosApi      = useAlumnos()
const sucursalStore   = useSucursalStore()
const { exito, error: notifError, confirmar } = useSwal()

const lista        = ref<Reposicion[]>([])
const alumnos      = ref<AlumnoResumen[]>([])
const cargando     = ref(false)
const filtroEstado = ref<'pendiente' | 'agendada' | 'tomada' | 'vencida'>('pendiente')

const sucursalId = computed(() => sucursalStore.sucursalActivaId)

async function cargar() {
  if (!sucursalId.value) return
  cargando.value = true
  try {
    lista.value = await reposicionesApi.listar(sucursalId.value)
  } catch (e: any) {
    notifError('Error al cargar', e.message)
  } finally {
    cargando.value = false
  }
}

async function cargarAlumnos() {
  try { alumnos.value = await alumnosApi.listar({ status: 'activo' }) } catch {}
}

onMounted(() => { cargar(); cargarAlumnos() })
watch(sucursalId, () => { cargar(); cargarAlumnos() })

const filtradas = computed(() => lista.value.filter(r => r.estado === filtroEstado.value))

const conteos = computed(() => ({
  pendiente: lista.value.filter(r => r.estado === 'pendiente').length,
  agendada:  lista.value.filter(r => r.estado === 'agendada').length,
  tomada:    lista.value.filter(r => r.estado === 'tomada').length,
  vencida:   lista.value.filter(r => r.estado === 'vencida').length,
}))

const hoyISO = () => new Date().toISOString().slice(0, 10)

// ── Modal crear comodín ───────────────────────────────────────────────────────
const modalCrear = ref(false)
const guardando  = ref(false)
const form       = ref({ alumnoFolio: '' as string | null, motivo: '', fechaFalta: '' })

const alumnosOpts = computed(() => alumnos.value.map(a => ({ title: `${a.nombre} · #${a.folio}`, value: a.folio })))

function abrirCrear() {
  form.value = { alumnoFolio: null, motivo: '', fechaFalta: hoyISO() }
  modalCrear.value = true
}

async function crear() {
  if (!form.value.alumnoFolio) { notifError('Selecciona un alumno'); return }
  if (!form.value.fechaFalta)  { notifError('Indica qué día faltó'); return }
  guardando.value = true
  try {
    await reposicionesApi.crear({
      alumnoFolio: form.value.alumnoFolio,
      sucursalId:  sucursalId.value,
      motivo:      form.value.motivo || undefined,
      fechaFalta:  form.value.fechaFalta,
    })
    modalCrear.value = false
    exito('Clase a reponer creada', 'Queda disponible para agendar en el Calendario.')
    await cargar()
  } catch (e: any) {
    notifError('No se pudo crear', e.message)
  } finally {
    guardando.value = false
  }
}

async function cancelar(r: Reposicion) {
  const { isConfirmed } = await confirmar(
    `¿Cancelar la reposición de ${r.alumnoNombre}?`,
    'La reposición quedará anulada.',
  )
  if (!isConfirmed) return
  try {
    await reposicionesApi.cancelar(r.id)
    await cargar()
    exito('Cancelada')
  } catch (e: any) {
    notifError('Error', e.message)
  }
}

const fmtFecha = (f?: string | null) =>
  f ? new Date(f + 'T12:00:00').toLocaleDateString('es-MX', { weekday: 'short', day: '2-digit', month: 'short' }) : '—'

// ── Modal declarar día inhábil ────────────────────────────────────────────────
const modalInhabil = ref(false)
const declarando   = ref(false)
const cargandoPrev = ref(false)
const formInhabil  = ref({ fecha: '', motivo: '' })
const preview      = ref<PreviewInhabil | null>(null)

function abrirInhabil() {
  formInhabil.value = { fecha: '', motivo: '' }
  preview.value = null
  modalInhabil.value = true
}

async function cargarPreview() {
  preview.value = null
  if (!formInhabil.value.fecha) return
  cargandoPrev.value = true
  try {
    preview.value = await inhabilesApi.preview({
      fecha:      formInhabil.value.fecha,
      motivo:     formInhabil.value.motivo || undefined,
      sucursalId: sucursalId.value,
    })
  } catch (e: any) {
    notifError('Error en vista previa', e.message)
  } finally {
    cargandoPrev.value = false
  }
}
watch(() => formInhabil.value.fecha, cargarPreview)

async function declararInhabil() {
  if (!formInhabil.value.fecha) { notifError('Selecciona la fecha'); return }
  if (!formInhabil.value.motivo.trim()) { notifError('Indica el motivo'); return }
  declarando.value = true
  try {
    const r = await inhabilesApi.declarar({
      fecha:      formInhabil.value.fecha,
      motivo:     formInhabil.value.motivo.trim(),
      sucursalId: sucursalId.value,
    })
    modalInhabil.value = false
    exito('Día inhábil declarado', `Se generaron ${r.reposicionesGeneradas} clase(s) a reponer para ${r.alumnosAfectados} alumno(s).`)
    filtroEstado.value = 'pendiente'
    await cargar()
  } catch (e: any) {
    notifError('No se pudo declarar', e.message)
  } finally {
    declarando.value = false
  }
}
</script>

<template>
  <div class="d-flex flex-column ga-4">
    <SoloLectura modulo="reposiciones" detalle="crear reposiciones ni declarar días inhábiles" />

    <!-- Toolbar -->
    <v-card rounded="xl" border>
      <v-card-text class="pa-3">
        <div class="d-flex flex-wrap align-center ga-3">
          <div class="d-flex ga-2">
            <v-chip
              v-for="f in [
                { val:'pendiente', label:`Pendientes (${conteos.pendiente})` },
                { val:'agendada',  label:`Agendadas (${conteos.agendada})` },
                { val:'tomada',    label:`Tomadas (${conteos.tomada})` },
                { val:'vencida',   label:`Vencidas (${conteos.vencida})` },
              ]" :key="f.val"
              :color="filtroEstado === f.val ? 'primary' : undefined"
              :variant="filtroEstado === f.val ? 'flat' : 'outlined'"
              size="small" style="cursor:pointer"
              @click="filtroEstado = f.val as any"
            >{{ f.label }}</v-chip>
          </div>
          <v-spacer />
          <v-btn v-if="puedeEditar" variant="outlined" color="orange-darken-2" prepend-icon="mdi-calendar-remove" rounded="lg" @click="abrirInhabil">
            Declarar día inhábil
          </v-btn>
          <v-btn v-if="puedeEditar" color="primary" prepend-icon="mdi-plus" rounded="lg" @click="abrirCrear">
Reponer clase
          </v-btn>
        </div>
      </v-card-text>
    </v-card>

    <!-- Tabla -->
    <v-card rounded="xl" border>
      <v-table density="comfortable" hover>
        <thead>
          <tr>
            <th class="col-header">Alumno</th>
            <th class="col-header">Origen</th>
            <th class="col-header">Motivo</th>
            <th class="col-header">Faltó el</th>
            <th class="col-header">Vence</th>
            <th class="col-header">Agendada para</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="cargando">
            <td colspan="7" class="text-center py-12"><v-progress-circular indeterminate color="primary" size="32" /></td>
          </tr>
          <tr v-else-if="!filtradas.length">
            <td colspan="7" class="text-center py-12 text-medium-emphasis" style="font-size:14px">
              Sin reposiciones {{ filtroEstado }}s
            </td>
          </tr>
          <tr v-for="r in filtradas" :key="r.id">
            <td>
              <span style="font-weight:600;color:#0a1e6e;font-size:13px">{{ r.alumnoNombre }}</span>
              <span style="font-size:11px;color:#9ca3af;margin-left:4px">#{{ r.alumnoFolio }}</span>
            </td>
            <td>
              <v-chip size="x-small" variant="tonal" label :color="r.origen === 'inhabil' ? 'orange' : 'blue-grey'">
                {{ r.origen === 'inhabil' ? 'Día inhábil' : 'Manual' }}
              </v-chip>
            </td>
            <td style="font-size:12px;color:#6b7280">{{ r.motivo || '—' }}</td>
            <td style="font-size:12px;color:#374151">
              <span v-if="r.fechaFalta">{{ fmtFecha(r.fechaFalta) }}</span>
              <span v-else style="color:#d1d5db">—</span>
            </td>
            <td style="font-size:12px;color:#6b7280">{{ fmtFecha(r.vence) }}</td>
            <td style="font-size:12px;color:#374151">
              <template v-if="r.estado === 'agendada' && r.fecha">
                {{ fmtFecha(r.fecha) }} · {{ r.horaInicio }}
                <div style="font-size:11px;color:#9ca3af">{{ r.instructorNombre }}</div>
              </template>
              <span v-else style="color:#d1d5db">—</span>
            </td>
            <td class="text-right">
              <v-btn
                v-if="puedeEditar && (r.estado === 'pendiente' || r.estado === 'agendada')"
                icon="mdi-trash-can-outline" size="x-small" variant="text" color="error"
                @click="cancelar(r)"
              />
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <v-alert type="info" density="compact" variant="tonal" rounded="lg" icon="mdi-information-outline">
      Las reposiciones <strong>pendientes</strong> se agendan desde el <strong>Calendario</strong>: abre una celda libre → pestaña "Reposición" → elige la clase a reponer.
    </v-alert>

    <!-- Modal crear -->
    <v-dialog v-model="modalCrear" max-width="480" persistent>
      <v-card rounded="xl">
        <div class="modal-head">
          <div>
            <div class="modal-eyebrow">Reposiciones</div>
            <div class="modal-title">Reponer clase</div>
          </div>
          <v-btn icon="mdi-close" variant="text" color="#0a1e6e" @click="modalCrear = false" />
        </div>
        <v-card-text class="pa-5">
          <v-autocomplete
            v-model="form.alumnoFolio"
            :items="alumnosOpts"
            label="Alumno *"
            density="compact" variant="outlined" rounded="lg"
            class="mb-3"
          />
          <!-- Sin la fecha de la falta no se puede ubicar en el pizarrón qué
               clase se está reponiendo, que es justo para lo que sirve -->
          <v-text-field
            v-model="form.fechaFalta"
            type="date"
            label="¿Qué día faltó? *"
            :max="hoyISO()"
            density="compact" variant="outlined" rounded="lg"
            class="mb-3"
            :rules="[v => !!v || 'Indica el día de la falta']"
          />
          <v-text-field
            v-model="form.motivo"
            label="Motivo (opcional)"
            placeholder="Día inhábil, clase perdida…"
            density="compact" variant="outlined" rounded="lg" hide-details
          />
        </v-card-text>
        <v-divider />
        <v-card-actions class="pa-4 ga-2 justify-end">
          <v-btn variant="text" @click="modalCrear = false">Cancelar</v-btn>
          <v-btn color="primary" rounded="lg" :loading="guardando" @click="crear">Crear</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Modal declarar día inhábil -->
    <v-dialog v-model="modalInhabil" max-width="560" persistent scrollable>
      <v-card rounded="xl">
        <div class="modal-head" style="background:linear-gradient(135deg,#fff7ed,#fed7aa);border-bottom-color:#fdba74">
          <div>
            <div class="modal-eyebrow" style="color:#c2410c">Reposiciones</div>
            <div class="modal-title" style="color:#9a3412">Declarar día inhábil</div>
          </div>
          <v-btn icon="mdi-close" variant="text" color="#9a3412" @click="modalInhabil = false" />
        </div>

        <v-card-text class="pa-5">
          <v-alert type="warning" density="compact" variant="tonal" rounded="lg" class="mb-4" icon="mdi-information-outline">
            Al declarar el día inhábil se generará <strong>automáticamente una clase a reponer para todos los alumnos</strong> que tengan clase ese día. Luego las agendas desde el Calendario.
          </v-alert>
          <v-row dense>
            <v-col cols="12" sm="5">
              <v-text-field
                v-model="formInhabil.fecha"
                label="Fecha *"
                type="date"
                density="compact" variant="outlined" rounded="lg" hide-details
              />
            </v-col>
            <v-col cols="12" sm="7">
              <v-text-field
                v-model="formInhabil.motivo"
                label="Motivo *"
                placeholder="Día festivo, mantenimiento…"
                density="compact" variant="outlined" rounded="lg" hide-details
              />
            </v-col>
          </v-row>

          <!-- Vista previa de impacto -->
          <div class="mt-4">
            <div v-if="cargandoPrev" class="text-center py-4">
              <v-progress-circular indeterminate color="orange" size="26" />
            </div>
            <template v-else-if="preview">
              <v-alert
                :type="preview.alumnosAfectados ? 'warning' : 'info'"
                density="compact" variant="tonal" rounded="lg"
                :icon="preview.alumnosAfectados ? 'mdi-alert' : 'mdi-information-outline'"
              >
                <strong>{{ preview.alumnosAfectados }}</strong> alumno(s) ·
                <strong>{{ preview.clasesAfectadas }}</strong> clase(s) ese día.
                Se generará una clase a reponer por cada clase perdida.
              </v-alert>

              <div v-if="preview.detalleAlumnos.length"
                style="max-height:240px;overflow-y:auto;border:1px solid #f1f5f9;border-radius:10px"
                class="mt-3">
                <v-table density="compact">
                  <thead>
                    <tr>
                      <th class="col-header">Alumno</th>
                      <th class="col-header">Hora</th>
                      <th class="col-header">Instructor</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(a, i) in preview.detalleAlumnos" :key="i">
                      <td style="font-size:12px;font-weight:600;color:#0a1e6e">{{ a.nombre }}</td>
                      <td style="font-size:12px;color:#6b7280">{{ a.hora }}</td>
                      <td style="font-size:12px;color:#6b7280">{{ a.instructor }}</td>
                    </tr>
                  </tbody>
                </v-table>
              </div>
            </template>
            <div v-else-if="formInhabil.fecha" class="text-center py-3 text-medium-emphasis" style="font-size:13px">
              Sin clases ese día.
            </div>
          </div>
        </v-card-text>

        <v-divider />
        <v-card-actions class="pa-4 ga-2 justify-end">
          <v-btn variant="text" @click="modalInhabil = false">Cancelar</v-btn>
          <v-btn
            color="orange-darken-2" variant="flat" rounded="lg"
            :loading="declarando"
            :disabled="!preview"
            @click="declararInhabil"
          >
Declarar y generar reposiciones
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

  </div>
</template>

<style scoped>
.col-header { color:#9ca3af; font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
.modal-head {
  background: linear-gradient(135deg,#e8f4ff,#dbeafe);
  padding: 18px 22px; border-radius: 16px 16px 0 0; border-bottom: 1px solid #b8d4ff;
  display: flex; align-items: center; justify-content: space-between;
}
.modal-eyebrow { color:#1a3fe8; font-size:11px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; margin-bottom:3px; }
.modal-title { color:#0a1e6e; font-size:18px; font-weight:700; font-family:'Fraunces',serif; }
</style>
