<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { VForm } from 'vuetify/components'
import {
  useAlumnos, planesAMapa, USOS_CFDI, REGIMENES_FISCALES,
  type AlumnoResumen, type ProgramaAlumno, type DatosFiscales,
} from '@/composables/useAlumnos'
import { useModalidades } from '@/composables/useModalidades'
import { useApi } from '@/composables/useApi'
import { useSwal } from '@/composables/useSwal'
import { usePermisos } from '@/composables/usePermisos'
import SoloLectura from '@/components/SoloLectura.vue'

const { puedeEditar } = usePermisos('alumnos')

const { confirmar, exito, error: notifError } = useSwal()

const route   = useRoute()
const router  = useRouter()
const alumnos = useAlumnos()
const modApi  = useModalidades()
const api     = useApi()

interface SlotHorario {
  slotId: string
  dia: string
  horaInicio: string
  horaFin: string
  modalidad: string
  instructorNombre: string
  instructorIniciales: string
  grupoNombre?: string | null
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
    await cargarFiscales()
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

// ── Programas (multimodalidad) ─────────────────────────────────────────────
const modalidades = ref<{ clave: string; label: string }[]>([])
const planMap     = ref<Record<string, Record<number, number>>>({})

onMounted(async () => {
  try { modalidades.value = await modApi.listar() } catch {}
  try { planMap.value = planesAMapa(await alumnos.listarPlanes()) } catch {}
})

const modalidadesOpts = computed(() =>
  modalidades.value.map(m => ({ title: `${m.clave} — ${m.label}`, value: m.clave })))

const modalPrograma     = ref(false)
const editandoPrograma  = ref<ProgramaAlumno | null>(null)
const guardandoPrograma = ref(false)
const formPrograma = ref({
  modalidad: '', clasesSemana: 1, mensualidad: 0,
  descuentoPct: 0, descuentoMotivo: '',
  fechaInicio: new Date().toISOString().slice(0, 10),
})

const clasesOpts = computed(() => {
  const m = planMap.value[formPrograma.value.modalidad] ?? {}
  return Object.keys(m).map(Number).sort((a, b) => a - b)
})

// Al cambiar modalidad/clases, sugerir la mensualidad del catálogo
watch([() => formPrograma.value.modalidad, () => formPrograma.value.clasesSemana], () => {
  const m = planMap.value[formPrograma.value.modalidad]?.[formPrograma.value.clasesSemana]
  if (m != null) formPrograma.value.mensualidad = m
})

function abrirNuevoPrograma() {
  editandoPrograma.value = null
  formPrograma.value = {
    modalidad: '', clasesSemana: 1, mensualidad: 0,
    descuentoPct: 0, descuentoMotivo: '',
    fechaInicio: new Date().toISOString().slice(0, 10),
  }
  modalPrograma.value = true
}

function abrirEditarPrograma(p: ProgramaAlumno) {
  editandoPrograma.value = p
  formPrograma.value = {
    modalidad: p.modalidad,
    clasesSemana: p.plan.clasesSemana,
    mensualidad: p.plan.mensualidad,
    descuentoPct: p.descuento?.pct ?? 0,
    descuentoMotivo: p.descuento?.motivo ?? '',
    fechaInicio: p.fechaInicio,
  }
  modalPrograma.value = true
}

async function guardarPrograma() {
  if (!formPrograma.value.modalidad) { notifError('Selecciona una modalidad'); return }
  guardandoPrograma.value = true
  try {
    const descuento = formPrograma.value.descuentoPct > 0
      ? { pct: formPrograma.value.descuentoPct, motivo: formPrograma.value.descuentoMotivo || 'Descuento' }
      : null
    const plan = { clasesSemana: formPrograma.value.clasesSemana, mensualidad: formPrograma.value.mensualidad }

    if (editandoPrograma.value) {
      await alumnos.actualizarPrograma(editandoPrograma.value.id, {
        modalidad: formPrograma.value.modalidad, plan, descuento,
      })
      exito('Programa actualizado')
    } else {
      await alumnos.crearPrograma(folio, {
        modalidad: formPrograma.value.modalidad, plan, descuento,
        fechaInicio: formPrograma.value.fechaInicio,
      })
      exito('Programa agregado', 'Se generó su primera mensualidad')
    }
    modalPrograma.value = false
    await cargar()
  } catch (e: any) {
    notifError('Error', e.message)
  } finally {
    guardandoPrograma.value = false
  }
}

async function darBajaPrograma(p: ProgramaAlumno) {
  const { isConfirmed } = await confirmar('¿Dar de baja este programa?', `${p.modalidad} dejará de generar cobros.`)
  if (!isConfirmed) return
  try {
    await alumnos.bajaPrograma(p.id)
    await cargar()
    exito('Programa dado de baja')
  } catch (e: any) {
    notifError('Error', e.message)
  }
}

// ── Datos de facturación ───────────────────────────────────────────────────
// Se capturan una vez por alumno y se reusan en cada factura. Al pedir factura,
// el backend guarda una copia en el cobro para que la factura vieja conserve el
// dato con el que se emitió.
const datosFiscales   = ref<DatosFiscales | null>(null)
const editandoFiscal  = ref(false)
const guardandoFiscal = ref(false)
const fiscalFormRef   = ref<InstanceType<typeof VForm>>()
const formFiscal      = ref<DatosFiscales>({
  razonSocial: '', rfc: '', regimenFiscal: null, usoCfdi: null, codigoPostal: null, email: null,
})

async function cargarFiscales() {
  try { datosFiscales.value = await alumnos.obtenerDatosFiscales(folio) } catch { /* sin datos */ }
}

function abrirFiscal() {
  formFiscal.value = datosFiscales.value
    ? { ...datosFiscales.value }
    : {
        razonSocial: alumno.value?.contacto.tutor || alumno.value?.nombre || '',
        rfc: '', regimenFiscal: null, usoCfdi: 'D10',
        codigoPostal: null, email: alumno.value?.contacto.email ?? null,
      }
  editandoFiscal.value = true
}

async function guardarFiscal() {
  const { valid } = await fiscalFormRef.value!.validate()
  if (!valid) return
  guardandoFiscal.value = true
  try {
    datosFiscales.value  = await alumnos.guardarDatosFiscales(folio, formFiscal.value)
    editandoFiscal.value = false
    exito('Datos de facturación guardados')
  } catch (e: any) {
    notifError('Error', e.message)
  } finally {
    guardandoFiscal.value = false
  }
}

// ── Cambiar la fecha de pago ───────────────────────────────────────────────
// Aplica desde la siguiente mensualidad: el cobro del periodo en curso conserva
// sus fechas. Se muestra la vista previa porque toca el ciclo de cobro.
const modalDiaPago    = ref(false)
const programaDiaPago = ref<ProgramaAlumno | null>(null)
const nuevoDiaPago    = ref(1)
const guardandoDiaPago = ref(false)

function abrirDiaPago(p: ProgramaAlumno) {
  programaDiaPago.value = p
  nuevoDiaPago.value    = p.diaPago
  modalDiaPago.value    = true
}

/** Vencimiento que quedará: mismo mes del actual, con el día nuevo topado en meses cortos. */
const vencimientoPreview = computed(() => {
  const p = programaDiaPago.value
  if (!p) return ''
  const base   = new Date(p.vencimiento + 'T12:00:00')
  const ultimo = new Date(base.getFullYear(), base.getMonth() + 1, 0).getDate()
  const dia    = Math.min(nuevoDiaPago.value, ultimo)
  return new Date(base.getFullYear(), base.getMonth(), dia)
    .toLocaleDateString('es-MX', { day: '2-digit', month: 'long', year: 'numeric' })
})

async function guardarDiaPago() {
  if (!programaDiaPago.value) return
  guardandoDiaPago.value = true
  try {
    await alumnos.cambiarDiaPago(programaDiaPago.value.id, nuevoDiaPago.value)
    modalDiaPago.value = false
    await cargar()
    exito('Fecha de pago actualizada', 'Aplica a partir de la siguiente mensualidad')
  } catch (e: any) {
    notifError('Error', e.message)
  } finally {
    guardandoDiaPago.value = false
  }
}

// ── Reactivar un programa dado de baja ─────────────────────────────────────
// Se exige confirmar la fecha de pago: al regresar el alumno no se hereda el
// ciclo viejo, que casi siempre quedó vencido hace meses.
const modalReactivar     = ref(false)
const programaReactivar  = ref<ProgramaAlumno | null>(null)
const fechaPagoReactivar = ref('')
const reactivando        = ref(false)

function abrirReactivar(p: ProgramaAlumno) {
  programaReactivar.value  = p
  fechaPagoReactivar.value = new Date().toISOString().slice(0, 10)
  modalReactivar.value     = true
}

async function confirmarReactivar() {
  if (!programaReactivar.value || !fechaPagoReactivar.value) return
  reactivando.value = true
  try {
    await alumnos.reactivarPrograma(programaReactivar.value.id, fechaPagoReactivar.value)
    modalReactivar.value = false
    await cargar()
    exito('Programa reactivado', 'Su próxima mensualidad vence en la fecha confirmada')
  } catch (e: any) {
    notifError('Error', e.message)
  } finally {
    reactivando.value = false
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
    <SoloLectura modulo="alumnos" detalle="editar la ficha" />

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
                v-if="alumno.status !== 'activo' && puedeEditar"
                size="small"
                color="success"
                rounded="lg"
                variant="flat"
                :loading="cambiandoStatus"
                prepend-icon="mdi-account-check"
                @click="cambiarStatus('activo')"
              >Activar</v-btn>
              <v-btn
                v-if="alumno.status !== 'baja' && puedeEditar"
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
            <!-- Programas / Modalidades -->
            <v-col cols="12">
              <v-card rounded="xl" border>
                <v-card-title class="section-title pa-4 pb-2 d-flex align-center justify-space-between">
                  <span>Programas / Modalidades
                    <v-chip size="x-small" color="primary" class="ml-2">{{ alumno.programas.length }}</v-chip>
                  </span>
                  <v-btn v-if="puedeEditar" size="small" color="primary" variant="flat" rounded="lg" prepend-icon="mdi-plus" @click="abrirNuevoPrograma">
                    Agregar programa
                  </v-btn>
                </v-card-title>
                <v-card-text class="pa-4 pt-2">
                  <div v-if="!alumno.programas.length" style="font-size:13px;color:#9ca3af;text-align:center;padding:12px 0">
                    Sin programas registrados.
                  </div>
                  <div v-else class="d-flex flex-column ga-2">
                    <div
                      v-for="p in alumno.programas" :key="p.id"
                      class="programa-row" :style="p.status !== 'activo' ? 'opacity:.55' : ''"
                    >
                      <div class="d-flex align-center ga-3">
                        <v-chip size="small" variant="flat" label
                          :style="{ background: colorMod(p.modalidad), color: textMod(p.modalidad), fontWeight: 700, minWidth:'64px', justifyContent:'center' }">
                          {{ p.modalidad }}
                        </v-chip>
                        <div>
                          <div style="font-weight:700;color:#0a1e6e;font-size:14px">
                            {{ fmt(p.montoConDescuento) }}
                            <span style="font-weight:400;color:#6b7280;font-size:12px"> · {{ p.plan.clasesSemana }} clases/sem</span>
                          </div>
                          <div style="font-size:11px;color:#9ca3af">
                            Paga el día {{ p.diaPago }} · vence {{ fmtFechaCorta(p.vencimiento) }}
                            <span v-if="p.descuento" style="color:#15803d"> · {{ p.descuento.pct }}% {{ p.descuento.motivo }}</span>
                            <span v-if="p.status !== 'activo'" style="color:#dc2626;font-weight:600"> · baja</span>
                          </div>
                        </div>
                      </div>
                      <div class="d-flex align-center ga-1">
                        <v-chip v-if="p.diasMora > 0" size="x-small" color="error" variant="tonal">{{ p.diasMora }}d mora</v-chip>
                        <v-chip v-else-if="p.tocaPago" size="x-small" color="warning" variant="tonal">toca pago</v-chip>
                        <v-btn
                          v-if="p.status === 'activo' && puedeEditar"
                          icon="mdi-calendar-edit" size="x-small" variant="text" color="grey"
                          title="Cambiar fecha de pago"
                          @click="abrirDiaPago(p)"
                        />
                        <v-btn v-if="puedeEditar" icon="mdi-pencil-outline" size="x-small" variant="text" color="grey" title="Editar programa" @click="abrirEditarPrograma(p)" />
                        <v-btn
                          v-if="p.status === 'activo' && puedeEditar"
                          icon="mdi-account-off-outline" size="x-small" variant="text" color="error"
                          title="Dar de baja"
                          @click="darBajaPrograma(p)"
                        />
                        <v-btn
                          v-else-if="puedeEditar"
                          size="x-small" variant="tonal" color="success" rounded="lg"
                          prepend-icon="mdi-account-reactivate-outline"
                          @click="abrirReactivar(p)"
                        >Reactivar</v-btn>
                      </div>
                    </div>
                  </div>
                </v-card-text>
              </v-card>
            </v-col>

            <!-- Datos de facturación -->
            <v-col cols="12">
              <v-card rounded="xl" border>
                <v-card-title class="section-title pa-4 pb-2 d-flex align-center justify-space-between">
                  <span>
                    Datos de facturación
                    <v-chip
                      size="x-small" class="ml-2" variant="tonal"
                      :color="datosFiscales ? 'success' : 'grey'"
                    >{{ datosFiscales ? 'Capturados' : 'Sin capturar' }}</v-chip>
                  </span>
                  <v-btn
                    v-if="!editandoFiscal && puedeEditar"
                    size="small" color="primary" variant="flat" rounded="lg"
                    :prepend-icon="datosFiscales ? 'mdi-pencil-outline' : 'mdi-plus'"
                    @click="abrirFiscal"
                  >{{ datosFiscales ? 'Editar' : 'Capturar' }}</v-btn>
                </v-card-title>

                <v-card-text class="pa-4 pt-2">
                  <!-- Lectura -->
                  <template v-if="!editandoFiscal">
                    <div v-if="!datosFiscales" style="font-size:13px;color:#9ca3af;text-align:center;padding:12px 0">
                      Este alumno no factura. Captura sus datos solo si los va a pedir.
                    </div>
                    <div v-else class="d-flex flex-column">
                      <div class="info-row">
                        <span class="info-label">Razón social</span>
                        <span class="info-val">{{ datosFiscales.razonSocial }}</span>
                      </div>
                      <v-divider class="my-2" />
                      <div class="info-row">
                        <span class="info-label">RFC</span>
                        <span class="info-val" style="font-family:monospace;font-weight:700;color:#1a3fe8">
                          {{ datosFiscales.rfc }}
                        </span>
                      </div>
                      <template v-if="datosFiscales.regimenFiscal">
                        <v-divider class="my-2" />
                        <div class="info-row">
                          <span class="info-label">Régimen fiscal</span>
                          <span class="info-val">{{ datosFiscales.regimenFiscal }}</span>
                        </div>
                      </template>
                      <template v-if="datosFiscales.usoCfdi">
                        <v-divider class="my-2" />
                        <div class="info-row">
                          <span class="info-label">Uso de CFDI</span>
                          <span class="info-val">{{ datosFiscales.usoCfdi }}</span>
                        </div>
                      </template>
                      <template v-if="datosFiscales.codigoPostal">
                        <v-divider class="my-2" />
                        <div class="info-row">
                          <span class="info-label">Código postal</span>
                          <span class="info-val">{{ datosFiscales.codigoPostal }}</span>
                        </div>
                      </template>
                      <template v-if="datosFiscales.email">
                        <v-divider class="my-2" />
                        <div class="info-row">
                          <span class="info-label">Correo de facturación</span>
                          <span class="info-val">{{ datosFiscales.email }}</span>
                        </div>
                      </template>
                    </div>
                  </template>

                  <!-- Captura -->
                  <v-form v-else ref="fiscalFormRef" @submit.prevent="guardarFiscal">
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
                          variant="outlined" density="compact" rounded="lg"
                          maxlength="5"
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

                    <div class="d-flex justify-end ga-2 mt-2">
                      <v-btn variant="text" rounded="lg" @click="editandoFiscal = false">Cancelar</v-btn>
                      <v-btn
                        type="submit" rounded="lg"
                        style="background:#0a1e6e;color:#2fe6b0;font-weight:700"
                        :loading="guardandoFiscal"
                      >Guardar</v-btn>
                    </div>
                  </v-form>
                </v-card-text>
              </v-card>
            </v-col>

            <!-- Cobranza -->
            <v-col cols="12" md="6">
              <v-card rounded="xl" border height="100%">
                <v-card-title class="section-title pa-4 pb-2">Cobranza</v-card-title>
                <v-card-text class="pa-4 pt-2">
                  <div class="info-row">
                    <span class="info-label">Mensualidad total</span>
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
                    Sin horario asignado — asignar desde el Calendario
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
                        <div class="text-right">
                          <div style="font-size:12px;color:#6b7280">{{ slot.instructorNombre }}</div>
                          <div v-if="slot.grupoNombre" style="font-size:10.5px;color:#9d174d;font-weight:600">{{ slot.grupoNombre }}</div>
                        </div>
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

      <!-- ── Modal: Agregar / editar programa ──────────────────────────────── -->
      <v-dialog v-model="modalPrograma" max-width="480" persistent>
        <v-card rounded="xl">
          <div style="background:linear-gradient(135deg,#e8f4ff,#dbeafe);padding:18px 22px;border-radius:16px 16px 0 0;border-bottom:1px solid #b8d4ff">
            <div style="color:#1a3fe8;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase">Programa</div>
            <div style="color:#0a1e6e;font-size:17px;font-weight:700;font-family:'Fraunces',serif">
              {{ editandoPrograma ? 'Editar programa' : 'Agregar programa' }}
            </div>
          </div>
          <v-card-text class="pa-5">
            <v-row dense>
              <v-col cols="12">
                <v-select
                  v-model="formPrograma.modalidad"
                  :items="modalidadesOpts"
                  label="Modalidad *"
                  density="compact" variant="outlined" rounded="lg"
                />
              </v-col>
              <v-col cols="6">
                <v-select
                  v-model.number="formPrograma.clasesSemana"
                  :items="clasesOpts.length ? clasesOpts : [1,2,3,4,5]"
                  label="Clases / semana *"
                  density="compact" variant="outlined" rounded="lg"
                />
              </v-col>
              <v-col cols="6">
                <v-text-field
                  v-model.number="formPrograma.mensualidad"
                  label="Mensualidad *"
                  type="number" prefix="$"
                  density="compact" variant="outlined" rounded="lg"
                />
              </v-col>
              <v-col v-if="!editandoPrograma" cols="12">
                <v-text-field
                  v-model="formPrograma.fechaInicio"
                  label="Fecha de inicio *"
                  type="date"
                  density="compact" variant="outlined" rounded="lg"
                />
              </v-col>
              <v-col cols="7">
                <v-text-field
                  v-model.number="formPrograma.descuentoPct"
                  label="Descuento %"
                  type="number" suffix="%"
                  density="compact" variant="outlined" rounded="lg"
                />
              </v-col>
              <v-col cols="5">
                <v-text-field
                  v-model="formPrograma.descuentoMotivo"
                  label="Motivo"
                  density="compact" variant="outlined" rounded="lg"
                  :disabled="!formPrograma.descuentoPct"
                />
              </v-col>
            </v-row>
          </v-card-text>
          <v-divider />
          <v-card-actions class="pa-4 ga-2">
            <v-btn variant="text" rounded="lg" @click="modalPrograma = false">Cancelar</v-btn>
            <v-spacer />
            <v-btn color="primary" variant="flat" rounded="lg" :loading="guardandoPrograma" @click="guardarPrograma">
              {{ editandoPrograma ? 'Guardar' : 'Agregar' }}
            </v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <!-- ── Modal: cambiar fecha de pago ──────────────────────────────────── -->
      <v-dialog v-model="modalDiaPago" max-width="440">
        <v-card v-if="programaDiaPago" rounded="xl">
          <v-card-title class="pa-5 pb-2">
            <div style="color:#0a1e6e;font-size:18px;font-weight:700;font-family:'Fraunces',serif">
              Cambiar fecha de pago
            </div>
            <div style="font-size:13px;color:#6b7280;font-weight:400">
              {{ programaDiaPago.modalidad }} · paga hoy el día {{ programaDiaPago.diaPago }}
            </div>
          </v-card-title>
          <v-divider />

          <v-card-text class="pa-5">
            <v-select
              v-model="nuevoDiaPago"
              :items="Array.from({ length: 31 }, (_, i) => i + 1)"
              label="Nuevo día de pago *"
              variant="outlined"
              density="compact"
              rounded="lg"
            />
            <!-- Vista previa obligatoria: mueve el ciclo de cobro del alumno -->
            <v-alert type="info" variant="tonal" density="compact" rounded="lg">
              <div style="font-size:12.5px;line-height:1.55">
                Aplica <strong>a partir de la siguiente mensualidad</strong>: el cobro
                del periodo en curso conserva sus fechas.<br>
                Su próximo vencimiento pasará a ser el <strong>{{ vencimientoPreview }}</strong>.
                <template v-if="nuevoDiaPago > 28">
                  <br><span style="color:#d97706">En los meses de menos días se cobrará el último día del mes.</span>
                </template>
              </div>
            </v-alert>
          </v-card-text>

          <v-divider />
          <v-card-actions class="pa-4 ga-2">
            <v-btn variant="text" rounded="lg" @click="modalDiaPago = false">Cancelar</v-btn>
            <v-spacer />
            <v-btn
              rounded="lg"
              style="background:#0a1e6e;color:#2fe6b0;font-weight:700;min-width:130px"
              :loading="guardandoDiaPago"
              :disabled="nuevoDiaPago === programaDiaPago.diaPago"
              @click="guardarDiaPago"
            >Guardar</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <!-- ── Modal: reactivar programa ─────────────────────────────────────── -->
      <v-dialog v-model="modalReactivar" max-width="440">
        <v-card v-if="programaReactivar" rounded="xl">
          <v-card-title class="pa-5 pb-2">
            <div style="color:#0a1e6e;font-size:18px;font-weight:700;font-family:'Fraunces',serif">
              Reactivar programa
            </div>
            <div style="font-size:13px;color:#6b7280;font-weight:400">
              {{ programaReactivar.modalidad }} · {{ fmt(programaReactivar.montoConDescuento) }} al mes
            </div>
          </v-card-title>
          <v-divider />

          <v-card-text class="pa-5">
            <v-alert type="info" variant="tonal" density="compact" rounded="lg" class="mb-4">
              <div style="font-size:12.5px;line-height:1.5">
                Confirma con qué fecha se reincorpora. De ahí sale su nuevo día de
                pago y su primer vencimiento; no se hereda el ciclo anterior.
              </div>
            </v-alert>

            <v-text-field
              v-model="fechaPagoReactivar"
              type="date"
              label="Fecha de pago *"
              variant="outlined"
              density="compact"
              rounded="lg"
              :rules="[v => !!v || 'La fecha es requerida']"
            />
          </v-card-text>

          <v-divider />
          <v-card-actions class="pa-4 ga-2">
            <v-btn variant="text" rounded="lg" @click="modalReactivar = false">Cancelar</v-btn>
            <v-spacer />
            <v-btn
              rounded="lg"
              style="background:#0a1e6e;color:#2fe6b0;font-weight:700;min-width:130px"
              :loading="reactivando"
              :disabled="!fechaPagoReactivar"
              @click="confirmarReactivar"
            >Reactivar</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

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
.programa-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border: 1px solid #eef2f7;
  border-radius: 12px;
  padding: 10px 14px;
}
</style>
