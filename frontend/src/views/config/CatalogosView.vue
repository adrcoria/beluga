<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAlumnos, type PlanCatalogo } from '@/composables/useAlumnos'
import { useSwal } from '@/composables/useSwal'

const alumnos = useAlumnos()
const { exito, error: notifError, confirmar } = useSwal()

// ── Estado ────────────────────────────────────────────────────────────────
const planes   = ref<PlanCatalogo[]>([])
const cargando = ref(true)

// ── Modal ─────────────────────────────────────────────────────────────────
const dialog    = ref(false)
const guardando = ref(false)
const editandoId = ref<string | null>(null)

const formVacio = () => ({ label: '', modalidad: 'PART' as PlanCatalogo['modalidad'], clasesSemana: 1, mensualidad: 0 })
const form = ref(formVacio())
const formRef = ref()

const MODALIDADES = ['PART', 'GRUPAL', 'MATRO'] as const
const colorMod: Record<string, string> = { PART: '#dbeafe', GRUPAL: '#fce7f3', MATRO: '#fed7aa' }
const textMod:  Record<string, string> = { PART: '#1e3a8a', GRUPAL: '#9d174d', MATRO: '#9a3412' }

// ── Carga ─────────────────────────────────────────────────────────────────
async function cargar() {
  cargando.value = true
  try { planes.value = await alumnos.listarPlanes() }
  finally { cargando.value = false }
}
onMounted(cargar)

function planesPorModalidad(mod: string) {
  return planes.value
    .filter(p => p.modalidad === mod)
    .sort((a, b) => a.clasesSemana - b.clasesSemana)
}

// ── Abrir modal ───────────────────────────────────────────────────────────
function abrirNuevo(modalidad: PlanCatalogo['modalidad']) {
  editandoId.value = null
  form.value = { ...formVacio(), modalidad }
  dialog.value = true
}

function abrirEditar(plan: PlanCatalogo) {
  editandoId.value = plan.id
  form.value = { label: plan.label, modalidad: plan.modalidad, clasesSemana: plan.clasesSemana, mensualidad: plan.mensualidad }
  dialog.value = true
}

// ── Guardar ───────────────────────────────────────────────────────────────
async function guardar() {
  const { valid } = await formRef.value!.validate()
  if (!valid) return
  guardando.value = true
  try {
    if (editandoId.value) {
      await alumnos.actualizarPlan(editandoId.value, { label: form.value.label, mensualidad: form.value.mensualidad, clasesSemana: form.value.clasesSemana })
      exito('Servicio actualizado', form.value.label)
    } else {
      await alumnos.crearPlan(form.value)
      exito('Servicio creado', form.value.label)
    }
    dialog.value = false
    await cargar()
  } catch (e: any) {
    notifError('Error', e.message)
  } finally {
    guardando.value = false
  }
}

// ── Eliminar ──────────────────────────────────────────────────────────────
async function eliminar(plan: PlanCatalogo) {
  const { isConfirmed } = await confirmar(
    '¿Eliminar este servicio?',
    `"${plan.label}" será removido del catálogo.`,
  )
  if (!isConfirmed) return
  try {
    await alumnos.eliminarPlan(plan.id)
    exito('Servicio eliminado')
    await cargar()
  } catch (e: any) {
    notifError('Error', e.message)
  }
}

const fmt = (n: number) =>
  new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 0 }).format(n)
</script>

<template>
  <div class="d-flex flex-column ga-4">
    <v-card rounded="xl" border>
      <!-- Header -->
      <div style="background:linear-gradient(135deg,#e8f4ff,#dbeafe);padding:20px 24px;border-radius:16px 16px 0 0;border-bottom:1px solid #b8d4ff">
        <div style="color:#1a3fe8;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin-bottom:4px">Catálogos · Planes de pago</div>
        <div style="color:#0a1e6e;font-size:18px;font-weight:700;font-family:'Fraunces',serif">Servicios por modalidad</div>
      </div>

      <div v-if="cargando" class="text-center py-16">
        <v-progress-circular indeterminate color="primary" size="40" />
      </div>

      <div v-else class="pa-6 d-flex flex-column ga-6">
        <div v-for="mod in MODALIDADES" :key="mod">
          <!-- Cabecera modalidad -->
          <div class="d-flex align-center ga-3 mb-3">
            <v-chip size="small" label variant="flat"
              :style="{ background: colorMod[mod], color: textMod[mod], fontWeight: '700' }"
            >{{ mod }}</v-chip>
            <div style="height:1px;flex:1;background:#e5e7eb" />
            <v-btn
              size="x-small" variant="tonal" color="primary" rounded="lg"
              prepend-icon="mdi-plus"
              @click="abrirNuevo(mod)"
            >Nuevo servicio</v-btn>
          </div>

          <!-- Tabla -->
          <v-table density="compact" hover v-if="planesPorModalidad(mod).length">
            <thead>
              <tr>
                <th style="color:#9ca3af;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Nombre del servicio</th>
                <th style="color:#9ca3af;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Clases/sem</th>
                <th style="color:#9ca3af;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Mensualidad</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="plan in planesPorModalidad(mod)" :key="plan.id">
                <td style="font-weight:600;color:#0a1e6e;font-size:14px">{{ plan.label }}</td>
                <td>
                  <v-chip size="x-small" variant="tonal" color="primary" label>
                    {{ plan.clasesSemana }} clase{{ plan.clasesSemana > 1 ? 's' : '' }}/sem
                  </v-chip>
                </td>
                <td style="font-weight:700;color:#15803d;font-size:15px">{{ fmt(plan.mensualidad) }}</td>
                <td>
                  <div class="d-flex ga-1 justify-end">
                    <v-btn icon size="x-small" variant="text" color="primary" @click="abrirEditar(plan)">
                      <v-icon>mdi-pencil-outline</v-icon>
                    </v-btn>
                    <v-btn icon size="x-small" variant="text" color="error" @click="eliminar(plan)">
                      <v-icon>mdi-delete-outline</v-icon>
                    </v-btn>
                  </div>
                </td>
              </tr>
            </tbody>
          </v-table>

          <div v-else class="text-center py-4" style="font-size:13px;color:#9ca3af;border:1px dashed #e5e7eb;border-radius:12px">
            Sin servicios. Agrega el primero.
          </div>
        </div>
      </div>
    </v-card>

    <!-- ── Modal crear / editar ───────────────────────────────────────────── -->
    <v-dialog v-model="dialog" max-width="480" persistent>
      <v-card rounded="xl">
        <div style="background:linear-gradient(135deg,#e8f4ff,#dbeafe);padding:20px 24px;border-radius:16px 16px 0 0;border-bottom:1px solid #b8d4ff">
          <div style="color:#1a3fe8;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin-bottom:4px">
            {{ editandoId ? 'Editar servicio' : 'Nuevo servicio' }}
          </div>
          <div style="color:#0a1e6e;font-size:18px;font-weight:700;font-family:'Fraunces',serif">
            {{ editandoId ? form.label || '—' : 'Agregar al catálogo' }}
          </div>
        </div>

        <v-card-text class="pa-6">
          <v-form ref="formRef">
            <v-row dense>
              <v-col cols="12">
                <v-text-field
                  v-model="form.label"
                  label="Nombre del servicio *"
                  variant="outlined" density="compact" rounded="lg"
                  :rules="[v => !!v || 'Campo requerido']"
                  hint="Ej: PART · 2 clases/sem, Intensivo adultos"
                  persistent-hint
                />
              </v-col>
              <v-col cols="12" sm="6">
                <v-select
                  v-model="form.modalidad"
                  label="Modalidad *"
                  :items="MODALIDADES"
                  variant="outlined" density="compact" rounded="lg"
                  :rules="[v => !!v || 'Requerido']"
                  :disabled="!!editandoId"
                />
              </v-col>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model.number="form.clasesSemana"
                  label="Clases por semana *"
                  type="number" min="1" max="7"
                  variant="outlined" density="compact" rounded="lg"
                  :rules="[v => v >= 1 || 'Mínimo 1']"
                  :disabled="!!editandoId"
                />
              </v-col>
              <v-col cols="12">
                <v-text-field
                  v-model.number="form.mensualidad"
                  label="Mensualidad *"
                  type="number" min="1"
                  prefix="$"
                  variant="outlined" density="compact" rounded="lg"
                  :rules="[v => v > 0 || 'Ingresa el precio']"
                />
              </v-col>
            </v-row>
          </v-form>
        </v-card-text>

        <v-card-actions class="pa-4 pt-0 ga-2">
          <v-spacer />
          <v-btn variant="text" rounded="lg" @click="dialog = false">Cancelar</v-btn>
          <v-btn color="primary" variant="flat" rounded="lg" :loading="guardando" @click="guardar">
            {{ editandoId ? 'Guardar cambios' : 'Agregar servicio' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>
