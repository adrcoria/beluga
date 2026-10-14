<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { VForm } from 'vuetify/components'
import { useCobranza, type Cobro, METODOS_PAGO } from '@/composables/useCobranza'
import { type PagoCobro } from '@/composables/useCobranza'
import {
  useAlumnos, USOS_CFDI, REGIMENES_FISCALES,
  type AlumnoResumen, type DatosFiscales,
} from '@/composables/useAlumnos'
import { useSwal } from '@/composables/useSwal'
import { useSucursalStore } from '@/stores/sucursal'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { imprimirComprobante } from '@/utils/ticket58'
import { toPng } from 'html-to-image'

const cobranzaApi   = useCobranza()
const alumnosApi    = useAlumnos()
const { exito, error: notifError } = useSwal()
const sucursalStore  = useSucursalStore()
const auth           = useAuthStore()
const ui             = useUiStore()

// Dispara la misma generación de cobros del login, bajo demanda
async function generarCobrosAhora() {
  ui.setSincronizando(true)
  try {
    const r = await cobranzaApi.generarCobros()
    await cargar()
    exito('Cobros actualizados', r.generados > 0
      ? `${r.generados} cobro${r.generados !== 1 ? 's' : ''} nuevo${r.generados !== 1 ? 's' : ''}`
      : 'Todo al día, sin cobros nuevos')
  } catch (e: any) {
    notifError('Error', e.message)
  } finally {
    ui.setSincronizando(false)
  }
}

// ── Estado ─────────────────────────────────────────────────────────────────
const cobros    = ref<Cobro[]>([])
const cargando  = ref(false)
const busqueda  = ref('')
const filtroEst = ref<'todos' | 'pendiente' | 'parcial' | 'pagado' | 'condonado'>('pendiente')
const soloFacturaPendiente = ref(false)

// ── Paginación ───────────────────────────────────────────────────────────────
const paginaActual = ref(1)
const porPagina    = ref(10)
const opcionesPorPagina = [10, 20, 50, 100]

// ── Modal pago ─────────────────────────────────────────────────────────────
const modalPago   = ref(false)
const cobroSel    = ref<Cobro | null>(null)
const guardando   = ref(false)
const pagoFormRef = ref<InstanceType<typeof VForm>>()
const formPago    = ref({ monto: 0, metodo: 'efectivo', requiereFactura: false })
const opcionesMeses = [1, 2, 3, 4, 5, 6, 9, 12]

// ── Modal ticket ───────────────────────────────────────────────────────────
const modalTicket = ref(false)
const imprimiendo = ref(false)
const ticketData  = ref<{ cobro: Cobro; montoAbono: number; metodo: string; ticket: string | null; fecha: string; pagoId?: string } | null>(null)

// ── Modal pago adelantado (sin necesidad de cobro pendiente) ─────────────────
const modalAdelanto    = ref(false)
const guardandoAdel    = ref(false)
const adelantoFormRef  = ref<InstanceType<typeof VForm>>()
const alumnosActivos   = ref<AlumnoResumen[]>([])
const formAdelanto     = ref({ folio: '', programaId: '', meses: 1, metodo: 'efectivo', requiereFactura: false })

const alumnosOpts = computed(() =>
  alumnosActivos.value.map(a => ({ title: `${a.nombre} · #${a.folio}`, value: a.folio })))

const alumnoAdelanto = computed(() =>
  alumnosActivos.value.find(a => a.folio === formAdelanto.value.folio) ?? null)

// Programas activos del alumno seleccionado (multimodalidad)
const programasAdelanto = computed(() =>
  (alumnoAdelanto.value?.programas ?? []).filter(p => p.status === 'activo'))

const programasOpts = computed(() =>
  programasAdelanto.value.map(p => ({
    title: `${p.modalidad} · ${p.plan.clasesSemana}x/sem · ${fmt(p.montoConDescuento)}`,
    value: p.id,
  })))

const programaAdelanto = computed(() =>
  programasAdelanto.value.find(p => p.id === formAdelanto.value.programaId) ?? null)

const totalAdelanto = computed(() =>
  programaAdelanto.value ? programaAdelanto.value.montoConDescuento * formAdelanto.value.meses : 0)

// Al cambiar de alumno, seleccionar su primer programa por defecto
watch(() => formAdelanto.value.folio, () => {
  formAdelanto.value.programaId = programasAdelanto.value[0]?.id ?? ''
})

async function abrirAdelanto() {
  formAdelanto.value = { folio: '', programaId: '', meses: 1, metodo: 'efectivo', requiereFactura: false }
  modalAdelanto.value = true
  if (!alumnosActivos.value.length) {
    try { alumnosActivos.value = await alumnosApi.listar({ status: 'activo' }) } catch {}
  }
}

async function registrarAdelanto() {
  const { valid } = await adelantoFormRef.value!.validate()
  if (!valid || !programaAdelanto.value) return
  guardandoAdel.value = true
  try {
    const cobro = await cobranzaApi.registrarPagoAdelantado({
      programaId:      formAdelanto.value.programaId,
      meses:           formAdelanto.value.meses,
      metodo:          formAdelanto.value.metodo,
      sucursalId:      sucursalStore.sucursalActivaId,
      requiereFactura: formAdelanto.value.requiereFactura,
    })
    ticketData.value = {
      cobro,
      montoAbono: totalAdelanto.value,
      metodo:     formAdelanto.value.metodo,
      ticket:     (cobro as any).ticket ?? null,
      fecha:      new Date().toISOString().slice(0, 10),
    }
    modalAdelanto.value = false
    modalTicket.value   = true
    exito('Pago adelantado registrado', `Cubre ${formAdelanto.value.meses} ${formAdelanto.value.meses === 1 ? 'mes' : 'meses'}`)
    await cargar()
  } catch (e: any) {
    notifError('Error', e.message)
  } finally {
    guardandoAdel.value = false
  }
}

// ── Carga ──────────────────────────────────────────────────────────────────
async function cargar() {
  cargando.value = true
  try {
    cobros.value = await cobranzaApi.listar()
  } catch {}
  finally { cargando.value = false }
}
onMounted(cargar)


// ── Filtrado ───────────────────────────────────────────────────────────────
const cobrosFiltrados = computed(() => {
  let r = cobros.value
  if (filtroEst.value !== 'todos') r = r.filter(c => c.estado === filtroEst.value)
  if (soloFacturaPendiente.value) r = r.filter(c => c.requiereFactura && !c.facturaRealizada)
  if (busqueda.value.trim()) {
    const q = busqueda.value.toLowerCase()
    r = r.filter(c => c.alumnoNombre.toLowerCase().includes(q) || c.alumnoFolio.includes(q))
  }
  return r
})

// ── Paginación (sobre la lista ya filtrada) ──────────────────────────────────
const totalPaginas = computed(() => Math.max(1, Math.ceil(cobrosFiltrados.value.length / porPagina.value)))

const cobrosPagina = computed(() => {
  const start = (paginaActual.value - 1) * porPagina.value
  return cobrosFiltrados.value.slice(start, start + porPagina.value)
})

const rangoTexto = computed(() => {
  const total = cobrosFiltrados.value.length
  if (!total) return '0'
  const ini = (paginaActual.value - 1) * porPagina.value + 1
  const fin = Math.min(paginaActual.value * porPagina.value, total)
  return `${ini}–${fin} de ${total}`
})

// Al cambiar filtros, búsqueda o tamaño de página, volver a la primera
watch([busqueda, filtroEst, porPagina, soloFacturaPendiente], () => { paginaActual.value = 1 })
// Si la página actual queda fuera de rango, ajustarla
watch(totalPaginas, (t) => { if (paginaActual.value > t) paginaActual.value = t })

// ── Stats ──────────────────────────────────────────────────────────────────
const stats = computed(() => ({
  pendiente: cobros.value.filter(c => c.estado === 'pendiente'),
  parcial:   cobros.value.filter(c => c.estado === 'parcial'),
  pagado:    cobros.value.filter(c => c.estado === 'pagado'),
}))

const totalFacturasPendientes = computed(() =>
  cobros.value.filter(c => c.requiereFactura && !c.facturaRealizada).length
)

const totalPendiente = computed(() =>
  stats.value.pendiente.reduce((s, c) => s + (c.montoNeto - c.montoPagado), 0) +
  stats.value.parcial.reduce((s, c) => s + (c.montoNeto - c.montoPagado), 0)
)

// ── Abrir modal de cobro ──────────────────────────────────────────────────
function abrirCobrar(cobro: Cobro) {
  cobroSel.value = cobro
  const pendiente = Math.max(0, cobro.montoNeto - cobro.montoPagado)
  formPago.value  = { monto: pendiente, metodo: 'efectivo', requiereFactura: cobro.requiereFactura }
  modalPago.value = true
}

// ── Registrar abono ────────────────────────────────────────────────────────
async function registrarAbono() {
  const { valid } = await pagoFormRef.value!.validate()
  if (!valid || !cobroSel.value) return
  guardando.value = true
  try {
    const cobro = await cobranzaApi.registrarAbono(cobroSel.value.id, {
      monto:           formPago.value.monto,
      metodo:          formPago.value.metodo,
      sucursalId:      sucursalStore.sucursalActivaId,
      requiereFactura: formPago.value.requiereFactura,
    })
    ticketData.value = {
      cobro,
      montoAbono: formPago.value.monto,
      metodo:     formPago.value.metodo,
      ticket:     (cobro as any).ticket ?? null,
      fecha:      new Date().toISOString().slice(0, 10),
    }
    modalPago.value  = false
    modalTicket.value = true
    exito('Abono registrado', cobro.estado === 'pagado' ? 'Cobro liquidado' : 'Pago parcial registrado')
    await cargar()
  } catch (e: any) {
    notifError('Error', e.message)
  } finally {
    guardando.value = false
  }
}

// ── No pedir cobro (condonar) ────────────────────────────────────────────────
// El mes se da por cubierto: el alumno deja de deberlo y su vencimiento avanza.
// Por eso se pide motivo obligatorio y se muestra la vista previa del impacto.
const modalCondonar   = ref(false)
const cobroCondonar   = ref<Cobro | null>(null)
const condonando      = ref(false)
const condonarFormRef = ref<InstanceType<typeof VForm>>()
const motivoNoCobro   = ref('')

function abrirCondonar(c: Cobro) {
  cobroCondonar.value  = c
  motivoNoCobro.value  = ''
  modalCondonar.value  = true
}

async function confirmarCondonar() {
  const { valid } = await condonarFormRef.value!.validate()
  if (!valid || !cobroCondonar.value) return
  condonando.value = true
  try {
    await cobranzaApi.condonar(cobroCondonar.value.id, motivoNoCobro.value)
    modalCondonar.value = false
    exito('Cobro marcado como no cobrable', 'El mes queda cubierto y el vencimiento avanza')
    await cargar()
  } catch (e: any) {
    notifError('Error', e.message)
  } finally {
    condonando.value = false
  }
}

// ── Detalle de facturación ───────────────────────────────────────────────────
// Junta el comprobante y los datos fiscales en un solo lugar: es lo que se
// necesita a la mano para emitir la factura.
const modalFactura = ref(false)
const cobroFactura = ref<Cobro | null>(null)

// Captura de datos fiscales sin salir de aquí: si la cajera tiene que irse a la
// ficha del alumno, pierde el hilo de lo que estaba facturando.
const capturandoFiscal = ref(false)
const guardandoFiscal  = ref(false)
const fiscalFormRef    = ref<InstanceType<typeof VForm>>()
const formFiscal       = ref<DatosFiscales>({
  razonSocial: '', rfc: '', regimenFiscal: null, usoCfdi: 'D10', codigoPostal: null, email: null,
})

function abrirFactura(c: Cobro) {
  cobroFactura.value    = c
  capturandoFiscal.value = false
  modalFactura.value    = true
}

async function abrirCapturaFiscal() {
  if (!cobroFactura.value) return
  const c = cobroFactura.value
  // Si ya tenía datos se precargan para corregir, no para volver a teclear
  let previos: DatosFiscales | null = null
  try { previos = await alumnosApi.obtenerDatosFiscales(c.alumnoFolio) } catch { /* sin datos */ }

  formFiscal.value = previos ?? {
    razonSocial: c.alumnoNombre, rfc: '', regimenFiscal: null,
    usoCfdi: 'D10', codigoPostal: null, email: c.alumnoEmail ?? null,
  }
  capturandoFiscal.value = true
}

async function guardarFiscalDesdeCobranza() {
  const { valid } = await fiscalFormRef.value!.validate()
  if (!valid || !cobroFactura.value) return
  guardandoFiscal.value = true
  try {
    await alumnosApi.guardarDatosFiscales(cobroFactura.value.alumnoFolio, formFiscal.value)
    // Re-marcar la factura para que el backend vuelva a congelar la copia con
    // los datos recién capturados: la copia se hace al marcarla, no al guardar.
    const upd = await cobranzaApi.marcarFactura(cobroFactura.value.id, { requiereFactura: true })
    const idx = cobros.value.findIndex(x => x.id === upd.id)
    if (idx >= 0) cobros.value[idx] = upd
    cobroFactura.value     = upd
    capturandoFiscal.value = false
    exito('Datos fiscales guardados', 'Ya quedaron en este comprobante')
  } catch (e: any) {
    notifError('Error', e.message)
  } finally {
    guardandoFiscal.value = false
  }
}

async function copiarRfc() {
  const rfc = cobroFactura.value?.datosFactura?.rfc
  if (!rfc) return
  try {
    await navigator.clipboard.writeText(rfc)
    exito('RFC copiado', rfc)
  } catch {
    notifError('No se pudo copiar', 'Cópialo a mano: ' + rfc)
  }
}

/** Marca o desmarca la factura como emitida. Desmarcar sirve para corregir. */
async function cambiarFacturaRealizada(realizada: boolean) {
  if (!cobroFactura.value) return
  try {
    const upd = await cobranzaApi.marcarFactura(cobroFactura.value.id, { facturaRealizada: realizada })
    const idx = cobros.value.findIndex(x => x.id === upd.id)
    if (idx >= 0) cobros.value[idx] = upd
    cobroFactura.value = upd
    exito(realizada ? 'Factura marcada como realizada' : 'Factura marcada como pendiente')
  } catch (e: any) { notifError('Error', e.message) }
}

// ── Facturación ──────────────────────────────────────────────────────────────
async function toggleRequiereFactura(c: Cobro) {
  try {
    const upd = await cobranzaApi.marcarFactura(c.id, { requiereFactura: !c.requiereFactura })
    const idx = cobros.value.findIndex(x => x.id === c.id)
    if (idx >= 0) cobros.value[idx] = upd
  } catch (e: any) { notifError('Error', e.message) }
}

// ── Ver / reenviar recibo de un pago ya registrado ──────────────────────────
async function verRecibo(cobro: Cobro) {
  try {
    const pagos = await cobranzaApi.pagosDe(cobro.id)
    if (!pagos.length) { notifError('Sin recibos', 'Este cobro no tiene pagos registrados.'); return }
    const p: PagoCobro = pagos[0]   // el más reciente
    ticketData.value = {
      cobro,
      montoAbono: p.monto,
      metodo:     p.metodo,
      ticket:     p.ticket,
      fecha:      p.fecha,
      pagoId:     p.id,
    }
    modalTicket.value = true
  } catch (e: any) {
    notifError('Error', e.message)
  }
}

// ── Helpers ────────────────────────────────────────────────────────────────
const fmt = (n: number) =>
  new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 0 }).format(n)

const fmtPeriodo = (desde: string, hasta: string) => {
  const d = new Date(desde + 'T12:00:00')
  const h = new Date(hasta + 'T12:00:00')
  const opts: Intl.DateTimeFormatOptions = { day: '2-digit', month: 'short' }
  return `${d.toLocaleDateString('es-MX', opts)} – ${h.toLocaleDateString('es-MX', opts)}`
}

const fmtFecha = (f: string) =>
  new Date(f.length === 10 ? f + 'T12:00:00' : f).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' })

// El valor que guarda la BD es el del enum ('credito', 'debito'): sin esto el
// ticket imprime "Credito" a secas, sin acento y sin decir que fue tarjeta.
// Las etiquetas van cortas a propósito: en 48 mm no cabe "Tarjeta crédito".
const fmtMetodo = (m: string) => ({
  efectivo:      'Efectivo',
  transferencia: 'Transferencia',
  credito:       'Crédito',
  debito:        'Débito',
  oxxo:          'OXXO',
}[m] ?? m)

function estadoColor(e: string) {
  if (e === 'pagado')    return 'success'
  if (e === 'parcial')   return 'warning'
  if (e === 'condonado') return 'grey'
  return 'error'
}

const etiquetaEstado = (e: string) => ({
  pagado:    'Pagado',
  parcial:   'Parcial',
  condonado: 'No cobrado',
  pendiente: 'Pendiente',
}[e] ?? e)

// ── Compartir comprobante ──────────────────────────────────────────────────
function textoTicket(d: typeof ticketData.value): string {
  if (!d) return ''
  const concepto  = d.cobro.concepto === 'inscripcion' ? 'Inscripción' : 'Mensualidad'
  const periodo   = d.cobro.concepto === 'inscripcion' ? 'Pago único' : fmtPeriodo(d.cobro.periodoDesde, d.cobro.periodoHasta)
  const pendiente = d.cobro.montoNeto - d.cobro.montoPagado
  return [
    `*Recibo de pago — Acuática Beluga*`,
    ``,
    `Alumno: ${d.cobro.alumnoNombre}`,
    `Folio:  #${d.cobro.alumnoFolio}`,
    `Recibo: ${d.ticket ?? '—'}`,
    `Fecha:  ${fmtFecha(d.fecha)}`,
    ``,
    `Concepto: ${concepto}`,
    `Periodo:  ${periodo}`,
    `Método:   ${d.metodo}`,
    `*Abono:   ${fmt(d.montoAbono)}*`,
    pendiente > 0 ? `Pendiente: ${fmt(pendiente)}` : `_Cobro liquidado_`,
    ``,
    `¡Nadar es vida!`,
  ].filter(l => l !== null).join('\n')
}

function compartirWhatsApp() {
  if (!ticketData.value) return
  const telefono = ticketData.value.cobro.alumnoTelefono?.replace(/[\s\-\(\)\+]/g, '') ?? ''
  const texto    = encodeURIComponent(textoTicket(ticketData.value))
  const url      = telefono
    ? `https://wa.me/52${telefono}?text=${texto}`
    : `https://wa.me/?text=${texto}`
  window.open(url, '_blank')
}

// ── Impresión del comprobante ──────────────────────────────────────────────
// Por el diálogo del navegador, con el formato de 58 mm: se clona el
// comprobante que está en pantalla y se manda a imprimir (ver utils/ticket58.ts).
//
// No hay impresión silenciosa. El sistema corre en el servidor y la miniprinter
// cuelga del USB de la caja, así que para mandarle texto ESC/POS haría falta un
// programa instalado en cada equipo que imprima. Se evaluó y se descartó: no
// compensa el costo de instalación. El navegador manda el comprobante como
// imagen —sale algo más suave que el texto nativo de la impresora— pero funciona
// en cualquier computadora sin instalar nada.
async function imprimir() {
  if (!ticketData.value) return
  const el = document.getElementById('ticket-print')
  if (!el) {
    notifError('No se pudo imprimir', 'No se encontró el comprobante en pantalla.')
    return
  }
  imprimiendo.value = true
  try {
    await imprimirComprobante(el, `Recibo ${ticketData.value.ticket ?? ''}`.trim())
  } catch (e: any) {
    notifError('No se pudo imprimir', e.message)
  } finally {
    imprimiendo.value = false
  }
}

// Genera el comprobante como imagen PNG. En móvil/tablet lo comparte por el menú
// nativo (WhatsApp incluido); en laptop lo descarga para adjuntarlo en WhatsApp Web.
async function comprobanteImagen() {
  const el = document.getElementById('ticket-print')
  if (!el || !ticketData.value) return
  try {
    // Medidas explícitas y sin margen: html-to-image dimensiona el lienzo con
    // offsetWidth (que redondea hacia abajo y excluye márgenes), así que sin
    // esto el comprobante sale recortado del lado derecho.
    const caja    = el.getBoundingClientRect()
    const dataUrl = await toPng(el, {
      pixelRatio:      2,
      backgroundColor: '#ffffff',
      width:           Math.ceil(caja.width),
      height:          Math.ceil(caja.height),
      style:           { margin: '0' },
    })
    const nombre  = `comprobante-${ticketData.value.ticket ?? 's-n'}-${ticketData.value.cobro.alumnoFolio}.png`
    const blob    = await (await fetch(dataUrl)).blob()
    const file    = new File([blob], nombre, { type: 'image/png' })
    const nav     = navigator as any

    if (nav.canShare && nav.canShare({ files: [file] })) {
      await nav.share({ files: [file], title: 'Comprobante Acuática Beluga' })
      return
    }
    const a = document.createElement('a')
    a.href = dataUrl
    a.download = nombre
    a.click()
  } catch (e: any) {
    notifError('No se pudo generar la imagen', e.message)
  }
}

const pendienteAbono = computed(() => {
  if (!cobroSel.value) return 0
  return Math.max(0, cobroSel.value.montoNeto - cobroSel.value.montoPagado)
})

const excedente = computed(() =>
  Math.max(0, formPago.value.monto - pendienteAbono.value)
)
</script>

<template>
  <div class="d-flex flex-column ga-4">

    <!-- Stats ───────────────────────────────────────────────────────────── -->
    <v-row dense>
      <v-col cols="6" md="3">
        <v-card rounded="xl" border
          :style="{ cursor:'pointer', outline: filtroEst==='pendiente' ? '2px solid #dc2626' : 'none' }"
          @click="filtroEst = filtroEst === 'pendiente' ? 'todos' : 'pendiente'">
          <v-card-text class="pa-4">
            <div class="stat-label">Pendientes</div>
            <div class="stat-num" style="color:#dc2626">{{ cargando ? '—' : stats.pendiente.length }}</div>
            <div class="stat-sub" style="color:#dc2626">{{ cargando ? '' : fmt(totalPendiente) }} por cobrar</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card rounded="xl" border
          :style="{ cursor:'pointer', outline: filtroEst==='parcial' ? '2px solid #d97706' : 'none' }"
          @click="filtroEst = filtroEst === 'parcial' ? 'todos' : 'parcial'">
          <v-card-text class="pa-4">
            <div class="stat-label">Parciales</div>
            <div class="stat-num" style="color:#d97706">{{ cargando ? '—' : stats.parcial.length }}</div>
            <div class="stat-sub">Con abono parcial</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card rounded="xl" border
          :style="{ cursor:'pointer', outline: filtroEst==='pagado' ? '2px solid #15803d' : 'none' }"
          @click="filtroEst = filtroEst === 'pagado' ? 'todos' : 'pagado'">
          <v-card-text class="pa-4">
            <div class="stat-label">Liquidados</div>
            <div class="stat-num" style="color:#15803d">{{ cargando ? '—' : stats.pagado.length }}</div>
            <div class="stat-sub">Este ciclo</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card rounded="xl" border>
          <v-card-text class="pa-4">
            <div class="stat-label">Total cobros</div>
            <div class="stat-num" style="color:#0a1e6e">{{ cargando ? '—' : cobros.length }}</div>
            <div class="stat-sub">Generados</div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- Toolbar ─────────────────────────────────────────────────────────── -->
    <v-card rounded="xl" border>
      <v-card-text class="pa-3">
        <div class="d-flex flex-wrap align-center ga-3">
          <v-text-field
            v-model="busqueda"
            placeholder="Nombre o folio…"
            prepend-inner-icon="mdi-magnify"
            density="compact"
            rounded="lg"
            variant="outlined"
            hide-details
            style="max-width:280px"
            clearable
          />
          <div class="d-flex ga-2 flex-wrap">
            <v-chip
              v-for="f in [
                { val:'todos',     label:'Todos' },
                { val:'pendiente', label:'Pendiente' },
                { val:'parcial',   label:'Parcial' },
                { val:'pagado',    label:'Pagado' },
                { val:'condonado', label:'No cobrados' },
              ]" :key="f.val"
              :color="filtroEst === f.val ? 'primary' : undefined"
              :variant="filtroEst === f.val ? 'flat' : 'outlined'"
              size="small"
              style="cursor:pointer"
              @click="filtroEst = f.val as any"
            >{{ f.label }}</v-chip>
          </div>

          <v-chip
            :color="soloFacturaPendiente ? 'warning' : undefined"
            :variant="soloFacturaPendiente ? 'flat' : 'outlined'"
            size="small" style="cursor:pointer"
            prepend-icon="mdi-file-clock-outline"
            @click="soloFacturaPendiente = !soloFacturaPendiente"
          >
            Facturas pendientes
            <v-chip v-if="totalFacturasPendientes" size="x-small" class="ml-1" color="warning" variant="flat">
              {{ totalFacturasPendientes }}
            </v-chip>
          </v-chip>

          <v-spacer />
          <v-btn
            variant="outlined" rounded="lg" size="small" color="primary"
            prepend-icon="mdi-calculator-variant-outline"
            @click="generarCobrosAhora"
          >Calcular cobros</v-btn>
          <v-btn
            color="primary" variant="flat" rounded="lg" size="small"
            prepend-icon="mdi-cash-fast"
            @click="abrirAdelanto"
          >Pago adelantado</v-btn>
          <span style="font-size:12px;color:#9ca3af">{{ cobrosFiltrados.length }} cobro{{ cobrosFiltrados.length !== 1 ? 's' : '' }}</span>
        </div>
      </v-card-text>
    </v-card>

    <!-- Tabla ───────────────────────────────────────────────────────────── -->
    <v-card rounded="xl" border>
      <v-table density="comfortable" hover>
        <thead>
          <tr>
            <th class="col-header">Alumno</th>
            <th class="col-header">Periodo</th>
            <th class="col-header">Monto base</th>
            <th class="col-header">S. favor aplic.</th>
            <th class="col-header">Neto</th>
            <th class="col-header">Pagado</th>
            <th class="col-header">Pendiente</th>
            <th class="col-header">Estado</th>
            <th class="col-header">Factura</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="cargando">
            <td colspan="10" class="text-center py-12">
              <v-progress-circular indeterminate color="primary" size="32" />
            </td>
          </tr>
          <tr v-else-if="!cobrosFiltrados.length">
            <td colspan="10" class="text-center py-12 text-medium-emphasis" style="font-size:14px">
              Sin cobros{{ filtroEst !== 'todos' ? ' ' + filtroEst + 's' : '' }}
            </td>
          </tr>
          <tr v-for="c in cobrosPagina" :key="c.id">
            <td>
              <div class="d-flex align-center ga-2">
                <span style="font-weight:600;color:#0a1e6e;font-size:13px">{{ c.alumnoNombre }}</span>
                <v-chip
                  size="x-small" label variant="tonal"
                  :color="c.concepto === 'inscripcion' ? 'deep-purple' : 'primary'"
                >{{ c.concepto === 'inscripcion' ? 'Inscripción' : 'Mensualidad' }}</v-chip>
                <v-chip
                  v-if="c.programaModalidad"
                  size="x-small" label variant="flat" color="grey-lighten-3"
                  style="font-weight:700;color:#374151"
                >{{ c.programaModalidad }}</v-chip>
              </div>
              <div style="font-size:11px;color:#9ca3af">
                #{{ c.alumnoFolio }}
                <span v-if="c.alumnoSaldoFavor > 0" style="color:#15803d;font-weight:600;margin-left:4px">
                  SF {{ fmt(c.alumnoSaldoFavor) }}
                </span>
              </div>
            </td>
            <td style="font-size:12px;color:#6b7280;white-space:nowrap">
              {{ c.concepto === 'inscripcion' ? 'Pago único' : fmtPeriodo(c.periodoDesde, c.periodoHasta) }}
            </td>
            <td style="font-size:13px;color:#374151">{{ fmt(c.montoBase) }}</td>
            <td>
              <span v-if="c.saldoFavorAplicado > 0" style="font-size:13px;color:#15803d;font-weight:600">
                −{{ fmt(c.saldoFavorAplicado) }}
              </span>
              <span v-else style="font-size:12px;color:#d1d5db">—</span>
            </td>
            <td style="font-size:14px;font-weight:700;color:#0a1e6e">{{ fmt(c.montoNeto) }}</td>
            <td style="font-size:13px;color:#15803d;font-weight:600">
              {{ c.montoPagado > 0 ? fmt(c.montoPagado) : '—' }}
            </td>
            <td>
              <span v-if="c.estado === 'condonado'" style="font-size:12px;color:#9ca3af">—</span>
              <span v-else-if="c.estado !== 'pagado'" style="font-size:13px;font-weight:700;color:#dc2626">
                {{ fmt(c.montoNeto - c.montoPagado) }}
              </span>
              <v-icon v-else icon="mdi-check-circle" color="success" size="18" />
            </td>
            <td>
              <v-chip
                :color="estadoColor(c.estado)" size="small" variant="tonal" label
                :title="c.estado === 'condonado' ? c.motivoNoCobro ?? '' : undefined"
              >
                {{ etiquetaEstado(c.estado) }}
              </v-chip>
              <div
                v-if="c.estado === 'condonado' && c.motivoNoCobro"
                style="font-size:10.5px;color:#9ca3af;max-width:140px;line-height:1.3;margin-top:2px"
              >{{ c.motivoNoCobro }}</div>
            </td>
            <td>
              <!-- Abre el detalle de facturación en vez de solo alternar: ahí está
                   el comprobante junto con el RFC, que es lo que se necesita para
                   emitir la factura sin andar buscando en dos pantallas. -->
              <v-chip
                v-if="c.requiereFactura"
                size="small" variant="tonal" label
                :color="c.facturaRealizada ? 'success' : (c.datosFactura ? 'warning' : 'error')"
                style="cursor:pointer"
                :title="c.datosFactura ? 'Ver datos de facturación' : 'Faltan los datos fiscales del alumno'"
                @click.stop="abrirFactura(c)"
              >
                <v-icon
                  start size="14"
                  :icon="c.facturaRealizada ? 'mdi-file-check-outline' : (c.datosFactura ? 'mdi-file-clock-outline' : 'mdi-file-alert-outline')"
                />
                {{ c.facturaRealizada ? 'Facturada' : (c.datosFactura ? 'Pendiente' : 'Sin datos') }}
              </v-chip>
              <span
                v-else
                style="font-size:11px;color:#cbd5e1;cursor:pointer"
                title="Marcar que requiere factura"
                @click.stop="toggleRequiereFactura(c)"
              >No req.</span>
            </td>
            <td>
              <div class="d-flex align-center justify-end ga-1">
                <v-btn
                  v-if="c.montoPagado > 0"
                  icon="mdi-receipt-text-outline" size="small" variant="text" color="primary"
                  title="Ver / reenviar recibo"
                  @click.stop="verRecibo(c)"
                />
                <v-btn
                  v-if="c.estado !== 'pagado' && c.estado !== 'condonado' && c.montoPagado === 0"
                  icon="mdi-cash-remove" size="small" variant="text" color="grey"
                  title="No pedir cobro"
                  @click.stop="abrirCondonar(c)"
                />
                <v-btn
                  v-if="c.estado !== 'pagado' && c.estado !== 'condonado'"
                  size="small"
                  rounded="lg"
                  style="background:#2fe6b0;color:#0a1e6e;font-weight:700"
                  @click.stop="abrirCobrar(c)"
                >Cobrar</v-btn>
              </div>
            </td>
          </tr>
        </tbody>
      </v-table>

      <!-- Pie de paginación -->
      <div v-if="!cargando && cobrosFiltrados.length"
        class="d-flex flex-wrap align-center justify-space-between ga-3 pa-3"
        style="border-top:1px solid #f1f5f9">
        <div class="d-flex align-center ga-2">
          <span style="font-size:12px;color:#6b7280">Por página</span>
          <v-select
            v-model="porPagina"
            :items="opcionesPorPagina"
            density="compact" variant="outlined" rounded="lg" hide-details
            style="max-width:100px"
          />
        </div>
        <span style="font-size:12px;color:#9ca3af">{{ rangoTexto }}</span>
        <v-pagination
          v-model="paginaActual"
          :length="totalPaginas"
          :total-visible="5"
          density="compact"
          rounded="lg"
          active-color="primary"
        />
      </div>
    </v-card>

    <!-- ── Modal: Registrar abono ──────────────────────────────────────────── -->
    <v-dialog v-model="modalPago" max-width="500" persistent>
      <v-card v-if="cobroSel" rounded="xl">
        <!-- Header -->
        <div style="background:linear-gradient(135deg,#e8f4ff,#dbeafe);padding:20px 24px 16px;border-radius:16px 16px 0 0;border-bottom:1px solid #b8d4ff">
          <div style="color:#1a3fe8;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin-bottom:2px">Registrar cobro</div>
          <div style="color:#0a1e6e;font-size:18px;font-weight:700;font-family:'Fraunces',serif">{{ cobroSel.alumnoNombre }}</div>
          <div style="color:#6b7280;font-size:12px;margin-top:2px">
            Folio #{{ cobroSel.alumnoFolio }} ·
            {{ cobroSel.concepto === 'inscripcion' ? 'Inscripción (pago único)' : fmtPeriodo(cobroSel.periodoDesde, cobroSel.periodoHasta) }}
          </div>
        </div>

        <!-- Resumen del cobro -->
        <div class="pa-5 pb-2">
          <div class="cobro-grid">
            <div class="cobro-item">
              <span class="cobro-lbl">Monto base</span>
              <span class="cobro-val">{{ fmt(cobroSel.montoBase) }}</span>
            </div>
            <div v-if="cobroSel.saldoFavorAplicado > 0" class="cobro-item">
              <span class="cobro-lbl">Saldo favor aplicado</span>
              <span class="cobro-val" style="color:#15803d">−{{ fmt(cobroSel.saldoFavorAplicado) }}</span>
            </div>
            <div class="cobro-item">
              <span class="cobro-lbl">Monto neto</span>
              <span class="cobro-val" style="font-weight:800;color:#0a1e6e">{{ fmt(cobroSel.montoNeto) }}</span>
            </div>
            <div v-if="cobroSel.montoPagado > 0" class="cobro-item">
              <span class="cobro-lbl">Ya abonado</span>
              <span class="cobro-val" style="color:#15803d">{{ fmt(cobroSel.montoPagado) }}</span>
            </div>
            <div class="cobro-item" style="border-top:1px dashed #e5e7eb;margin-top:4px;padding-top:6px">
              <span class="cobro-lbl" style="font-weight:700;color:#dc2626">Por pagar</span>
              <span class="cobro-val" style="font-weight:800;font-size:17px;color:#dc2626">{{ fmt(pendienteAbono) }}</span>
            </div>
          </div>

          <v-alert
            v-if="cobroSel.alumnoSaldoFavor > 0"
            density="compact"
            variant="tonal"
            color="success"
            rounded="lg"
            class="mt-3"
            icon="mdi-wallet"
          >
            Alumno tiene <strong>{{ fmt(cobroSel.alumnoSaldoFavor) }}</strong> en saldo a favor (aplicado automáticamente en el próximo ciclo)
          </v-alert>
        </div>

        <v-form ref="pagoFormRef">
          <v-card-text class="pa-5 pt-2">
            <v-row dense>
              <v-col cols="12">
                <v-text-field
                  v-model.number="formPago.monto"
                  label="Monto a cobrar *"
                  type="number"
                  min="1"
                  prefix="$"
                  density="compact"
                  variant="outlined"
                  rounded="lg"
                  :rules="[v => !!v && v > 0 || 'Ingresa un monto mayor a $0']"
                  :hint="excedente > 0 ? `${fmt(excedente)} se acumulará como saldo a favor` : `Puedes cobrar parcialmente`"
                  persistent-hint
                />
              </v-col>

              <v-col cols="12" class="mt-2">
                <div class="section-label mb-2">Método de pago</div>
                <div class="d-flex ga-2 flex-wrap">
                  <v-chip
                    v-for="m in METODOS_PAGO" :key="m.value"
                    :color="formPago.metodo === m.value ? 'primary' : undefined"
                    :variant="formPago.metodo === m.value ? 'flat' : 'outlined'"
                    size="small"
                    style="cursor:pointer"
                    @click="formPago.metodo = m.value"
                  >{{ m.label }}</v-chip>
                </div>
              </v-col>

              <v-col cols="12" class="mt-1">
                <v-checkbox
                  v-model="formPago.requiereFactura"
                  label="Requiere factura"
                  density="compact" color="primary" hide-details
                />
                <!-- No se bloquea el cobro por un trámite: se avisa y se resuelve
                     después desde el chip de Factura, que lleva a la captura. -->
                <v-alert
                  v-if="formPago.requiereFactura && !cobroSel.alumnoTieneDatosFiscales"
                  type="warning" variant="tonal" density="compact" rounded="lg" class="mt-2"
                >
                  <div style="font-size:12px;line-height:1.45">
                    Este alumno no tiene datos fiscales. Se cobra igual y el
                    comprobante quedará marcado <strong>Sin datos</strong>: los
                    capturas desde ahí cuando puedas.
                  </div>
                </v-alert>
              </v-col>
            </v-row>
          </v-card-text>
        </v-form>

        <v-divider />
        <v-card-actions class="pa-4 ga-2">
          <v-btn variant="text" rounded="lg" @click="modalPago = false">Cancelar</v-btn>
          <v-spacer />
          <v-btn
            rounded="lg"
            style="background:#0a1e6e;color:#2fe6b0;font-weight:700;min-width:160px"
            :loading="guardando"
            @click="registrarAbono"
          >
            <v-icon start icon="mdi-check" />
            Cobrar {{ fmt(formPago.monto || 0) }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- ── Modal: Pago adelantado ─────────────────────────────────────────── -->
    <v-dialog v-model="modalAdelanto" max-width="500" persistent>
      <v-card rounded="xl">
        <div style="background:linear-gradient(135deg,#e8f4ff,#dbeafe);padding:20px 24px 16px;border-radius:16px 16px 0 0;border-bottom:1px solid #b8d4ff">
          <div style="color:#1a3fe8;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin-bottom:2px">Pago adelantado</div>
          <div style="color:#0a1e6e;font-size:18px;font-weight:700;font-family:'Fraunces',serif">Registrar meses por adelantado</div>
          <div style="color:#6b7280;font-size:12px;margin-top:2px">Aplica aunque el alumno no tenga cobros pendientes.</div>
        </div>

        <v-form ref="adelantoFormRef">
          <v-card-text class="pa-5">
            <v-row dense>
              <v-col cols="12">
                <v-autocomplete
                  v-model="formAdelanto.folio"
                  :items="alumnosOpts"
                  label="Alumno *"
                  density="compact" variant="outlined" rounded="lg"
                  prepend-inner-icon="mdi-account-search"
                  no-data-text="Sin alumnos activos"
                  :rules="[v => !!v || 'Selecciona un alumno']"
                />
              </v-col>
              <v-col v-if="alumnoAdelanto" cols="12">
                <v-select
                  v-model="formAdelanto.programaId"
                  :items="programasOpts"
                  label="Programa / modalidad *"
                  density="compact" variant="outlined" rounded="lg"
                  prepend-inner-icon="mdi-swim"
                  no-data-text="El alumno no tiene programas activos"
                  :rules="[v => !!v || 'Selecciona un programa']"
                />
              </v-col>
              <v-col cols="12" sm="6">
                <v-select
                  v-model.number="formAdelanto.meses"
                  :items="opcionesMeses"
                  label="Meses a pagar *"
                  density="compact" variant="outlined" rounded="lg"
                  prepend-inner-icon="mdi-calendar-multiple"
                />
              </v-col>
              <v-col cols="12" sm="6" class="d-flex align-center">
                <div v-if="programaAdelanto" style="font-size:13px;color:#6b7280">
                  Mensualidad <strong style="color:#0a1e6e">{{ fmt(programaAdelanto.montoConDescuento) }}</strong>
                </div>
              </v-col>

              <v-col cols="12" class="mt-1">
                <div class="section-label mb-2">Método de pago</div>
                <div class="d-flex ga-2 flex-wrap">
                  <v-chip
                    v-for="m in METODOS_PAGO" :key="m.value"
                    :color="formAdelanto.metodo === m.value ? 'primary' : undefined"
                    :variant="formAdelanto.metodo === m.value ? 'flat' : 'outlined'"
                    size="small" style="cursor:pointer"
                    @click="formAdelanto.metodo = m.value"
                  >{{ m.label }}</v-chip>
                </div>
              </v-col>

              <v-col cols="12" class="mt-1">
                <v-checkbox
                  v-model="formAdelanto.requiereFactura"
                  label="Requiere factura"
                  density="compact" color="primary" hide-details
                />
              </v-col>

              <v-col cols="12">
                <div class="cobro-item" style="border-top:1px dashed #e5e7eb;margin-top:4px;padding-top:10px">
                  <span class="cobro-lbl" style="font-weight:700;color:#0a1e6e">
                    Total ({{ formAdelanto.meses }} {{ formAdelanto.meses === 1 ? 'mes' : 'meses' }})
                  </span>
                  <span class="cobro-val" style="font-weight:800;font-size:18px;color:#0a1e6e">{{ fmt(totalAdelanto) }}</span>
                </div>
              </v-col>
            </v-row>
          </v-card-text>
        </v-form>

        <v-divider />
        <v-card-actions class="pa-4 ga-2">
          <v-btn variant="text" rounded="lg" @click="modalAdelanto = false">Cancelar</v-btn>
          <v-spacer />
          <v-btn
            rounded="lg"
            style="background:#0a1e6e;color:#2fe6b0;font-weight:700;min-width:160px"
            :loading="guardandoAdel"
            :disabled="!formAdelanto.programaId"
            @click="registrarAdelanto"
          >
            <v-icon start icon="mdi-cash-fast" />
            Cobrar {{ fmt(totalAdelanto) }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- ── Modal: No pedir cobro ──────────────────────────────────────────── -->
    <v-dialog v-model="modalCondonar" max-width="480">
      <v-card v-if="cobroCondonar" rounded="xl">
        <v-card-title class="pa-5 pb-2">
          <div style="color:#0a1e6e;font-size:18px;font-weight:700;font-family:'Fraunces',serif">
            No pedir este cobro
          </div>
          <div style="font-size:13px;color:#6b7280;font-weight:400">
            {{ cobroCondonar.alumnoNombre }} · #{{ cobroCondonar.alumnoFolio }}
          </div>
        </v-card-title>
        <v-divider />

        <v-form ref="condonarFormRef" @submit.prevent="confirmarCondonar">
          <v-card-text class="pa-5">
            <!-- Vista previa del impacto: la acción cambia el ciclo de pago del
                 alumno, no solo oculta una fila -->
            <v-alert type="warning" variant="tonal" density="compact" rounded="lg" class="mb-4">
              <div style="font-size:12.5px;line-height:1.5">
                Se dará por cubierto el periodo
                <strong>{{ fmtPeriodo(cobroCondonar.periodoDesde, cobroCondonar.periodoHasta) }}</strong>
                por <strong>{{ fmt(cobroCondonar.montoNeto) }}</strong>.
                El alumno dejará de deberlo y su vencimiento avanzará al siguiente mes.
              </div>
            </v-alert>

            <v-textarea
              v-model="motivoNoCobro"
              label="¿Por qué no se cobra? *"
              variant="outlined"
              density="compact"
              rounded="lg"
              rows="3"
              auto-grow
              counter="255"
              placeholder="Beca, cortesía por inhábiles, error de captura…"
              :rules="[
                v => !!v?.trim() || 'El motivo es requerido',
                v => (v?.trim().length ?? 0) >= 5 || 'Explica brevemente el motivo',
                v => (v?.length ?? 0) <= 255 || 'Máximo 255 caracteres',
              ]"
            />
          </v-card-text>

          <v-divider />
          <v-card-actions class="pa-4 ga-2">
            <v-btn variant="text" rounded="lg" @click="modalCondonar = false">Cancelar</v-btn>
            <v-spacer />
            <v-btn
              type="submit"
              rounded="lg"
              style="background:#0a1e6e;color:#2fe6b0;font-weight:700;min-width:150px"
              :loading="condonando"
            >
              <v-icon start icon="mdi-cash-remove" />
              No cobrar
            </v-btn>
          </v-card-actions>
        </v-form>
      </v-card>
    </v-dialog>

    <!-- ── Modal: Datos de facturación ────────────────────────────────────── -->
    <v-dialog v-model="modalFactura" max-width="520">
      <v-card v-if="cobroFactura" rounded="xl">
        <v-card-title class="pa-5 pb-2">
          <div style="color:#0a1e6e;font-size:18px;font-weight:700;font-family:'Fraunces',serif">
            Datos para facturar
          </div>
          <div style="font-size:13px;color:#6b7280;font-weight:400">
            {{ cobroFactura.alumnoNombre }} · #{{ cobroFactura.alumnoFolio }}
          </div>
        </v-card-title>
        <v-divider />

        <v-card-text class="pa-5">
          <div class="section-label mb-2">Comprobante</div>
          <div class="cobro-grid mb-5">
            <div class="cobro-item">
              <span class="cobro-lbl">Concepto</span>
              <span class="cobro-val">{{ cobroFactura.concepto === 'inscripcion' ? 'Inscripción' : 'Mensualidad' }}</span>
            </div>
            <div class="cobro-item">
              <span class="cobro-lbl">Periodo</span>
              <span class="cobro-val">{{ fmtPeriodo(cobroFactura.periodoDesde, cobroFactura.periodoHasta) }}</span>
            </div>
            <div class="cobro-item">
              <span class="cobro-lbl">Importe</span>
              <span class="cobro-val" style="color:#0a1e6e;font-weight:700">{{ fmt(cobroFactura.montoNeto) }}</span>
            </div>
            <div class="cobro-item">
              <span class="cobro-lbl">Pagado</span>
              <span class="cobro-val" style="color:#15803d">{{ fmt(cobroFactura.montoPagado) }}</span>
            </div>
          </div>

          <div class="section-label mb-2">Datos fiscales</div>

          <!-- Captura en línea: aquí es donde se nota que faltan -->
          <v-form v-if="capturandoFiscal" ref="fiscalFormRef" @submit.prevent="guardarFiscalDesdeCobranza">
            <v-row dense>
              <v-col cols="12" md="7">
                <v-text-field
                  v-model="formFiscal.razonSocial"
                  label="Razón social *"
                  variant="outlined" density="compact" rounded="lg"
                  :rules="[v => !!v?.trim() || 'La razón social es requerida']"
                />
              </v-col>
              <v-col cols="12" md="5">
                <v-text-field
                  v-model="formFiscal.rfc"
                  label="RFC *"
                  variant="outlined" density="compact" rounded="lg"
                  style="text-transform:uppercase"
                  :rules="[
                    v => !!v?.trim() || 'El RFC es requerido',
                    v => /^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/.test((v ?? '').toUpperCase()) || 'RFC inválido (12 o 13 caracteres)',
                  ]"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-select
                  v-model="formFiscal.regimenFiscal"
                  :items="REGIMENES_FISCALES" item-title="label" item-value="value"
                  label="Régimen fiscal"
                  variant="outlined" density="compact" rounded="lg" clearable
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-select
                  v-model="formFiscal.usoCfdi"
                  :items="USOS_CFDI" item-title="label" item-value="value"
                  label="Uso de CFDI"
                  variant="outlined" density="compact" rounded="lg" clearable
                />
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field
                  v-model="formFiscal.codigoPostal"
                  label="Código postal"
                  variant="outlined" density="compact" rounded="lg" maxlength="5"
                  :rules="[v => !v || /^\d{5}$/.test(v) || 'Deben ser 5 dígitos']"
                />
              </v-col>
              <v-col cols="12" md="8">
                <v-text-field
                  v-model="formFiscal.email"
                  label="Correo para enviar la factura"
                  variant="outlined" density="compact" rounded="lg"
                  :rules="[v => !v || /.+@.+\..+/.test(v) || 'Correo inválido']"
                />
              </v-col>
            </v-row>
            <div class="d-flex justify-end ga-2 mt-1">
              <v-btn variant="text" size="small" rounded="lg" @click="capturandoFiscal = false">Cancelar</v-btn>
              <v-btn
                type="submit" size="small" rounded="lg"
                style="background:#0a1e6e;color:#2fe6b0;font-weight:700"
                :loading="guardandoFiscal"
              >Guardar datos</v-btn>
            </div>
          </v-form>

          <v-alert
            v-else-if="!cobroFactura.datosFactura"
            type="error" variant="tonal" density="compact" rounded="lg"
          >
            <div style="font-size:12.5px;line-height:1.5">
              Este alumno no tiene datos fiscales capturados.
            </div>
            <v-btn
              size="small" variant="flat" color="error" rounded="lg" class="mt-2"
              prepend-icon="mdi-plus"
              @click="abrirCapturaFiscal"
            >Capturarlos ahora</v-btn>
          </v-alert>

          <div v-else class="cobro-grid">
            <div class="cobro-item">
              <span class="cobro-lbl">Razón social</span>
              <span class="cobro-val">{{ cobroFactura.datosFactura.razonSocial }}</span>
            </div>
            <div class="cobro-item">
              <span class="cobro-lbl">RFC</span>
              <span class="d-flex align-center ga-1">
                <span class="cobro-val" style="font-family:monospace;font-weight:700;color:#1a3fe8">
                  {{ cobroFactura.datosFactura.rfc }}
                </span>
                <v-btn icon="mdi-content-copy" size="x-small" variant="text" title="Copiar RFC" @click="copiarRfc" />
              </span>
            </div>
            <div v-if="cobroFactura.datosFactura.regimenFiscal" class="cobro-item">
              <span class="cobro-lbl">Régimen fiscal</span>
              <span class="cobro-val">{{ cobroFactura.datosFactura.regimenFiscal }}</span>
            </div>
            <div v-if="cobroFactura.datosFactura.usoCfdi" class="cobro-item">
              <span class="cobro-lbl">Uso de CFDI</span>
              <span class="cobro-val">{{ cobroFactura.datosFactura.usoCfdi }}</span>
            </div>
            <div v-if="cobroFactura.datosFactura.codigoPostal" class="cobro-item">
              <span class="cobro-lbl">Código postal</span>
              <span class="cobro-val">{{ cobroFactura.datosFactura.codigoPostal }}</span>
            </div>
            <div v-if="cobroFactura.datosFactura.email" class="cobro-item">
              <span class="cobro-lbl">Correo</span>
              <span class="cobro-val">{{ cobroFactura.datosFactura.email }}</span>
            </div>
            <div class="d-flex justify-end mt-1">
              <v-btn
                size="x-small" variant="text" color="primary"
                prepend-icon="mdi-pencil-outline"
                @click="abrirCapturaFiscal"
              >Corregir datos</v-btn>
            </div>
          </div>
        </v-card-text>

        <v-divider />
        <v-card-actions class="pa-4 ga-2">
          <v-btn variant="text" rounded="lg" @click="modalFactura = false">Cerrar</v-btn>
          <v-spacer />
          <v-btn
            v-if="!cobroFactura.facturaRealizada"
            rounded="lg"
            style="background:#0a1e6e;color:#2fe6b0;font-weight:700"
            prepend-icon="mdi-file-check-outline"
            @click="cambiarFacturaRealizada(true)"
          >Marcar facturada</v-btn>
          <template v-else>
            <v-btn variant="text" size="small" rounded="lg" @click="cambiarFacturaRealizada(false)">
              Desmarcar
            </v-btn>
            <v-chip color="success" variant="tonal" label>
              <v-icon start icon="mdi-check-circle" size="16" /> Ya facturada
            </v-chip>
          </template>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- ── Modal: Ticket ──────────────────────────────────────────────────── -->
    <v-dialog v-model="modalTicket" max-width="400">
      <v-card v-if="ticketData" rounded="xl">
        <div class="text-center pa-6 pb-3">
          <div style="width:56px;height:56px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;margin:0 auto 10px">
            <v-icon icon="mdi-check-circle" color="success" size="32" />
          </div>
          <div style="font-size:17px;font-weight:700;color:#0a1e6e;font-family:'Fraunces',serif">
            {{ ticketData.cobro.estado === 'pagado' ? 'Cobro liquidado' : 'Abono registrado' }}
          </div>
        </div>

        <!-- Las márgenes van en el envoltorio, NO en #ticket-print: al capturarlo
             como imagen o clonarlo para imprimir, un margen propio desplaza el
             contenido y recorta el lado derecho. -->
        <div class="mx-5 mb-5">
          <div class="ticket-body" id="ticket-print">
            <div class="ticket-header">
              <div style="font-size:13px;font-weight:800;color:#0a1e6e;letter-spacing:.05em">ACUÁTICA BELUGA</div>
              <div style="font-size:10px;color:#6b7280;margin-top:2px">{{ sucursalStore.sucursalActiva?.nombre ?? 'Morelia, Michoacán' }}</div>
              <div style="font-size:9.5px;color:#9ca3af;font-style:italic;margin-top:1px">Nadar es vida</div>
            </div>
            <div class="ticket-divider" />

            <div class="ticket-row">
              <span>Recibo</span>
              <span style="font-family:monospace;font-weight:700;color:#1a3fe8">{{ ticketData.ticket ?? '—' }}</span>
            </div>
            <div class="ticket-row">
              <span>Fecha</span>
              <span>{{ fmtFecha(ticketData.fecha) }}</span>
            </div>
            <div v-if="auth.usuario?.nombre" class="ticket-row">
              <span>Atendió</span>
              <span>{{ auth.usuario.nombre }}</span>
            </div>
            <div class="ticket-divider" />

            <div class="ticket-row">
              <span>Alumno</span>
              <span style="font-weight:600;color:#0a1e6e">{{ ticketData.cobro.alumnoNombre }}</span>
            </div>
            <div class="ticket-row">
              <span>Folio</span>
              <span>#{{ ticketData.cobro.alumnoFolio }}</span>
            </div>
            <div class="ticket-row">
              <span>Periodo</span>
              <span>{{ fmtPeriodo(ticketData.cobro.periodoDesde, ticketData.cobro.periodoHasta) }}</span>
            </div>
            <div class="ticket-divider" />

            <div class="ticket-row">
              <span>Concepto</span>
              <span>{{ ticketData.cobro.concepto === 'inscripcion' ? 'Inscripción' : 'Mensualidad' }}</span>
            </div>
            <div class="ticket-row">
              <span>Método</span>
              <span>{{ fmtMetodo(ticketData.metodo) }}</span>
            </div>
            <div v-if="ticketData.cobro.saldoFavorAplicado > 0" class="ticket-row" style="color:#15803d">
              <span>SF aplicado</span>
              <span>−{{ fmt(ticketData.cobro.saldoFavorAplicado) }}</span>
            </div>
            <div class="ticket-row mt-2" style="border-top:1px solid #e5e7eb;padding-top:8px">
              <span style="font-weight:700;font-size:14px;color:#0a1e6e">Abono</span>
              <span class="ticket-total" style="font-weight:800;font-size:18px;color:#0a1e6e;font-family:'Fraunces',serif">{{ fmt(ticketData.montoAbono) }}</span>
            </div>
            <div v-if="ticketData.cobro.estado !== 'pagado'" class="ticket-row" style="color:#d97706">
              <span style="font-size:11px">Pendiente</span>
              <span style="font-size:11px;font-weight:600">{{ fmt(ticketData.cobro.montoNeto - ticketData.cobro.montoPagado) }}</span>
            </div>

            <div class="ticket-divider" />
            <!-- Sello de estado: lo primero que busca el alumno al reclamar -->
            <div
              style="text-align:center;font-size:12px;font-weight:800;letter-spacing:.08em;margin:2px 0"
              :style="{ color: ticketData.cobro.estado === 'pagado' ? '#15803d' : '#d97706' }"
            >
              {{ ticketData.cobro.estado === 'pagado' ? '✓ PAGADO' : 'ABONO PARCIAL' }}
            </div>
            <div class="ticket-legal" style="text-align:center;font-size:9.5px;color:#9ca3af;margin-top:6px;line-height:1.35">
              Este comprobante no es un CFDI.<br>Consérvelo para cualquier aclaración.
            </div>
            <div style="text-align:center;font-size:10px;font-weight:600;color:#9ca3af;margin-top:6px">
              ¡Gracias por su pago!
            </div>
          </div>
        </div>

        <v-card-actions class="pa-4 pt-0 d-flex flex-column ga-2">
          <!-- Acción de diario en la caja: abre el diálogo de impresión del
               navegador con el comprobante ya formateado a 58 mm -->
          <v-btn
            block rounded="lg"
            style="background:#25D366;color:#fff;font-weight:700"
            prepend-icon="mdi-printer"
            :loading="imprimiendo"
            @click="imprimir"
          >Imprimir ticket</v-btn>
          <div class="d-flex ga-2 w-100">
            <v-btn
              variant="outlined"
              rounded="lg"
              prepend-icon="mdi-image-outline"
              style="flex:1"
              @click="comprobanteImagen"
            >Imagen</v-btn>
            <v-btn
              variant="outlined"
              rounded="lg"
              prepend-icon="mdi-whatsapp"
              style="flex:1"
              @click="compartirWhatsApp"
            >WhatsApp texto</v-btn>
          </div>
          <v-btn
            block
            rounded="lg"
            style="background:#0a1e6e;color:#2fe6b0;font-weight:700"
            @click="modalTicket = false"
          >Cerrar</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

  </div>
</template>

<style scoped>
.col-header {
  color: #9ca3af;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
}

.stat-label { font-size: 10.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #9ca3af; margin-bottom: 4px; }
.stat-num   { font-size: 32px; font-weight: 900; font-family: 'Fraunces', serif; line-height: 1; }
.stat-sub   { font-size: 11px; color: #9ca3af; margin-top: 4px; }

.section-label {
  font-size: 10.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: #9ca3af;
}

.cobro-grid  { display: flex; flex-direction: column; gap: 6px; }
.cobro-item  { display: flex; justify-content: space-between; align-items: center; }
.cobro-lbl   { font-size: 12px; color: #6b7280; }
.cobro-val   { font-size: 14px; font-weight: 600; color: #374151; }

.ticket-body   { border: 1px dashed #e5e7eb; border-radius: 12px; padding: 16px; background: #fafafa; }
.ticket-header { text-align: center; padding-bottom: 10px; }
.ticket-divider{ border-top: 1px dashed #e5e7eb; margin: 10px 0; }
.ticket-row    { display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #6b7280; padding: 3px 0; }

/* La impresión del comprobante ya no usa window.print() sobre esta vista:
   va al ESC/POS de la térmica o, como respaldo, a un iframe de 58 mm
   (utils/ticket58.ts). Por eso aquí no hay reglas @media print. */
</style>
