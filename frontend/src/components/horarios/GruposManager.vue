<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useGrupos, esModalidadGrupo, type Grupo } from '@/composables/useGrupos'
import { useAlumnos, type AlumnoResumen } from '@/composables/useAlumnos'
import { useSwal } from '@/composables/useSwal'
import { useSucursalStore } from '@/stores/sucursal'

const props = defineProps<{ modelValue: boolean }>()
const emit  = defineEmits<{ 'update:modelValue': [boolean]; 'actualizado': [] }>()

const gruposApi     = useGrupos()
const alumnosApi    = useAlumnos()
const sucursalStore = useSucursalStore()
const { exito, error: notifError, confirmar } = useSwal()

const abierto = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const grupos        = ref<Grupo[]>([])
const alumnosGrupal = ref<AlumnoResumen[]>([])
const cargando      = ref(false)

// Form de edición/alta inline
const editando = ref<Grupo | null>(null)
const nuevoNombre = ref('')
const nuevoMiembros = ref<string[]>([])
const guardando = ref(false)

const sucursalId = computed(() => sucursalStore.sucursalActivaId)

async function cargar() {
  cargando.value = true
  try {
    grupos.value = await gruposApi.listar(sucursalId.value)
    const activos = await alumnosApi.listar({ status: 'activo' })
    alumnosGrupal.value = activos.filter(a => esModalidadGrupo(a.modalidad))
  } catch (e: any) {
    notifError('Error al cargar grupos', e.message)
  } finally {
    cargando.value = false
  }
}

watch(abierto, (v) => { if (v) { resetForm(); cargar() } })

const alumnosOpts = computed(() => alumnosGrupal.value.map(a => ({
  title: `${a.nombre} · #${a.folio}`, value: a.folio,
})))

function resetForm() {
  editando.value = null
  nuevoNombre.value = ''
  nuevoMiembros.value = []
}

function editar(g: Grupo) {
  editando.value = g
  nuevoNombre.value = g.nombre
  nuevoMiembros.value = g.alumnos.map(a => a.folio)
}

async function guardar() {
  if (!nuevoNombre.value.trim()) { notifError('Ponle un nombre al grupo'); return }
  guardando.value = true
  try {
    if (editando.value) {
      await gruposApi.actualizar(editando.value.id, { nombre: nuevoNombre.value.trim(), alumnos: nuevoMiembros.value })
      exito('Grupo actualizado')
    } else {
      await gruposApi.crear({ nombre: nuevoNombre.value.trim(), sucursalId: sucursalId.value, alumnos: nuevoMiembros.value })
      exito('Grupo creado')
    }
    resetForm()
    await cargar()
    emit('actualizado')
  } catch (e: any) {
    notifError('No se pudo guardar', e.message)
  } finally {
    guardando.value = false
  }
}

async function eliminar(g: Grupo) {
  const { isConfirmed } = await confirmar(`¿Eliminar el grupo "${g.nombre}"?`, 'Dejará de estar disponible para asignar.')
  if (!isConfirmed) return
  try {
    await gruposApi.eliminar(g.id)
    await cargar()
    emit('actualizado')
    exito('Grupo eliminado')
  } catch (e: any) {
    notifError('No se pudo eliminar', e.message)
  }
}
</script>

<template>
  <v-dialog v-model="abierto" max-width="640" scrollable>
    <v-card rounded="xl">
      <div class="modal-head">
        <div>
          <div class="modal-eyebrow">Horarios · Grupos</div>
          <div class="modal-title">Grupos</div>
        </div>
        <v-btn icon="mdi-close" variant="text" color="#0a1e6e" @click="abierto = false" />
      </div>

      <v-card-text class="pa-5">
        <!-- Form alta/edición -->
        <div class="form-grupo">
          <div class="text-caption text-medium-emphasis mb-2">
            {{ editando ? `Editando: ${editando.nombre}` : 'Nuevo grupo' }}
          </div>
          <v-row dense>
            <v-col cols="12" sm="5">
              <v-text-field v-model="nuevoNombre" label="Nombre del grupo *"
                density="compact" variant="outlined" rounded="lg" hide-details />
            </v-col>
            <v-col cols="12" sm="7">
              <v-autocomplete
                v-model="nuevoMiembros"
                :items="alumnosOpts"
                label="Alumnos"
                density="compact" variant="outlined" rounded="lg" hide-details
                multiple chips closable-chips
                no-data-text="Sin alumnos de grupo (GRUPAL o MINI)"
              />
            </v-col>
          </v-row>
          <div class="d-flex justify-end ga-2 mt-3">
            <v-btn v-if="editando" variant="text" size="small" @click="resetForm">Cancelar edición</v-btn>
            <v-btn color="primary" rounded="lg" size="small" :loading="guardando" @click="guardar">
              {{ editando ? 'Guardar cambios' : 'Crear grupo' }}
            </v-btn>
          </div>
        </div>

        <v-divider class="my-4" />

        <!-- Lista de grupos -->
        <div v-if="cargando" class="text-center py-6">
          <v-progress-circular indeterminate color="primary" size="28" />
        </div>
        <div v-else-if="!grupos.length" class="text-center py-6 text-medium-emphasis" style="font-size:13px">
          Aún no hay grupos. Crea el primero arriba.
        </div>
        <div v-else class="d-flex flex-column ga-2">
          <div v-for="g in grupos" :key="g.id" class="grupo-row">
            <div>
              <div style="font-weight:700;color:#0a1e6e;font-size:14px">{{ g.nombre }}</div>
              <div style="font-size:12px;color:#6b7280">
                {{ g.alumnos.length }} alumno{{ g.alumnos.length !== 1 ? 's' : '' }}
                <template v-if="g.alumnos.length"> · {{ g.alumnos.map(a => a.nombre).join(', ') }}</template>
              </div>
            </div>
            <div class="d-flex ga-1">
              <v-btn icon="mdi-pencil-outline" size="x-small" variant="text" color="grey" @click="editar(g)" />
              <v-btn icon="mdi-trash-can-outline" size="x-small" variant="text" color="error" @click="eliminar(g)" />
            </div>
          </div>
        </div>
      </v-card-text>

      <v-divider />
      <v-card-actions class="pa-4 justify-end">
        <v-btn color="primary" variant="text" @click="abierto = false">Cerrar</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.form-grupo {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 14px 16px;
}
.grupo-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border: 1px solid #eef2f7;
  border-radius: 10px;
  padding: 10px 14px;
}
.modal-head {
  background: linear-gradient(135deg,#e8f4ff,#dbeafe);
  padding: 18px 22px;
  border-radius: 16px 16px 0 0;
  border-bottom: 1px solid #b8d4ff;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.modal-eyebrow { color:#1a3fe8; font-size:11px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; margin-bottom:3px; }
.modal-title { color:#0a1e6e; font-size:18px; font-weight:700; font-family:'Fraunces',serif; }
</style>
