<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { VForm } from 'vuetify/components'
import * as XLSX from 'xlsx'
import { useAlumnos, planesAMapa, type AlumnoResumen, type PlanCatalogo } from '@/composables/useAlumnos'
import { useSwal } from '@/composables/useSwal'
import { useSucursalStore } from '@/stores/sucursal'

const { exito } = useSwal()

const router         = useRouter()
const alumnos        = useAlumnos()
const sucursalStore  = useSucursalStore()

const lista      = ref<AlumnoResumen[]>([])
const cargando   = ref(false)
const errorMsg   = ref('')
const busqueda   = ref('')
const filtroMod  = ref('')
const filtroSt   = ref('activo')
const filtroPago = ref('todos')
const modalAlta  = ref(false)
const guardando  = ref(false)
const errorAlta  = ref('')
const formRef    = ref<InstanceType<typeof VForm>>()

// ── Catálogo de planes desde API ──────────────────────────────────────────
const planes       = ref<PlanCatalogo[]>([])
const planCatalogo = computed(() => planesAMapa(planes.value))

async function cargarPlanes() {
  try { planes.value = await alumnos.listarPlanes() } catch {}
}

// ── Carga ─────────────────────────────────────────────────────────────────
async function cargar() {
  cargando.value = true; errorMsg.value = ''
  try {
    const params: Record<string,string> = {}
    if (busqueda.value.trim()) params.busqueda  = busqueda.value.trim()
    if (filtroMod.value)       params.modalidad  = filtroMod.value
    if (filtroSt.value)        params.status     = filtroSt.value
    if (filtroPago.value === 'toca_pago') params.toca_pago = '1'
    lista.value = await alumnos.listar(params)
  } catch (e: any) { errorMsg.value = e.message }
  finally { cargando.value = false }
}

let timer: ReturnType<typeof setTimeout>
watch(busqueda, () => { clearTimeout(timer); timer = setTimeout(cargar, 350) })
watch([filtroMod, filtroSt, filtroPago], cargar)
onMounted(() => { cargar(); cargarPlanes() })

// ── Filtro local vencido ──────────────────────────────────────────────────
const listaFinal = computed(() =>
  filtroPago.value === 'vencido' ? lista.value.filter(a => a.diasMora > 0) : lista.value
)

// ── Stats ─────────────────────────────────────────────────────────────────
const stats = computed(() => ({
  total:    lista.value.length,
  alDia:    lista.value.filter(a => !a.tocaPago && a.diasMora === 0).length,
  tocaPago: lista.value.filter(a => a.tocaPago && a.diasMora === 0).length,
  vencidos: lista.value.filter(a => a.diasMora > 0).length,
}))

// ── Helpers ───────────────────────────────────────────────────────────────
function estadoPago(a: AlumnoResumen) {
  if (a.diasMora > 0) return { label: `${a.diasMora}d mora`, color: 'error' }
  if (a.tocaPago)     return { label: 'Toca pago',           color: 'warning' }
  return                     { label: 'Al día',               color: 'success' }
}
function colorMod(m: string) {
  return m === 'PART' ? 'blue-lighten-4' : m === 'GRUPAL' ? 'pink-lighten-4' : 'orange-lighten-4'
}
function textMod(m: string) {
  return m === 'PART' ? '#1e3a8a' : m === 'GRUPAL' ? '#9d174d' : '#9a3412'
}
const fmt = (n: number) =>
  new Intl.NumberFormat('es-MX', { style:'currency', currency:'MXN', minimumFractionDigits:0 }).format(n)
const fmtFecha = (f?: string) =>
  f ? new Date(f + 'T12:00:00').toLocaleDateString('es-MX', { day:'2-digit', month:'short', year:'numeric' }) : '—'

// ── Formulario alta ───────────────────────────────────────────────────────
// El alta solo registra al alumno y su plan contratado.
// La asignación a slots de horario es responsabilidad del Calendario (etapa separada).
const hoy = new Date().toISOString().slice(0, 10)

// Fecha máxima para nacimiento = hoy − 1 año (mínimo requerido)
const maxFechaNac = computed(() => {
  const d = new Date()
  d.setFullYear(d.getFullYear() - 1)
  return d.toISOString().slice(0, 10)
})

const errorFechaNac = computed<string[]>(() => {
  const v = form.value.fechaNacimiento
  if (!v) return []
  const ahora = new Date()
  const nacer = new Date(v + 'T12:00:00')
  if (nacer > ahora) return ['No puede ser fecha futura']
  const edadDias = (ahora.getTime() - nacer.getTime()) / 86_400_000
  if (edadDias <= 365) return ['El inscrito debe tener más de 1 año de edad']
  return []
})
const errorFechaInsc = computed<string[]>(() => {
  const v = form.value.fechaInscripcion
  if (!v) return ['Campo requerido']
  const hoyFresh = new Date().toISOString().slice(0, 10)
  if (v > hoyFresh) return ['No puede ser fecha futura']
  return []
})

const form = ref(initForm())
function initForm() {
  return { nombre:'', fechaNacimiento:'', tutor:'', telefono:'', email:'',
           modalidad:'GRUPAL', clasesSemana:1,
           mensualidad: 0,
           fechaInscripcion: hoy,
           aplicarDescuento: false,
           descuentoPct: '' as string | number, descuentoMotivo:'',
           montoBaseInscripcion: 300,
           aplicarDescuentoInsc: false,
           descuentoPctInsc: '' as string | number, descuentoMotivoInsc:'',
           status:'activo' }
}

// Clases disponibles filtradas por modalidad seleccionada
const clasesDisponibles = computed(() =>
  planes.value
    .filter(p => p.modalidad === form.value.modalidad && p.activo)
    .sort((a, b) => a.clasesSemana - b.clasesSemana)
    .map(p => ({ title: `${p.clasesSemana} clase${p.clasesSemana > 1 ? 's' : ''}/semana`, value: p.clasesSemana }))
)

// Al cambiar modalidad: resetear clases al primer plan disponible
watch(() => form.value.modalidad, () => {
  const primero = clasesDisponibles.value[0]
  if (primero) form.value.clasesSemana = primero.value
})

// Auto-llenar mensualidad cuando cambia modalidad, clases o catálogo
watch(
  () => [form.value.modalidad, form.value.clasesSemana, planCatalogo.value] as const,
  ([mod, cls, cat]) => { const p = cat[mod]?.[cls]; if (p) form.value.mensualidad = p },
  { immediate: true }
)

const descPct        = computed(() => form.value.aplicarDescuento ? parseFloat(String(form.value.descuentoPct)) || 0 : 0)
const montoDesc      = computed(() => form.value.mensualidad * descPct.value / 100)
const montoFinal     = computed(() => form.value.mensualidad - montoDesc.value)
const tieneResumen   = computed(() => descPct.value > 0 && form.value.mensualidad > 0)

const descPctInsc    = computed(() => form.value.aplicarDescuentoInsc ? parseFloat(String(form.value.descuentoPctInsc)) || 0 : 0)
const montoDescInsc  = computed(() => form.value.montoBaseInscripcion * descPctInsc.value / 100)
const montoInscFinal = computed(() => Math.max(0, form.value.montoBaseInscripcion - montoDescInsc.value))

function abrirAlta() { form.value = initForm(); errorAlta.value = ''; modalAlta.value = true }

async function guardar() {
  const { valid } = await formRef.value!.validate()
  if (!valid) return
  if (errorFechaNac.value.length || errorFechaInsc.value.length) return
  guardando.value = true; errorAlta.value = ''
  try {
    await alumnos.crear({
      nombre: form.value.nombre.trim(),
      fechaNacimiento: form.value.fechaNacimiento || null,
      contacto: { tutor: form.value.tutor || null, telefono: form.value.telefono, email: form.value.email || null },
      modalidad: form.value.modalidad,
      plan: { clasesSemana: form.value.clasesSemana, mensualidad: montoFinal.value },
      fechaInicio: form.value.fechaInscripcion,
      inscripcionPagada: montoInscFinal.value === 0,
      montoInscripcion: montoInscFinal.value,
      descuento: descPct.value > 0 ? { pct: descPct.value, motivo: form.value.descuentoMotivo } : null,
      disponibilidad: { dias: [], horaInicio: null, horaFin: null },
      status: form.value.status,
      sucursalId: sucursalStore.sucursalActivaId,
    })
    modalAlta.value = false
    await cargar()
    exito('¡Alumno registrado!', 'El alumno fue dado de alta correctamente.')
  } catch (e: any) { errorAlta.value = e.message }
  finally { guardando.value = false }
}

// ── Importación masiva ────────────────────────────────────────────────────
interface ImportFila {
  nombre: string; fechaNacimiento: string; tutor: string; telefono: string
  email: string; modalidad: string; clasesSemana: number; fechaInicio: string
  descuentoPct: number; descuentoMotivo: string; status: string
  inscripcionPagada: boolean; montoInscripcion: number; errores: string[]
}

const COLUMNAS = [
  { key: 'nombre',            label: 'Nombre',             ejemplo: 'María González', req: true  },
  { key: 'fechaNacimiento',   label: 'Fecha Nacimiento',   ejemplo: '2018-03-15',     req: true  },
  { key: 'tutor',             label: 'Tutor / Contacto',   ejemplo: 'Ana González',   req: true  },
  { key: 'telefono',          label: 'Telefono',           ejemplo: '4431234567',     req: true  },
  { key: 'email',             label: 'Email',              ejemplo: 'ana@gmail.com',  req: false },
  { key: 'modalidad',         label: 'Modalidad',          ejemplo: 'GRUPAL',         req: true  },
  { key: 'clasesSemana',      label: 'Clases Semana',      ejemplo: '1',              req: true  },
  { key: 'fechaInicio',       label: 'Fecha Inicio',       ejemplo: hoy,              req: true  },
  { key: 'descuentoPct',      label: 'Descuento Pct',      ejemplo: '0',              req: false },
  { key: 'descuentoMotivo',   label: 'Motivo Descuento',   ejemplo: 'Hermanos',       req: false },
  { key: 'inscripcionPagada', label: 'Inscripcion Pagada', ejemplo: 'NO',             req: true  },
  { key: 'montoInscripcion',  label: 'Monto Inscripcion',  ejemplo: '300',            req: true  },
] as const

const modalImportar  = ref(false)
const importPaso     = ref<'subir' | 'preview' | 'importando' | 'resultado'>('subir')
const importFilas    = ref<ImportFila[]>([])
const importProgreso = ref(0)
const importOk       = ref(0)
const importErrores  = ref<string[]>([])
const dropActivo     = ref(false)

const filasValidas   = computed(() => importFilas.value.filter(f => f.errores.length === 0))
const filasInvalidas = computed(() => importFilas.value.filter(f => f.errores.length > 0))

function descargarPlantilla() {
  const encabezados = COLUMNAS.map(c => c.label)
  const ejemplo     = COLUMNAS.map(c => c.ejemplo)
  const notas       = COLUMNAS.map(c => c.req ? '* requerido' : 'opcional')
  const ws = XLSX.utils.aoa_to_sheet([encabezados, ejemplo, notas])
  ws['!cols'] = COLUMNAS.map(c => ({ wch: Math.max(c.label.length, String(c.ejemplo).length) + 4 }))
  const wb = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(wb, ws, 'Alumnos')
  XLSX.writeFile(wb, 'plantilla_alumnos_beluga.xlsx')
}

function abrirImportar() {
  importPaso.value = 'subir'; importFilas.value = []
  importProgreso.value = 0; importOk.value = 0; importErrores.value = []
  modalImportar.value = true
}

function onFileInput(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0]
  if (f) leerArchivo(f);
  (e.target as HTMLInputElement).value = ''
}

function onDrop(e: DragEvent) {
  dropActivo.value = false
  const f = e.dataTransfer?.files?.[0]
  if (f) leerArchivo(f)
}

function leerArchivo(file: File) {
  const reader = new FileReader()
  reader.onload = ev => {
    const data = new Uint8Array(ev.target!.result as ArrayBuffer)
    const wb   = XLSX.read(data, { type: 'array', cellDates: true })
    const ws   = wb.Sheets[wb.SheetNames[0]]
    const rows = XLSX.utils.sheet_to_json<Record<string, any>>(ws, { defval: '' })
    importFilas.value = rows
      .filter(r => Object.values(r).some(v => String(v).trim()))
      .filter((_r, i) => i > 0 || !String(Object.values(_r)[0]).toLowerCase().includes('requerido'))
      .map(normalizarFila)
    importPaso.value = 'preview'
  }
  reader.readAsArrayBuffer(file)
}

function getCell(row: Record<string, any>, key: string, label: string): string {
  return String(row[label] ?? row[key] ?? '').trim()
}

function normalizarFila(row: Record<string, any>): ImportFila {
  const nombre          = getCell(row, 'nombre', 'Nombre')
  const fechaNacimiento = getCell(row, 'fechaNacimiento', 'Fecha Nacimiento')
  const tutor           = getCell(row, 'tutor', 'Tutor / Contacto')
  const telefono        = getCell(row, 'telefono', 'Telefono')
  const email           = getCell(row, 'email', 'Email')
  const modalidad       = getCell(row, 'modalidad', 'Modalidad').toUpperCase()
  const clasesSemana    = parseInt(getCell(row, 'clasesSemana', 'Clases Semana')) || 1
  const fechaInicio     = getCell(row, 'fechaInicio', 'Fecha Inicio')
  const descuentoPct    = parseFloat(getCell(row, 'descuentoPct', 'Descuento Pct')) || 0
  const descuentoMotivo = getCell(row, 'descuentoMotivo', 'Motivo Descuento')
  const inscRaw         = getCell(row, 'inscripcionPagada', 'Inscripcion Pagada').toUpperCase()
  const inscripcionPagada = ['SI', 'SÍ', 'YES', '1', 'TRUE'].includes(inscRaw)
  const montoInscripcion  = parseFloat(getCell(row, 'montoInscripcion', 'Monto Inscripcion')) || 300

  const errores: string[] = []
  if (!nombre)          errores.push('Nombre requerido')
  if (!fechaNacimiento) errores.push('Fecha Nacimiento requerida')
  if (!tutor)           errores.push('Tutor / Contacto requerido')
  if (!telefono)        errores.push('Teléfono requerido')
  if (!['PART', 'GRUPAL', 'MATRO'].includes(modalidad))
    errores.push('Modalidad inválida — usa PART, GRUPAL o MATRO')
  if (![1, 2, 3, 5].includes(clasesSemana))
    errores.push('Clases/semana inválido — usa 1, 2, 3 o 5')
  if (!fechaInicio) errores.push('Fecha Inicio requerida')
  if (!['SI', 'SÍ', 'YES', '1', 'TRUE', 'NO', 'NOT', '0', 'FALSE'].includes(inscRaw))
    errores.push('Inscripcion Pagada debe ser SI o NO')

  return { nombre, fechaNacimiento, tutor, telefono, email, modalidad, clasesSemana,
           fechaInicio, descuentoPct, descuentoMotivo, status: 'activo', inscripcionPagada,
           montoInscripcion, errores }
}

async function ejecutarImport() {
  importPaso.value = 'importando'
  importProgreso.value = 0; importOk.value = 0; importErrores.value = []

  for (const fila of filasValidas.value) {
    try {
      await alumnos.crear({
        nombre: fila.nombre,
        fechaNacimiento: fila.fechaNacimiento || null,
        contacto: { tutor: fila.tutor || null, telefono: fila.telefono, email: fila.email || null },
        modalidad: fila.modalidad as any,
        plan: {
          clasesSemana: fila.clasesSemana,
          mensualidad: planCatalogo.value[fila.modalidad]?.[fila.clasesSemana] ?? 0,
        },
        fechaInicio: fila.fechaInicio,
        inscripcionPagada: fila.inscripcionPagada || fila.montoInscripcion === 0,
        montoInscripcion: fila.montoInscripcion,
        descuento: fila.descuentoPct > 0 ? { pct: fila.descuentoPct, motivo: fila.descuentoMotivo } : null,
        disponibilidad: { dias: [], horaInicio: null, horaFin: null },
        status: fila.status as any,
        sucursalId: sucursalStore.sucursalActivaId,
      })
      importOk.value++
    } catch (e: any) {
      importErrores.value.push(`${fila.nombre}: ${e.message}`)
    }
    importProgreso.value++
  }

  importPaso.value = 'resultado'
  if (importOk.value > 0) await cargar()
}
</script>

<template>
  <div class="d-flex flex-column ga-4">

    <!-- Stats rápidas -->
    <v-row dense>
      <v-col v-for="s in [
        { label:'Total activos', val: stats.total,    color:'#0a1e6e', cursor:'default' },
        { label:'Al día',        val: stats.alDia,    color:'#15803d', cursor:'pointer', pago:'aldía' },
        { label:'Toca pago',     val: stats.tocaPago, color:'#d97706', cursor:'pointer', pago:'toca_pago' },
        { label:'Vencidos',      val: stats.vencidos, color:'#dc2626', cursor:'pointer', pago:'vencido' },
      ]" :key="s.label" cols="6" md="3">
        <v-card rounded="xl" border :style="{ cursor: s.cursor, outline: filtroPago === s.pago ? '2px solid '+s.color : 'none' }"
          @click="s.pago ? filtroPago = filtroPago === s.pago ? 'todos' : s.pago : null">
          <v-card-text class="pa-4">
            <div class="text-caption font-weight-bold text-uppercase mb-2" style="letter-spacing:.08em;color:#9ca3af">{{ s.label }}</div>
            <div class="font-weight-black" style="font-size:32px;font-family:'Fraunces',serif;line-height:1" :style="{ color: s.color }">
              {{ cargando ? '—' : s.val }}
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- Toolbar -->
    <v-card rounded="xl" border>
      <v-card-text class="pa-3">
        <div class="d-flex flex-wrap align-center ga-3">
          <v-text-field
            v-model="busqueda"
            placeholder="Nombre, folio o teléfono…"
            prepend-inner-icon="mdi-magnify"
            density="compact"
            rounded="lg"
            variant="outlined"
            hide-details
            style="max-width:300px"
            clearable
          />
          <v-select
            v-model="filtroMod"
            :items="[{title:'Todas las modalidades',value:''},{title:'PART',value:'PART'},{title:'GRUPAL',value:'GRUPAL'},{title:'MATRO',value:'MATRO'}]"
            density="compact"
            rounded="lg"
            variant="outlined"
            hide-details
            style="min-width:190px"
          />
          <v-select
            v-model="filtroSt"
            :items="[{title:'Todos los status',value:''},{title:'Activos',value:'activo'},{title:'Inactivo',value:'baja'}]"
            density="compact"
            rounded="lg"
            variant="outlined"
            hide-details
            style="min-width:160px"
          />
          <v-spacer />
          <v-btn variant="outlined" prepend-icon="mdi-download-outline" rounded="lg" @click="descargarPlantilla">
            Plantilla
          </v-btn>
          <v-btn variant="outlined" prepend-icon="mdi-upload-outline" rounded="lg" @click="abrirImportar">
            Importar
          </v-btn>
          <v-btn color="primary" prepend-icon="mdi-plus" rounded="lg" @click="abrirAlta">
            Nuevo alumno
          </v-btn>
        </div>
      </v-card-text>
    </v-card>

    <!-- Tabla -->
    <v-card rounded="xl" border>
      <v-table density="comfortable" hover>
        <thead>
          <tr>
            <th style="color:#9ca3af;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Folio</th>
            <th style="color:#9ca3af;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Nombre</th>
            <th style="color:#9ca3af;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Modalidad</th>
            <th style="color:#9ca3af;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Plan</th>
            <th style="color:#9ca3af;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Mensualidad</th>
            <th style="color:#9ca3af;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Vencimiento</th>
            <th style="color:#9ca3af;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Estado pago</th>
            <th style="color:#9ca3af;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="cargando">
            <td colspan="9" class="text-center py-10">
              <v-progress-circular indeterminate color="primary" size="32" />
            </td>
          </tr>
          <tr v-else-if="errorMsg">
            <td colspan="9" class="text-center py-10 text-error">{{ errorMsg }}</td>
          </tr>
          <tr v-else-if="!listaFinal.length">
            <td colspan="9" class="text-center py-10 text-medium-emphasis">Sin resultados</td>
          </tr>
          <tr v-for="a in listaFinal" :key="a.folio" style="cursor:pointer" @click="router.push(`/alumnos/${a.folio}`)">
            <td><span style="font-family:monospace;font-size:12px;color:#9ca3af">#{{ a.folio }}</span></td>
            <td><span style="font-weight:600;color:#0a1e6e">{{ a.nombre }}</span></td>
            <td>
              <v-chip size="x-small" :color="colorMod(a.modalidad)" :style="{ color: textMod(a.modalidad) }" variant="flat" label>
                {{ a.modalidad }}
              </v-chip>
            </td>
            <td><span style="font-size:13px;color:#6b7280">{{ a.plan.label ?? `${a.plan.clasesSemana}x/sem` }}</span></td>
            <td>
              <span style="font-weight:700;color:#0a1e6e">{{ fmt(a.montoConDescuento) }}</span>
              <v-chip v-if="a.descuento" size="x-small" color="teal-lighten-4" class="ml-1" variant="flat">descuento -{{ a.descuento.pct }}%</v-chip>
            </td>
            <td style="font-size:13px;color:#6b7280">{{ fmtFecha(a.vencimiento) }}</td>
            <td>
              <v-chip size="small" :color="estadoPago(a).color" variant="tonal" label>{{ estadoPago(a).label }}</v-chip>
            </td>
            <td>
              <v-chip size="x-small" :color="a.status === 'activo' ? 'success' : a.status === 'prueba' ? 'pink-lighten-3' : 'grey'" variant="tonal" label>
                {{ a.status }}
              </v-chip>
            </td>
            <td>
              <v-icon icon="mdi-chevron-right" size="18" color="grey-lighten-1" />
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <!-- Modal: Nuevo alumno -->
    <v-dialog v-model="modalAlta" max-width="680" persistent scrollable>
      <v-card rounded="xl">
        <div style="background:linear-gradient(135deg,#e8f4ff,#dbeafe);padding:20px 24px;border-radius:16px 16px 0 0;border-bottom:1px solid #b8d4ff" class="d-flex align-center justify-space-between">
          <div>
            <div style="color:#1a3fe8;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin-bottom:4px">Alta de alumno</div>
            <div style="color:#0a1e6e;font-size:18px;font-weight:700;font-family:'Fraunces',serif">Nuevo alumno</div>
          </div>
          <v-btn icon="mdi-close" variant="text" color="#0a1e6e" @click="modalAlta = false" />
        </div>

        <v-form ref="formRef">
        <v-card-text class="pa-5">
          <v-row dense>
            <!-- Datos personales -->
            <v-col cols="12"><p class="section-label">Datos personales</p></v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="form.nombre"
                label="Nombre completo *"
                density="compact" variant="outlined" rounded="lg"
                :rules="[v => !!v?.trim() || 'Campo requerido', v => v.trim().length >= 2 || 'Mínimo 2 caracteres']"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="form.fechaNacimiento"
                label="Fecha de nacimiento"
                type="date"
                density="compact" variant="outlined" rounded="lg"
                :max="maxFechaNac"
                :error-messages="errorFechaNac"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="form.tutor" label="Tutor / Mamá / Papá" density="compact" variant="outlined" rounded="lg" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="form.telefono"
                label="Teléfono *"
                density="compact" variant="outlined" rounded="lg"
                prepend-inner-icon="mdi-phone-outline"
                :rules="[v => !!v?.trim() || 'Campo requerido', v => /^\d{7,15}$/.test(v.replace(/[\s\-\(\)]/g,'')) || 'Teléfono inválido']"
              />
            </v-col>
            <v-col cols="12">
              <v-text-field
                v-model="form.email"
                label="Correo electrónico"
                type="email"
                density="compact" variant="outlined" rounded="lg"
                prepend-inner-icon="mdi-email-outline"
                :rules="[v => !v || /.+@.+\..+/.test(v) || 'Email inválido']"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-select
                v-model="form.status"
                :items="[{title:'Activo',value:'activo'},{title:'Clase de prueba',value:'prueba'},{title:'Inactivo (baja)',value:'baja'}]"
                label="Status inicial"
                density="compact" variant="outlined" rounded="lg"
              />
            </v-col>

            <!-- Plan -->
            <v-col cols="12"><p class="section-label mt-2">Plan y fechas</p></v-col>
            <v-col cols="12" sm="6">
              <v-select
                v-model="form.modalidad"
                :items="[{title:'GRUPAL',value:'GRUPAL'},{title:'PART (particular)',value:'PART'},{title:'MATRO (matrona)',value:'MATRO'}]"
                label="Modalidad" density="compact" variant="outlined" rounded="lg"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-select
                v-model.number="form.clasesSemana"
                :items="clasesDisponibles"
                label="Clases por semana"
                density="compact" variant="outlined" rounded="lg"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="form.fechaInscripcion"
                label="Fecha de inscripción *"
                type="date"
                density="compact" variant="outlined" rounded="lg"
                :max="hoy"
                :error-messages="errorFechaInsc"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model.number="form.mensualidad"
                label="Mensualidad base *"
                type="number"
                prefix="$"
                density="compact" variant="outlined" rounded="lg"
                bg-color="#eff6ff"
                style="font-weight:700"
                :rules="[v => v > 0 || 'Ingresa la mensualidad']"
              />
            </v-col>
            <v-col cols="12" sm="6" class="d-flex align-center">
              <v-checkbox
                v-model="form.aplicarDescuento"
                label="Aplicar descuento"
                density="compact"
                hide-details
                color="primary"
              />
            </v-col>
            <template v-if="form.aplicarDescuento">
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model="form.descuentoPct"
                  label="Descuento mensualidad %"
                  type="number" min="0" max="100"
                  density="compact" variant="outlined" rounded="lg"
                  :rules="[v => !v || (Number(v) >= 0 && Number(v) <= 100) || 'Entre 0 y 100']"
                />
              </v-col>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model="form.descuentoMotivo"
                  label="Motivo del descuento"
                  density="compact" variant="outlined" rounded="lg"
                  placeholder="Hermanos, empleado…"
                  :rules="[v => !descPct || !!v?.trim() || 'Indica el motivo del descuento']"
                />
              </v-col>
            </template>

            <!-- Resumen mensual (siempre visible) -->
            <v-col v-if="form.mensualidad > 0" cols="12">
              <div class="resumen-precio">
                <template v-if="tieneResumen">
                  <div class="resumen-row">
                    <span>Mensualidad base</span>
                    <span>{{ fmt(form.mensualidad) }}</span>
                  </div>
                  <div class="resumen-row text-medium-emphasis">
                    <span>Descuento mensualidad ({{ descPct }}%)</span>
                    <span style="color:#dc2626">− {{ fmt(montoDesc) }}</span>
                  </div>
                  <v-divider class="my-2" />
                </template>
                <div class="resumen-row resumen-total">
                  <span>Mensualidad a cobrar</span>
                  <span style="color:#0a1e6e;font-family:'Fraunces',serif">{{ fmt(montoFinal) }}</span>
                </div>
              </div>
            </v-col>

            <!-- Inscripción -->
            <v-col cols="12"><p class="section-label mt-2">Cuota de inscripción</p></v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model.number="form.montoBaseInscripcion"
                label="Monto de inscripción *"
                type="number" min="0" prefix="$"
                density="compact" variant="outlined" rounded="lg"
                bg-color="#eff6ff"
                :rules="[v => v >= 0 || 'No puede ser negativo']"
              />
            </v-col>
            <v-col cols="12" sm="6" class="d-flex align-center">
              <v-checkbox
                v-model="form.aplicarDescuentoInsc"
                label="Aplicar descuento"
                density="compact"
                hide-details
                color="primary"
              />
            </v-col>
            <template v-if="form.aplicarDescuentoInsc">
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model="form.descuentoPctInsc"
                  label="Descuento inscripción %"
                  type="number" min="0" max="100"
                  density="compact" variant="outlined" rounded="lg"
                  :rules="[v => !v || (Number(v) >= 0 && Number(v) <= 100) || 'Entre 0 y 100']"
                />
              </v-col>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model="form.descuentoMotivoInsc"
                  label="Motivo del descuento"
                  density="compact" variant="outlined" rounded="lg"
                  placeholder="Promoción, beca…"
                  :rules="[v => !descPctInsc || !!v?.trim() || 'Indica el motivo del descuento']"
                />
              </v-col>
              <v-col cols="12">
                <div class="resumen-precio">
                  <div class="resumen-row">
                    <span>Monto inscripción</span>
                    <span>{{ fmt(form.montoBaseInscripcion) }}</span>
                  </div>
                  <div class="resumen-row text-medium-emphasis">
                    <span>Descuento ({{ descPctInsc }}%)</span>
                    <span style="color:#dc2626">− {{ fmt(montoDescInsc) }}</span>
                  </div>
                  <v-divider class="my-2" />
                  <div class="resumen-row resumen-total">
                    <span>Total a cobrar por inscripción</span>
                    <span style="color:#0a1e6e;font-family:'Fraunces',serif">{{ fmt(montoInscFinal) }}</span>
                  </div>
                </div>
              </v-col>
            </template>

            <!-- Nota informativa -->
            <v-col cols="12">
              <v-alert type="info" density="compact" rounded="lg" variant="tonal" icon="mdi-calendar-clock-outline" class="mt-1">
                La asignación de horarios y slots con instructor se realiza desde el <strong>Calendario</strong>, una vez registrado el alumno.
              </v-alert>
            </v-col>
          </v-row>
        </v-card-text>
        </v-form>

        <v-divider />
        <v-card-actions class="pa-4 ga-2 justify-end">
          <v-alert v-if="errorAlta" type="error" density="compact" variant="tonal" rounded="lg" class="mr-auto" style="flex:1">{{ errorAlta }}</v-alert>
          <v-btn variant="text" @click="modalAlta = false">Cancelar</v-btn>
          <v-btn color="primary" rounded="lg" :loading="guardando" @click="guardar">Registrar alumno</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Modal: Importar alumnos -->
    <v-dialog v-model="modalImportar" max-width="860" persistent scrollable>
      <v-card rounded="xl">
        <div style="background:linear-gradient(135deg,#e8f4ff,#dbeafe);padding:20px 24px;border-radius:16px 16px 0 0;border-bottom:1px solid #b8d4ff" class="d-flex align-center justify-space-between">
          <div>
            <div style="color:#1a3fe8;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin-bottom:4px">Importación masiva</div>
            <div style="color:#0a1e6e;font-size:18px;font-weight:700;font-family:'Fraunces',serif">Importar alumnos</div>
          </div>
          <v-btn icon="mdi-close" variant="text" color="#0a1e6e" @click="modalImportar = false" />
        </div>

        <v-card-text class="pa-5">

          <!-- Paso 1: Subir archivo -->
          <div v-if="importPaso === 'subir'">
            <p style="font-size:13px;color:#6b7280;margin-bottom:16px">
              Sube un archivo <strong>.xlsx</strong> o <strong>.csv</strong> con los datos de los alumnos.
              Descarga la plantilla si aún no la tienes.
            </p>
            <div
              class="drop-zone"
              :class="{ 'drop-zone--activo': dropActivo }"
              @dragover.prevent="dropActivo = true"
              @dragleave="dropActivo = false"
              @drop.prevent="onDrop"
              @click="($refs.fileInput as HTMLInputElement).click()"
            >
              <v-icon icon="mdi-cloud-upload-outline" size="48" color="#b8d4ff" class="mb-3" />
              <div style="font-weight:600;color:#0a1e6e;font-size:15px">Arrastra tu archivo aquí</div>
              <div style="font-size:12px;color:#9ca3af;margin-top:4px">o haz clic para seleccionar · .xlsx · .xls · .csv</div>
              <input ref="fileInput" type="file" accept=".xlsx,.xls,.csv" style="display:none" @change="onFileInput" />
            </div>
            <v-alert type="info" density="compact" variant="tonal" rounded="lg" class="mt-4" icon="mdi-lightbulb-outline">
              La primera fila del archivo debe contener los <strong>encabezados exactos</strong> de la plantilla.
              La segunda fila de la plantilla es un ejemplo — puedes borrarla antes de importar.
            </v-alert>
          </div>

          <!-- Paso 2: Preview -->
          <div v-else-if="importPaso === 'preview'">
            <div class="d-flex align-center ga-3 mb-4 flex-wrap">
              <v-chip color="success" variant="tonal" size="small" prepend-icon="mdi-check-circle">
                {{ filasValidas.length }} válidos
              </v-chip>
              <v-chip v-if="filasInvalidas.length" color="error" variant="tonal" size="small" prepend-icon="mdi-alert-circle">
                {{ filasInvalidas.length }} con errores
              </v-chip>
              <v-chip color="grey" variant="tonal" size="small">
                {{ importFilas.length }} total
              </v-chip>
              <v-spacer />
              <v-btn size="small" variant="text" prepend-icon="mdi-arrow-left" @click="importPaso = 'subir'">
                Cambiar archivo
              </v-btn>
            </div>

            <div style="max-height:360px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:10px">
              <v-table density="compact">
                <thead>
                  <tr>
                    <th style="width:32px"></th>
                    <th>Nombre</th>
                    <th>Teléfono</th>
                    <th>Modalidad</th>
                    <th>Clases</th>
                    <th>Fecha inicio</th>
                    <th>Descuento</th>
                    <th>Inscripción</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(fila, i) in importFilas" :key="i"
                    :style="fila.errores.length ? 'background:#fff5f5' : ''">
                    <td>
                      <v-icon v-if="!fila.errores.length" icon="mdi-check-circle" color="success" size="16" />
                      <v-tooltip v-else :text="fila.errores.join(' · ')" location="right">
                        <template #activator="{ props }">
                          <v-icon v-bind="props" icon="mdi-alert-circle" color="error" size="16" />
                        </template>
                      </v-tooltip>
                    </td>
                    <td style="font-size:13px;font-weight:600;color:#0a1e6e">{{ fila.nombre || '—' }}</td>
                    <td style="font-size:12px;color:#6b7280">{{ fila.telefono || '—' }}</td>
                    <td>
                      <v-chip v-if="['PART','GRUPAL','MATRO'].includes(fila.modalidad)"
                        size="x-small" :color="colorMod(fila.modalidad)" variant="flat"
                        :style="{ color: textMod(fila.modalidad) }">{{ fila.modalidad }}</v-chip>
                      <span v-else style="color:#dc2626;font-size:11px">{{ fila.modalidad || '?' }}</span>
                    </td>
                    <td style="font-size:12px;color:#6b7280">{{ fila.clasesSemana }}/sem</td>
                    <td style="font-size:12px;color:#6b7280">{{ fila.fechaInicio || '—' }}</td>
                    <td style="font-size:12px;color:#6b7280">{{ fila.descuentoPct > 0 ? fila.descuentoPct + '%' : '—' }}</td>
                    <td style="font-size:12px">
                      <span :style="{ color: fila.inscripcionPagada ? '#15803d' : '#374151' }">
                        {{ fila.inscripcionPagada ? 'Pagada' : '$' + fila.montoInscripcion }}
                      </span>
                    </td>
                  </tr>
                </tbody>
              </v-table>
            </div>

            <v-alert v-if="filasInvalidas.length" type="warning" density="compact" variant="tonal" rounded="lg" class="mt-3">
              Los registros con errores <strong>no se importarán</strong>. Corrígelos en el archivo y vuelve a subirlo, o procede con los {{ filasValidas.length }} válidos.
            </v-alert>
          </div>

          <!-- Paso 3: Importando -->
          <div v-else-if="importPaso === 'importando'" class="text-center py-8">
            <v-progress-circular :model-value="importProgreso / filasValidas.length * 100" color="primary" size="72" width="6" class="mb-4">
              <span style="font-size:13px;font-weight:700">{{ importProgreso }}/{{ filasValidas.length }}</span>
            </v-progress-circular>
            <div style="font-size:15px;font-weight:600;color:#0a1e6e">Importando alumnos…</div>
            <div style="font-size:12px;color:#9ca3af;margin-top:4px">No cierres esta ventana</div>
          </div>

          <!-- Paso 4: Resultado -->
          <div v-else-if="importPaso === 'resultado'" class="text-center py-6">
            <div style="width:64px;height:64px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
              <v-icon icon="mdi-check-circle" color="success" size="36" />
            </div>
            <div style="font-size:18px;font-weight:700;color:#0a1e6e;font-family:'Fraunces',serif">
              {{ importOk }} alumno{{ importOk !== 1 ? 's' : '' }} importado{{ importOk !== 1 ? 's' : '' }}
            </div>
            <div v-if="importErrores.length" class="mt-4 text-left">
              <v-alert type="error" density="compact" variant="tonal" rounded="lg">
                <div style="font-weight:700;margin-bottom:4px">{{ importErrores.length }} error{{ importErrores.length !== 1 ? 'es' : '' }} al guardar:</div>
                <div v-for="e in importErrores" :key="e" style="font-size:12px">• {{ e }}</div>
              </v-alert>
            </div>
          </div>

        </v-card-text>

        <v-divider />
        <v-card-actions class="pa-4 ga-2 justify-end">
          <template v-if="importPaso === 'subir'">
            <v-btn variant="outlined" prepend-icon="mdi-download-outline" rounded="lg" @click="descargarPlantilla">
              Descargar plantilla
            </v-btn>
            <v-btn variant="text" @click="modalImportar = false">Cancelar</v-btn>
          </template>
          <template v-else-if="importPaso === 'preview'">
            <v-btn variant="text" @click="modalImportar = false">Cancelar</v-btn>
            <v-btn
              color="primary" rounded="lg"
              :disabled="!filasValidas.length"
              @click="ejecutarImport"
            >
              Importar {{ filasValidas.length }} alumno{{ filasValidas.length !== 1 ? 's' : '' }}
            </v-btn>
          </template>
          <template v-else-if="importPaso === 'resultado'">
            <v-btn color="primary" rounded="lg" @click="modalImportar = false">Cerrar</v-btn>
          </template>
        </v-card-actions>
      </v-card>
    </v-dialog>

  </div>
</template>

<style scoped>
.section-label {
  font-size: 10.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: #9ca3af;
  border-bottom: 1px solid #f3f4f6;
  padding-bottom: 6px;
  margin-bottom: 4px;
}
.resumen-precio {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 12px 16px;
}
.resumen-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 13px;
  color: #6b7280;
  padding: 3px 0;
}
.resumen-total {
  font-size: 15px;
  font-weight: 700;
  color: #111827;
}
.drop-zone {
  border: 2px dashed #b8d4ff;
  border-radius: 16px;
  padding: 40px 24px;
  text-align: center;
  cursor: pointer;
  transition: background .15s, border-color .15s;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.drop-zone:hover,
.drop-zone--activo {
  background: #eff6ff;
  border-color: #1a3fe8;
}
</style>
