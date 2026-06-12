<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import * as XLSX from 'xlsx'
import { useReportes, type Ingresos, type Cartera, type AsistenciaReporte, type Ocupacion } from '@/composables/useReportes'
import { useSucursalStore } from '@/stores/sucursal'
import { useSwal } from '@/composables/useSwal'

const api = useReportes()
const sucursalStore = useSucursalStore()
const { error: notifError } = useSwal()

const sucursalId = computed(() => sucursalStore.sucursalActivaId)
const tab = ref('ingresos')

const desde = ref(new Date().toISOString().slice(0, 8) + '01')
const hasta = ref(new Date().toISOString().slice(0, 10))

const cargando = ref(false)
const ingresos    = ref<Ingresos | null>(null)
const cartera     = ref<Cartera | null>(null)
const asistencia  = ref<AsistenciaReporte | null>(null)
const ocupacion   = ref<Ocupacion | null>(null)

const fmt = (n: number) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 0 }).format(n)
const fmtFecha = (f?: string | null) => f ? new Date(f + 'T12:00:00').toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1)

async function cargar() {
  if (!sucursalId.value) return
  cargando.value = true
  try {
    if (tab.value === 'ingresos')   ingresos.value   = await api.ingresos(sucursalId.value, desde.value, hasta.value)
    if (tab.value === 'cartera')    cartera.value    = await api.cartera(sucursalId.value)
    if (tab.value === 'asistencia') asistencia.value = await api.asistencia(sucursalId.value, desde.value, hasta.value)
    if (tab.value === 'ocupacion')  ocupacion.value  = await api.ocupacion(sucursalId.value)
  } catch (e: any) {
    notifError('Error al cargar el reporte', e.message)
  } finally {
    cargando.value = false
  }
}

watch(tab, cargar)
watch(sucursalId, cargar)
onMounted(cargar)

// Exportar un arreglo de objetos a Excel
function exportar(filas: any[], nombre: string) {
  if (!filas.length) { notifError('Nada que exportar'); return }
  const ws = XLSX.utils.json_to_sheet(filas)
  const wb = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(wb, ws, 'Reporte')
  XLSX.writeFile(wb, `${nombre}_${new Date().toISOString().slice(0, 10)}.xlsx`)
}

function exportarIngresos() {
  exportar((ingresos.value?.detalle ?? []).map(d => ({
    Fecha: d.fecha, Recibo: d.ticket, Alumno: d.alumno, Folio: d.folio,
    Concepto: d.concepto, Metodo: d.metodo, Monto: d.monto,
  })), 'ingresos')
}
function exportarCartera() {
  exportar((cartera.value?.cartera ?? []).map(c => ({
    Folio: c.folio, Alumno: c.nombre, Telefono: c.telefono,
    Pendiente: c.pendiente, 'Días mora': c.diasMora, Cobros: c.cobros,
  })), 'cartera_vencida')
}
function exportarAusentes() {
  exportar((asistencia.value?.ausentes ?? []).map(a => ({
    Folio: a.folio, Alumno: a.nombre, 'Última asistencia': a.ultima ?? 'Nunca',
  })), 'ausentismo')
}
function exportarOcupacion() {
  exportar((ocupacion.value?.instructores ?? []).map(i => ({
    Instructor: i.nombre, Clases: i.clases, Alumnos: i.alumnos,
  })), 'ocupacion_instructores')
}

const maxDia = computed(() => Math.max(1, ...(asistencia.value?.porDia.map(d => d.total) ?? [1])))
</script>

<template>
  <div class="d-flex flex-column ga-4">
    <v-tabs v-model="tab" color="primary" bg-color="transparent">
      <v-tab value="ingresos">Ingresos</v-tab>
      <v-tab value="cartera">Cartera vencida</v-tab>
      <v-tab value="asistencia">Asistencia</v-tab>
      <v-tab value="ocupacion">Ocupación</v-tab>
    </v-tabs>

    <!-- Filtro de fechas (ingresos y asistencia) -->
    <v-card v-if="tab === 'ingresos' || tab === 'asistencia'" rounded="xl" border>
      <v-card-text class="pa-3 d-flex flex-wrap align-center ga-3">
        <v-text-field v-model="desde" label="Desde" type="date" density="compact" variant="outlined" rounded="lg" hide-details style="max-width:180px" />
        <v-text-field v-model="hasta" label="Hasta" type="date" density="compact" variant="outlined" rounded="lg" hide-details style="max-width:180px" />
        <v-btn color="primary" variant="flat" rounded="lg" :loading="cargando" @click="cargar">Aplicar</v-btn>
      </v-card-text>
    </v-card>

    <div v-if="cargando" class="text-center py-12"><v-progress-circular indeterminate color="primary" size="36" /></div>

    <!-- ── INGRESOS ──────────────────────────────────────────────────────── -->
    <template v-else-if="tab === 'ingresos' && ingresos">
      <v-row dense>
        <v-col cols="12" sm="4">
          <v-card rounded="xl" border><v-card-text class="pa-4">
            <div class="lbl">Total cobrado</div>
            <div class="num" style="color:#15803d">{{ fmt(ingresos.total) }}</div>
            <div class="sub">{{ ingresos.totalCount }} pagos</div>
          </v-card-text></v-card>
        </v-col>
        <v-col cols="12" sm="8">
          <v-card rounded="xl" border height="100%"><v-card-text class="pa-4">
            <div class="lbl mb-2">Por método</div>
            <div class="d-flex flex-wrap ga-2">
              <v-chip v-for="m in ingresos.porMetodo" :key="m.metodo" variant="tonal" color="primary" label>
                {{ cap(m.metodo) }}: <strong class="ml-1">{{ fmt(m.total) }}</strong>
              </v-chip>
              <v-chip v-for="c in ingresos.porConcepto" :key="c.concepto" variant="tonal" color="teal" label>
                {{ cap(c.concepto) }}: <strong class="ml-1">{{ fmt(c.total) }}</strong>
              </v-chip>
            </div>
          </v-card-text></v-card>
        </v-col>
      </v-row>

      <v-card rounded="xl" border>
        <div class="d-flex align-center justify-space-between pa-3" style="border-bottom:1px solid #f1f5f9">
          <span class="lbl">Detalle de pagos</span>
          <v-btn size="small" variant="outlined" rounded="lg" prepend-icon="mdi-microsoft-excel" @click="exportarIngresos">Excel</v-btn>
        </div>
        <v-table density="compact">
          <thead><tr><th class="ch">Fecha</th><th class="ch">Recibo</th><th class="ch">Alumno</th><th class="ch">Concepto</th><th class="ch">Método</th><th class="ch text-right">Monto</th></tr></thead>
          <tbody>
            <tr v-if="!ingresos.detalle.length"><td colspan="6" class="text-center py-8 text-medium-emphasis">Sin pagos en el periodo</td></tr>
            <tr v-for="(d, i) in ingresos.detalle" :key="i">
              <td style="font-size:12px;color:#6b7280">{{ fmtFecha(d.fecha) }}</td>
              <td style="font-family:monospace;font-size:12px;color:#1a3fe8">{{ d.ticket ?? '—' }}</td>
              <td style="font-size:13px;color:#0a1e6e;font-weight:600">{{ d.alumno }} <span style="color:#9ca3af;font-weight:400">#{{ d.folio }}</span></td>
              <td style="font-size:12px;text-transform:capitalize;color:#6b7280">{{ d.concepto }}</td>
              <td style="font-size:12px;text-transform:capitalize;color:#6b7280">{{ d.metodo }}</td>
              <td class="text-right" style="font-weight:700;color:#0a1e6e">{{ fmt(d.monto) }}</td>
            </tr>
          </tbody>
        </v-table>
      </v-card>
    </template>

    <!-- ── CARTERA ───────────────────────────────────────────────────────── -->
    <template v-else-if="tab === 'cartera' && cartera">
      <v-row dense>
        <v-col cols="6" sm="4">
          <v-card rounded="xl" border><v-card-text class="pa-4">
            <div class="lbl">Por cobrar</div>
            <div class="num" style="color:#dc2626">{{ fmt(cartera.total) }}</div>
          </v-card-text></v-card>
        </v-col>
        <v-col cols="6" sm="4">
          <v-card rounded="xl" border><v-card-text class="pa-4">
            <div class="lbl">Alumnos con adeudo</div>
            <div class="num" style="color:#0a1e6e">{{ cartera.alumnos }}</div>
          </v-card-text></v-card>
        </v-col>
      </v-row>
      <v-card rounded="xl" border>
        <div class="d-flex align-center justify-space-between pa-3" style="border-bottom:1px solid #f1f5f9">
          <span class="lbl">Cartera vencida</span>
          <v-btn size="small" variant="outlined" rounded="lg" prepend-icon="mdi-microsoft-excel" @click="exportarCartera">Excel</v-btn>
        </div>
        <v-table density="compact">
          <thead><tr><th class="ch">Alumno</th><th class="ch">Teléfono</th><th class="ch text-right">Pendiente</th><th class="ch text-right">Días mora</th></tr></thead>
          <tbody>
            <tr v-if="!cartera.cartera.length"><td colspan="4" class="text-center py-8 text-medium-emphasis">Sin adeudos 🎉</td></tr>
            <tr v-for="c in cartera.cartera" :key="c.folio">
              <td style="font-size:13px;color:#0a1e6e;font-weight:600">{{ c.nombre }} <span style="color:#9ca3af;font-weight:400">#{{ c.folio }}</span></td>
              <td style="font-size:12px;color:#6b7280">{{ c.telefono || '—' }}</td>
              <td class="text-right" style="font-weight:700;color:#dc2626">{{ fmt(c.pendiente) }}</td>
              <td class="text-right">
                <v-chip size="x-small" :color="c.diasMora > 30 ? 'error' : c.diasMora > 0 ? 'warning' : 'grey'" variant="tonal" label>{{ c.diasMora }}d</v-chip>
              </td>
            </tr>
          </tbody>
        </v-table>
      </v-card>
    </template>

    <!-- ── ASISTENCIA ────────────────────────────────────────────────────── -->
    <template v-else-if="tab === 'asistencia' && asistencia">
      <v-card rounded="xl" border>
        <v-card-text class="pa-4">
          <div class="lbl mb-1">Asistencias en el periodo</div>
          <div class="num" style="color:#0a1e6e">{{ asistencia.total }}</div>
          <!-- mini gráfica de barras -->
          <div class="d-flex align-end ga-1 mt-3" style="height:90px">
            <div v-for="d in asistencia.porDia" :key="d.fecha" class="d-flex flex-column align-center" style="flex:1;min-width:14px">
              <div :style="{ height: (d.total / maxDia * 70) + 'px', width:'100%', background:'#1a3fe8', borderRadius:'3px 3px 0 0', minHeight:'2px' }" :title="`${d.fecha}: ${d.total}`"></div>
              <div style="font-size:8px;color:#9ca3af;margin-top:2px">{{ d.fecha.slice(8, 10) }}</div>
            </div>
            <div v-if="!asistencia.porDia.length" class="text-medium-emphasis" style="font-size:13px">Sin asistencias en el periodo</div>
          </div>
        </v-card-text>
      </v-card>
      <v-card rounded="xl" border>
        <div class="d-flex align-center justify-space-between pa-3" style="border-bottom:1px solid #f1f5f9">
          <span class="lbl">Ausentismo — activos sin asistir en el periodo ({{ asistencia.ausentes.length }})</span>
          <v-btn size="small" variant="outlined" rounded="lg" prepend-icon="mdi-microsoft-excel" @click="exportarAusentes">Excel</v-btn>
        </div>
        <v-table density="compact">
          <thead><tr><th class="ch">Alumno</th><th class="ch">Última asistencia</th></tr></thead>
          <tbody>
            <tr v-if="!asistencia.ausentes.length"><td colspan="2" class="text-center py-8 text-medium-emphasis">Todos los activos asistieron 🎉</td></tr>
            <tr v-for="a in asistencia.ausentes" :key="a.folio">
              <td style="font-size:13px;color:#0a1e6e;font-weight:600">{{ a.nombre }} <span style="color:#9ca3af;font-weight:400">#{{ a.folio }}</span></td>
              <td style="font-size:12px;color:#6b7280">{{ a.ultima ? fmtFecha(a.ultima) : 'Nunca' }}</td>
            </tr>
          </tbody>
        </v-table>
      </v-card>
    </template>

    <!-- ── OCUPACIÓN ─────────────────────────────────────────────────────── -->
    <template v-else-if="tab === 'ocupacion' && ocupacion">
      <v-row dense>
        <v-col cols="6" sm="4">
          <v-card rounded="xl" border><v-card-text class="pa-4">
            <div class="lbl">Clases activas</div>
            <div class="num" style="color:#0a1e6e">{{ ocupacion.totalClases }}</div>
          </v-card-text></v-card>
        </v-col>
        <v-col cols="6" sm="4">
          <v-card rounded="xl" border><v-card-text class="pa-4">
            <div class="lbl">Alumnos en clase</div>
            <div class="num" style="color:#15803d">{{ ocupacion.totalAlumnos }}</div>
          </v-card-text></v-card>
        </v-col>
      </v-row>
      <v-card rounded="xl" border>
        <div class="d-flex align-center justify-space-between pa-3" style="border-bottom:1px solid #f1f5f9">
          <span class="lbl">Carga por instructor (horario vigente)</span>
          <v-btn size="small" variant="outlined" rounded="lg" prepend-icon="mdi-microsoft-excel" @click="exportarOcupacion">Excel</v-btn>
        </div>
        <v-table density="compact">
          <thead><tr><th class="ch">Instructor</th><th class="ch text-right">Clases/sem</th><th class="ch text-right">Alumnos</th></tr></thead>
          <tbody>
            <tr v-for="i in ocupacion.instructores" :key="i.id">
              <td style="font-size:13px;color:#0a1e6e;font-weight:600">{{ i.nombre }}</td>
              <td class="text-right">{{ i.clases }}</td>
              <td class="text-right" style="font-weight:700;color:#0a1e6e">{{ i.alumnos }}</td>
            </tr>
          </tbody>
        </v-table>
      </v-card>
    </template>
  </div>
</template>

<style scoped>
.lbl { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#9ca3af; }
.num { font-size:30px; font-weight:900; font-family:'Fraunces',serif; line-height:1.1; }
.sub { font-size:11px; color:#9ca3af; margin-top:2px; }
.ch  { color:#9ca3af; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
</style>
