<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { VForm } from 'vuetify/components'
import { useCobranza, type Cobro, METODOS_PAGO } from '@/composables/useCobranza'
import { useSwal } from '@/composables/useSwal'
import { useSucursalStore } from '@/stores/sucursal'

const cobranzaApi   = useCobranza()
const { exito, error: notifError } = useSwal()
const sucursalStore  = useSucursalStore()

// ── Estado ─────────────────────────────────────────────────────────────────
const cobros    = ref<Cobro[]>([])
const cargando  = ref(false)
const busqueda  = ref('')
const filtroEst = ref<'todos' | 'pendiente' | 'parcial' | 'pagado'>('pendiente')

// ── Modal pago ─────────────────────────────────────────────────────────────
const modalPago   = ref(false)
const cobroSel    = ref<Cobro | null>(null)
const guardando   = ref(false)
const pagoFormRef = ref<InstanceType<typeof VForm>>()
const formPago    = ref({ monto: 0, metodo: 'efectivo' })

// ── Modal ticket ───────────────────────────────────────────────────────────
const modalTicket = ref(false)
const ticketData  = ref<{ cobro: Cobro; montoAbono: number; metodo: string } | null>(null)

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
  if (busqueda.value.trim()) {
    const q = busqueda.value.toLowerCase()
    r = r.filter(c => c.alumnoNombre.toLowerCase().includes(q) || c.alumnoFolio.includes(q))
  }
  return r
})

// ── Stats ──────────────────────────────────────────────────────────────────
const stats = computed(() => ({
  pendiente: cobros.value.filter(c => c.estado === 'pendiente'),
  parcial:   cobros.value.filter(c => c.estado === 'parcial'),
  pagado:    cobros.value.filter(c => c.estado === 'pagado'),
}))

const totalPendiente = computed(() =>
  stats.value.pendiente.reduce((s, c) => s + (c.montoNeto - c.montoPagado), 0) +
  stats.value.parcial.reduce((s, c) => s + (c.montoNeto - c.montoPagado), 0)
)

// ── Abrir modal de cobro ──────────────────────────────────────────────────
function abrirCobrar(cobro: Cobro) {
  cobroSel.value = cobro
  const pendiente = Math.max(0, cobro.montoNeto - cobro.montoPagado)
  formPago.value  = { monto: pendiente, metodo: 'efectivo' }
  modalPago.value = true
}

// ── Registrar abono ────────────────────────────────────────────────────────
async function registrarAbono() {
  const { valid } = await pagoFormRef.value!.validate()
  if (!valid || !cobroSel.value) return
  guardando.value = true
  try {
    const cobro = await cobranzaApi.registrarAbono(cobroSel.value.id, {
      monto:      formPago.value.monto,
      metodo:     formPago.value.metodo,
      sucursalId: sucursalStore.sucursalActivaId,
    })
    ticketData.value = { cobro, montoAbono: formPago.value.monto, metodo: formPago.value.metodo }
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
  new Date(f).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' })

function estadoColor(e: string) {
  return e === 'pagado' ? 'success' : e === 'parcial' ? 'warning' : 'error'
}

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
    `Ticket: ${d.cobro.ticket ?? '—'}`,
    `Fecha:  ${fmtFecha(new Date().toISOString())}`,
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

function imprimir() { window.print() }

function compartirEmail() {
  if (!ticketData.value) return
  const d       = ticketData.value
  const asunto  = encodeURIComponent(`Recibo de pago — Acuática Beluga — ${d.cobro.ticket ?? ''}`)
  const cuerpo  = encodeURIComponent(textoTicket(d).replace(/\*/g, '').replace(/_/g, ''))
  const mailto  = d.cobro.alumnoEmail ? `mailto:${d.cobro.alumnoEmail}` : 'mailto:'
  window.open(`${mailto}?subject=${asunto}&body=${cuerpo}`)
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
              ]" :key="f.val"
              :color="filtroEst === f.val ? 'primary' : undefined"
              :variant="filtroEst === f.val ? 'flat' : 'outlined'"
              size="small"
              style="cursor:pointer"
              @click="filtroEst = f.val as any"
            >{{ f.label }}</v-chip>
          </div>
          <v-spacer />
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
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="cargando">
            <td colspan="9" class="text-center py-12">
              <v-progress-circular indeterminate color="primary" size="32" />
            </td>
          </tr>
          <tr v-else-if="!cobrosFiltrados.length">
            <td colspan="9" class="text-center py-12 text-medium-emphasis" style="font-size:14px">
              Sin cobros{{ filtroEst !== 'todos' ? ' ' + filtroEst + 's' : '' }}
            </td>
          </tr>
          <tr v-for="c in cobrosFiltrados" :key="c.id">
            <td>
              <div class="d-flex align-center ga-2">
                <span style="font-weight:600;color:#0a1e6e;font-size:13px">{{ c.alumnoNombre }}</span>
                <v-chip
                  size="x-small" label variant="tonal"
                  :color="c.concepto === 'inscripcion' ? 'deep-purple' : 'primary'"
                >{{ c.concepto === 'inscripcion' ? 'Inscripción' : 'Mensualidad' }}</v-chip>
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
              <span v-if="c.estado !== 'pagado'" style="font-size:13px;font-weight:700;color:#dc2626">
                {{ fmt(c.montoNeto - c.montoPagado) }}
              </span>
              <v-icon v-else icon="mdi-check-circle" color="success" size="18" />
            </td>
            <td>
              <v-chip :color="estadoColor(c.estado)" size="small" variant="tonal" label>
                {{ c.estado === 'pagado' ? 'Pagado' : c.estado === 'parcial' ? 'Parcial' : 'Pendiente' }}
              </v-chip>
            </td>
            <td>
              <v-btn
                v-if="c.estado !== 'pagado'"
                size="small"
                rounded="lg"
                style="background:#2fe6b0;color:#0a1e6e;font-weight:700"
                @click.stop="abrirCobrar(c)"
              >Cobrar</v-btn>
            </td>
          </tr>
        </tbody>
      </v-table>
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

        <div class="ticket-body mx-5 mb-5" id="ticket-print">
          <div class="ticket-header">
            <div style="font-size:13px;font-weight:800;color:#0a1e6e;letter-spacing:.05em">ACUÁTICA BELUGA</div>
            <div style="font-size:10px;color:#9ca3af;margin-top:1px">Morelia, Michoacán</div>
          </div>
          <div class="ticket-divider" />

          <div class="ticket-row">
            <span>Ticket</span>
            <span style="font-family:monospace;font-weight:700;color:#1a3fe8">{{ ticketData.cobro.ticket ?? '—' }}</span>
          </div>
          <div class="ticket-row">
            <span>Fecha</span>
            <span>{{ fmtFecha(new Date().toISOString()) }}</span>
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
            <span style="text-transform:capitalize">{{ ticketData.metodo }}</span>
          </div>
          <div v-if="ticketData.cobro.saldoFavorAplicado > 0" class="ticket-row" style="color:#15803d">
            <span>SF aplicado</span>
            <span>−{{ fmt(ticketData.cobro.saldoFavorAplicado) }}</span>
          </div>
          <div class="ticket-row mt-2" style="border-top:1px solid #e5e7eb;padding-top:8px">
            <span style="font-weight:700;font-size:14px;color:#0a1e6e">Abono</span>
            <span style="font-weight:800;font-size:18px;color:#0a1e6e;font-family:'Fraunces',serif">{{ fmt(ticketData.montoAbono) }}</span>
          </div>
          <div v-if="ticketData.cobro.estado !== 'pagado'" class="ticket-row" style="color:#d97706">
            <span style="font-size:11px">Pendiente</span>
            <span style="font-size:11px;font-weight:600">{{ fmt(ticketData.cobro.montoNeto - ticketData.cobro.montoPagado) }}</span>
          </div>

          <div class="ticket-divider" />
          <div style="text-align:center;font-size:10px;color:#d1d5db;margin-top:6px">¡Nadar es vida!</div>
        </div>

        <v-card-actions class="pa-4 pt-0 d-flex flex-column ga-2">
          <div class="d-flex ga-2 w-100">
            <v-btn
              variant="outlined"
              rounded="lg"
              prepend-icon="mdi-printer"
              style="flex:1"
              @click="imprimir"
            >Imprimir</v-btn>
            <v-btn
              rounded="lg"
              style="background:#25D366;color:#fff;font-weight:700;flex:1"
              prepend-icon="mdi-whatsapp"
              @click="compartirWhatsApp"
            >WhatsApp</v-btn>
          </div>
          <div class="d-flex ga-2 w-100">
            <v-btn
              variant="outlined"
              rounded="lg"
              prepend-icon="mdi-email-outline"
              style="flex:1"
              @click="compartirEmail"
            >Correo</v-btn>
            <v-btn
              rounded="lg"
              style="background:#0a1e6e;color:#2fe6b0;font-weight:700;flex:1"
              @click="modalTicket = false"
            >Cerrar</v-btn>
          </div>
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

@media print {
  body * { visibility: hidden; }
  #ticket-print, #ticket-print * { visibility: visible; }
  #ticket-print { position: fixed; top: 20px; left: 20px; width: 280px; }
}
</style>
