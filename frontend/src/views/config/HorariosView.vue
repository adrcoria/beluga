<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useConfiguracion, type Configuracion } from '@/composables/useConfiguracion'
import { useSwal } from '@/composables/useSwal'
import { useSucursalStore } from '@/stores/sucursal'
import { usePermisos } from '@/composables/usePermisos'
import SoloLectura from '@/components/SoloLectura.vue'

const { puedeEditar } = usePermisos('parametros')

const configApi = useConfiguracion()
const sucursalStore = useSucursalStore()
const { exito, error: notifError } = useSwal()

const cargando  = ref(false)
const guardando = ref(false)

// ── Folio de recibos de pago (por sucursal) ───────────────────────────────────
const folioActual    = ref(0)
const folioNuevo     = ref<number | null>(null)
const guardandoFolio = ref(false)

const form = ref({
  horaInicio: '08:00',
  horaFin:    '21:00',
  cupoGrupal: 6,
  // Anticipación mínima para que una falta dé derecho a reposición.
  // El cliente pidió que fuera configurable, pero nunca menos de 2 horas.
  avisoHoras: 2,
})

const AVISO_MINIMO = 2

// Opciones de hora cada 30 min de 06:00 a 22:00
const horasOpts = (() => {
  const out: string[] = []
  for (let h = 6; h <= 22; h++) {
    out.push(`${String(h).padStart(2, '0')}:00`)
    if (h < 22) out.push(`${String(h).padStart(2, '0')}:30`)
  }
  return out
})()

async function cargar() {
  cargando.value = true
  try {
    const c = await configApi.obtener()
    form.value.horaInicio = c.horario_grid_inicio ?? '08:00'
    form.value.horaFin    = c.horario_grid_fin    ?? '21:00'
    form.value.cupoGrupal = Number(c.horario_cupo_grupal ?? 6)
    form.value.avisoHoras = Number(c.ausencia_horas_aviso ?? AVISO_MINIMO)
  } catch (e: any) {
    notifError('Error al cargar configuración', e.message)
  } finally {
    cargando.value = false
  }
}

async function cargarFolio() {
  try {
    const r = await configApi.obtenerFolioPago(sucursalStore.sucursalActivaId)
    folioActual.value = r.siguiente
    folioNuevo.value  = r.siguiente
  } catch {}
}

async function guardarFolio() {
  if (folioNuevo.value === null || folioNuevo.value < 1) { notifError('Folio inválido'); return }
  if (folioNuevo.value < folioActual.value) {
    notifError('Solo se puede aumentar', `El próximo folio actual es ${folioActual.value}.`); return
  }
  guardandoFolio.value = true
  try {
    const r = await configApi.actualizarFolioPago(sucursalStore.sucursalActivaId, folioNuevo.value)
    folioActual.value = r.siguiente
    folioNuevo.value  = r.siguiente
    exito('Folio actualizado', `El próximo recibo será el #${r.siguiente}.`)
  } catch (e: any) {
    notifError('No se pudo actualizar', e.message)
  } finally {
    guardandoFolio.value = false
  }
}

onMounted(() => { cargar(); cargarFolio() })

async function guardar() {
  if (form.value.horaFin <= form.value.horaInicio) {
    notifError('La hora de fin debe ser mayor a la de inicio'); return
  }
  if (!(form.value.avisoHoras >= AVISO_MINIMO)) {
    notifError('Aviso mínimo', `No puede ser menor a ${AVISO_MINIMO} horas.`); return
  }
  guardando.value = true
  try {
    const payload: Configuracion = {
      horario_grid_inicio: form.value.horaInicio,
      horario_grid_fin:    form.value.horaFin,
      horario_cupo_grupal: String(form.value.cupoGrupal),
      ausencia_horas_aviso: String(form.value.avisoHoras),
    }
    await configApi.actualizar(payload)
    exito('Configuración guardada', 'Los cambios se aplican en el pizarrón del Calendario.')
  } catch (e: any) {
    notifError('No se pudo guardar', e.message)
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <div class="d-flex flex-column ga-4">
    <SoloLectura modulo="parametros" detalle="cambiar estos parámetros" />

    <v-card rounded="xl" border max-width="640">
      <div style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);padding:20px 24px;border-radius:16px 16px 0 0;border-bottom:1px solid #a7f3d0">
        <div style="color:#059669;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin-bottom:4px">
          Configuración · Horarios
        </div>
        <div style="color:#064e3b;font-size:18px;font-weight:700;font-family:'Fraunces',serif">
          Parámetros del pizarrón
        </div>
      </div>

      <div v-if="cargando" class="text-center py-10">
        <v-progress-circular indeterminate color="primary" size="32" />
      </div>

      <v-card-text v-else class="pa-5">
        <v-row dense>
          <v-col cols="12"><p class="section-label">Rango de horas de la cuadrícula</p></v-col>
          <v-col cols="6">
            <v-select v-model="form.horaInicio" :items="horasOpts" label="Hora de apertura"
              density="compact" variant="outlined" rounded="lg" hide-details />
          </v-col>
          <v-col cols="6">
            <v-select v-model="form.horaFin" :items="horasOpts" label="Hora de cierre"
              density="compact" variant="outlined" rounded="lg" hide-details />
          </v-col>

          <v-col cols="12"><p class="section-label mt-3">Clases grupales</p></v-col>
          <v-col cols="6">
            <v-text-field v-model.number="form.cupoGrupal" label="Cupo máximo por grupo"
              type="number" min="2" max="30"
              density="compact" variant="outlined" rounded="lg" hide-details />
          </v-col>

          <v-col cols="12"><p class="section-label mt-3">Reposiciones por falta avisada</p></v-col>
          <v-col cols="6">
            <v-text-field v-model.number="form.avisoHoras" label="Aviso mínimo (horas)"
              type="number" :min="AVISO_MINIMO" max="72" suffix="h"
              density="compact" variant="outlined" rounded="lg"
              :rules="[v => v >= AVISO_MINIMO || `Mínimo ${AVISO_MINIMO} horas`]" />
          </v-col>
        </v-row>

        <v-alert type="info" density="compact" variant="tonal" rounded="lg" class="mt-4" icon="mdi-information-outline">
          Estos parámetros definen el alto del pizarrón y el límite de alumnos por grupo en todo el sistema.
        </v-alert>

        <v-alert type="info" density="compact" variant="tonal" rounded="lg" class="mt-3" icon="mdi-clock-alert-outline">
          Si el alumno avisa su falta con <strong>{{ form.avisoHoras }} horas</strong> o más de anticipación, se le genera
          una reposición automáticamente. Con menos aviso pierde la clase. Las faltas ya registradas
          <strong>conservan</strong> el criterio con el que se resolvieron.
        </v-alert>
      </v-card-text>

      <v-divider />
      <v-card-actions class="pa-4 justify-end">
        <v-btn v-if="puedeEditar" color="#0e3b33" variant="flat" rounded="lg" :loading="guardando" @click="guardar">
          Guardar configuración
        </v-btn>
      </v-card-actions>
    </v-card>

    <!-- Folio de recibos de pago -->
    <v-card rounded="xl" border max-width="640">
      <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);padding:20px 24px;border-radius:16px 16px 0 0;border-bottom:1px solid #bfdbfe">
        <div style="color:#1a3fe8;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin-bottom:4px">
          Configuración · Cobranza
        </div>
        <div style="color:#0a1e6e;font-size:18px;font-weight:700;font-family:'Fraunces',serif">
          Folio de recibos de pago
        </div>
      </div>

      <v-card-text class="pa-5">
        <div class="d-flex align-center justify-space-between mb-3"
          style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px">
          <span style="font-size:13px;color:#6b7280">Próximo folio (sucursal activa)</span>
          <span style="font-size:22px;font-weight:800;color:#0a1e6e;font-family:'Fraunces',serif;font-variant-numeric:tabular-nums">
            #{{ folioActual }}
          </span>
        </div>

        <v-row dense align="center">
          <v-col cols="8" sm="6">
            <v-text-field v-model.number="folioNuevo" label="Establecer folio de partida"
              type="number" min="1"
              density="compact" variant="outlined" rounded="lg" hide-details />
          </v-col>
          <v-col cols="4" sm="6">
            <v-btn v-if="puedeEditar" color="primary" variant="flat" rounded="lg" :loading="guardandoFolio" @click="guardarFolio">
              Aplicar
            </v-btn>
          </v-col>
        </v-row>

        <v-alert type="info" density="compact" variant="tonal" rounded="lg" class="mt-4" icon="mdi-counter">
          El folio es consecutivo por sucursal y se asigna en cada pago. Solo se puede <strong>aumentar</strong> el punto de partida, nunca repetir folios ya emitidos.
        </v-alert>
      </v-card-text>
    </v-card>
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
</style>
