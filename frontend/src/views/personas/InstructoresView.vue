<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import type { VForm } from 'vuetify/components'
import { useInstructores, type Instructor } from '@/composables/useInstructores'
import { useModalidades, type Modalidad } from '@/composables/useModalidades'
import { useSwal } from '@/composables/useSwal'
import { useSucursalStore } from '@/stores/sucursal'

const api           = useInstructores()
const modApi        = useModalidades()
const sucursalStore = useSucursalStore()
const { confirmar, exito, error: notifError } = useSwal()

const modalidades = ref<Modalidad[]>([])

// ── Estado ────────────────────────────────────────────────────────────────────
const lista        = ref<Instructor[]>([])
const cargando     = ref(false)
const busqueda     = ref('')
const filtroActivo = ref<'activos' | 'inactivos' | 'todos'>('activos')

// ── Diálogo ───────────────────────────────────────────────────────────────────
const dialogOpen   = ref(false)
const guardando    = ref(false)
const procesandoId = ref<string | null>(null)
const errorMsg     = ref('')
const formRef      = ref<InstanceType<typeof VForm>>()

interface FormState {
  nombre: string; iniciales: string; telefono: string
  email: string;  niveles: string[]; activo: boolean
  dispHoraInicio: string; dispHoraFin: string
}

const initForm = (): FormState => ({
  nombre: '', iniciales: '', telefono: '', email: '', niveles: [], activo: true,
  dispHoraInicio: '', dispHoraFin: '',
})
const form = ref<FormState>(initForm())
const editandoId = ref<string | null>(null)


const headers = [
  { title: 'Instructor', key: 'nombre',   sortable: true },
  { title: 'Teléfono',   key: 'telefono', sortable: false },
  { title: 'Modalidades', key: 'niveles',  sortable: false },
  { title: 'Estado',     key: 'activo',   sortable: true,  width: '110px' },
  { title: '',           key: 'acciones', sortable: false, align: 'end' as const, width: '60px' },
]

// ── Auto-iniciales al escribir nombre ────────────────────────────────────────
watch(() => form.value.nombre, (nombre) => {
  if (editandoId.value) return   // al editar no se recalculan las iniciales
  const palabras = nombre.trim().split(/\s+/).filter(Boolean)
  form.value.iniciales = palabras.map(p => p[0]).join('').toUpperCase().slice(0, 3)
})

// ── Lista filtrada ─────────────────────────────────────────────────────────────
const listaFiltrada = computed(() => {
  const q = busqueda.value.toLowerCase().trim()
  return lista.value.filter(i => {
    if (filtroActivo.value === 'activos'   && !i.activo) return false
    if (filtroActivo.value === 'inactivos' &&  i.activo) return false
    if (!q) return true
    return i.nombre.toLowerCase().includes(q) || i.iniciales.toLowerCase().includes(q)
  })
})

const totalActivos   = computed(() => lista.value.filter(i =>  i.activo).length)
const totalInactivos = computed(() => lista.value.filter(i => !i.activo).length)

// ── Carga ─────────────────────────────────────────────────────────────────────
async function cargar() {
  cargando.value = true
  try { lista.value = await api.listar({ todos: true }) }
  finally { cargando.value = false }
}

async function cargarModalidades() {
  try { modalidades.value = await modApi.listar() } catch {}
}

// ── Diálogo ───────────────────────────────────────────────────────────────────
function abrirNuevo() {
  editandoId.value = null
  form.value       = initForm()
  errorMsg.value   = ''
  dialogOpen.value = true
}

function abrirEditar(i: Instructor) {
  editandoId.value = i.id
  form.value = {
    nombre: i.nombre, iniciales: i.iniciales, telefono: i.telefono,
    email: i.email ?? '', niveles: [...i.niveles], activo: i.activo,
    dispHoraInicio: i.dispHoraInicio ?? '', dispHoraFin: i.dispHoraFin ?? '',
  }
  errorMsg.value = ''
  dialogOpen.value = true
}

async function guardar() {
  const { valid } = await formRef.value!.validate()
  if (!valid) return
  if (form.value.niveles.length === 0) { errorMsg.value = 'Selecciona al menos una modalidad'; return }
  if (form.value.dispHoraInicio && form.value.dispHoraFin && form.value.dispHoraFin <= form.value.dispHoraInicio) {
    errorMsg.value = 'La hora de fin debe ser mayor a la de inicio'; return
  }

  guardando.value = true
  errorMsg.value  = ''
  const payload = {
    nombre:         form.value.nombre.trim(),
    iniciales:      form.value.iniciales.trim().toUpperCase(),
    telefono:       form.value.telefono.trim(),
    email:          form.value.email.trim() || null,
    niveles:        form.value.niveles,
    sucursales:     [sucursalStore.sucursalActivaId],
    dispHoraInicio: form.value.dispHoraInicio || null,
    dispHoraFin:    form.value.dispHoraFin || null,
  }
  try {
    if (editandoId.value) {
      const updated = await api.actualizar(editandoId.value, { ...payload, activo: form.value.activo })
      const idx = lista.value.findIndex(i => i.id === editandoId.value)
      if (idx >= 0) lista.value[idx] = updated
      exito('Instructor actualizado', updated.nombre)
    } else {
      const nuevo = await api.crear(payload)
      lista.value.push(nuevo)
      exito('Instructor creado', nuevo.nombre)
    }
    dialogOpen.value = false
  } catch (e: any) {
    errorMsg.value = e.message
  } finally {
    guardando.value = false
  }
}

async function eliminar(instructor: Instructor) {
  const { isConfirmed } = await confirmar(
    '¿Eliminar instructor?',
    `Esta acción es permanente. "${instructor.nombre}" será eliminado del sistema.`
  )
  if (!isConfirmed) return
  procesandoId.value = instructor.id
  try {
    await api.eliminar(instructor.id)
    lista.value = lista.value.filter(i => i.id !== instructor.id)
    exito('Instructor eliminado', instructor.nombre)
  } catch (e: any) {
    notifError('Error', e.message)
  } finally {
    procesandoId.value = null
  }
}

async function inactivar(instructor: Instructor) {
  const { isConfirmed } = await confirmar(
    '¿Inactivar instructor?',
    `${instructor.nombre} dejará de aparecer en el calendario y no podrá asignarse a nuevas clases.`
  )
  if (!isConfirmed) return
  procesandoId.value = instructor.id
  try {
    const updated = await api.actualizar(instructor.id, { ...instructor, activo: false })
    const idx = lista.value.findIndex(i => i.id === instructor.id)
    if (idx >= 0) lista.value[idx] = updated
    exito('Instructor inactivado', instructor.nombre)
  } catch (e: any) {
    notifError('Error', e.message)
  } finally {
    procesandoId.value = null
  }
}

async function reactivar(instructor: Instructor) {
  procesandoId.value = instructor.id
  try {
    const updated = await api.actualizar(instructor.id, { ...instructor, activo: true })
    const idx = lista.value.findIndex(i => i.id === instructor.id)
    if (idx >= 0) lista.value[idx] = updated
    exito('Instructor reactivado', instructor.nombre)
  } catch (e: any) {
    notifError('Error', e.message)
  } finally {
    procesandoId.value = null
  }
}

onMounted(() => { cargar(); cargarModalidades() })
</script>

<template>
  <div class="d-flex flex-column ga-4">

    <!-- Stats -->
    <v-row dense>
      <v-col v-for="s in ([
        { label: 'Total instructores', val: lista.length,    color: '#0a1e6e' },
        { label: 'Activos',            val: totalActivos,    color: '#15803d' },
        { label: 'Inactivos',          val: totalInactivos,  color: '#6b7280' },
      ])" :key="s.label" cols="12" md="4">
        <v-card rounded="xl" border>
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
            placeholder="Nombre o iniciales…"
            prepend-inner-icon="mdi-magnify"
            density="compact" rounded="lg" variant="outlined" hide-details
            style="max-width:280px" clearable
          />
          <v-select
            v-model="filtroActivo"
            :items="[{title:'Activos',value:'activos'},{title:'Inactivos',value:'inactivos'},{title:'Todos',value:'todos'}]"
            density="compact" rounded="lg" variant="outlined" hide-details
            style="max-width:160px"
          />
          <v-spacer />
          <v-btn color="primary" prepend-icon="mdi-plus" rounded="lg" @click="abrirNuevo">
            Nuevo instructor
          </v-btn>
        </div>
      </v-card-text>
    </v-card>

  <!-- Tabla -->
  <v-card rounded="xl" elevation="0" border>

    <v-data-table
      :headers="headers"
      :items="listaFiltrada"
      :loading="cargando"
      items-per-page="25"
      no-data-text="No hay instructores registrados"
      hover
    >
      <!-- Nombre con avatar de iniciales -->
      <template #item.nombre="{ item }">
        <div class="d-flex align-center gap-3 py-1">
          <v-avatar
            size="36" rounded="lg"
            :style="{
              background: item.activo ? 'rgba(14,59,51,0.12)' : 'rgba(0,0,0,0.06)',
              color: item.activo ? '#0e3b33' : '#9ca3af',
              fontSize: '12px', fontWeight: 700
            }"
          >{{ item.iniciales }}</v-avatar>
          <div>
            <div :style="{ fontWeight: 600, fontSize: '13.5px', color: item.activo ? '#111827' : '#9ca3af' }">
              {{ item.nombre }}
            </div>
            <div v-if="item.email" style="font-size:11.5px;color:#6b7280">{{ item.email }}</div>
          </div>
        </div>
      </template>

      <!-- Teléfono -->
      <template #item.telefono="{ item }">
        <span style="font-size:13px;color:#374151">{{ item.telefono || '—' }}</span>
      </template>

      <!-- Niveles -->
      <template #item.niveles="{ item }">
        <div class="d-flex flex-wrap gap-1 py-1">
          <v-chip
            v-for="n in item.niveles" :key="n"
            size="x-small" variant="tonal" color="primary"
            style="font-size:10.5px"
          >{{ n }}</v-chip>
        </div>
      </template>

      <!-- Estado -->
      <template #item.activo="{ item }">
        <v-chip
          :color="item.activo ? 'success' : 'default'"
          size="small" variant="tonal"
        >
          <v-icon start :icon="item.activo ? 'mdi-check-circle-outline' : 'mdi-cancel'" size="14" />
          {{ item.activo ? 'Activo' : 'Inactivo' }}
        </v-chip>
      </template>

      <!-- Acciones -->
      <template #item.acciones="{ item }">
        <div class="d-flex align-center justify-end ga-1">
          <v-btn
            icon="mdi-pencil-outline" size="small" variant="text"
            color="grey" title="Editar instructor"
            :disabled="!!procesandoId"
            @click="abrirEditar(item)"
          />
          <v-btn
            v-if="item.activo"
            icon="mdi-account-off-outline" size="small" variant="text"
            color="error" title="Inactivar instructor"
            :loading="procesandoId === item.id"
            :disabled="!!procesandoId"
            @click="inactivar(item)"
          />
          <template v-else>
            <v-btn
              icon="mdi-account-check-outline" size="small" variant="text"
              color="success" title="Reactivar instructor"
              :loading="procesandoId === item.id"
              :disabled="!!procesandoId"
              @click="reactivar(item)"
            />
            <v-btn
              icon="mdi-delete-outline" size="small" variant="text"
              color="error" title="Eliminar definitivamente"
              :disabled="!!procesandoId"
              @click="eliminar(item)"
            />
          </template>
        </div>
      </template>
    </v-data-table>
  </v-card>

  <!-- ── Diálogo crear / editar ─────────────────────────────────────────────── -->
  <v-dialog v-model="dialogOpen" max-width="520" persistent>
    <v-card rounded="xl">
      <!-- Header con gradiente -->
      <div style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);padding:20px 24px;border-radius:16px 16px 0 0;border-bottom:1px solid #a7f3d0" class="d-flex align-center justify-space-between">
        <div>
          <div style="color:#059669;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin-bottom:4px">
            {{ editandoId ? 'Editar instructor' : 'Nuevo instructor' }}
          </div>
          <div style="color:#064e3b;font-size:18px;font-weight:700;font-family:'Fraunces',serif">
            {{ editandoId ? form.nombre : 'Registrar instructor' }}
          </div>
        </div>
        <v-btn icon="mdi-close" variant="text" color="#064e3b" @click="dialogOpen = false" />
      </div>

      <v-form ref="formRef">
        <v-card-text class="pa-5">
          <v-row dense>
            <v-col cols="12"><p class="section-label">Datos personales</p></v-col>

            <v-col cols="12">
              <v-text-field
                v-model="form.nombre"
                label="Nombre completo *"
                variant="outlined" density="compact" rounded="lg"
                :rules="[v => !!v || 'Requerido', v => v.length >= 2 || 'Mínimo 2 caracteres']"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="form.telefono"
                label="Teléfono"
                prepend-inner-icon="mdi-phone-outline"
                variant="outlined" density="compact" rounded="lg"
                :rules="[v => !v || /^\d{10}$/.test(v) || '10 dígitos']"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field
                v-model="form.email"
                label="Correo electrónico"
                prepend-inner-icon="mdi-email-outline"
                variant="outlined" density="compact" rounded="lg"
                :rules="[v => !v || /.+@.+\..+/.test(v) || 'Email inválido']"
              />
            </v-col>

            <v-col cols="12"><p class="section-label mt-2">Horario disponible</p></v-col>
            <v-col cols="6">
              <v-text-field
                v-model="form.dispHoraInicio"
                label="Desde"
                type="time"
                variant="outlined" density="compact" rounded="lg" hide-details
              />
            </v-col>
            <v-col cols="6">
              <v-text-field
                v-model="form.dispHoraFin"
                label="Hasta"
                type="time"
                variant="outlined" density="compact" rounded="lg" hide-details
              />
            </v-col>
            <v-col cols="12">
              <div style="font-size:11px;color:#9ca3af">
                Define el rango en que el instructor puede recibir clases. Vacío = disponible toda la cuadrícula.
              </div>
            </v-col>

            <v-col cols="12"><p class="section-label mt-2">Modalidades que imparte *</p></v-col>
            <v-col cols="12">
              <div class="d-flex flex-column ga-1">
                <v-checkbox
                  v-for="m in modalidades" :key="m.clave"
                  v-model="form.niveles" :value="m.clave"
                  :label="`${m.clave} — ${m.label}`"
                  density="compact" color="#0e3b33" hide-details
                />
              </div>
              <div v-if="errorMsg === 'Selecciona al menos una modalidad'"
                style="font-size:12px;color:#dc2626;margin-top:4px">
                {{ errorMsg }}
              </div>
            </v-col>


            <v-col v-if="errorMsg && errorMsg !== 'Selecciona al menos una modalidad'" cols="12">
              <v-alert type="error" density="compact" rounded="lg" variant="tonal">{{ errorMsg }}</v-alert>
            </v-col>
          </v-row>
        </v-card-text>
      </v-form>

      <v-divider />
      <v-card-actions class="pa-4 ga-2 justify-end">
        <v-btn variant="text" @click="dialogOpen = false">Cancelar</v-btn>
        <v-btn color="#0e3b33" variant="flat" rounded="lg" :loading="guardando" @click="guardar">
          {{ editandoId ? 'Guardar cambios' : 'Crear instructor' }}
        </v-btn>
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
</style>
