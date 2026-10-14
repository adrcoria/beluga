<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useUsuarios, rolInfo, ROLES, type Usuario } from '@/composables/useUsuarios'
import { useSwal } from '@/composables/useSwal'

const { exito, confirmar } = useSwal()

const { listar, crear, actualizar, eliminar } = useUsuarios()

const usuarios  = ref<Usuario[]>([])
const cargando  = ref(true)
const error     = ref('')
/** Mostrar también a los dados de baja, para reactivarlos o eliminarlos. */
const verInactivos = ref(false)

// ── Modal ─────────────────────────────────────────────────────────────────
const dialog      = ref(false)
const guardando   = ref(false)
const modoEdicion = ref(false)
const editandoId  = ref('')
const formRef     = ref()

const formVacio = () => ({
  nombre:     '',
  email:      '',
  password:   '',
  rol:        'operativo' as Usuario['rol'],
  todasSuc:   true,
  activo:     true,
})
const form = ref(formVacio())

// ── Carga ─────────────────────────────────────────────────────────────────
async function cargar() {
  cargando.value = true; error.value = ''
  try { usuarios.value = await listar(verInactivos.value) }
  catch (e: any) { error.value = e.message }
  finally { cargando.value = false }
}
onMounted(cargar)
watch(verInactivos, cargar)

// ── Abrir modal ───────────────────────────────────────────────────────────
function abrirNuevo() {
  modoEdicion.value = false
  editandoId.value  = ''
  form.value = formVacio()
  dialog.value = true
}

function abrirEditar(u: Usuario) {
  modoEdicion.value = true
  editandoId.value  = u.id
  form.value = {
    nombre:   u.nombre,
    email:    u.email,
    password: '',
    rol:      u.rol,
    todasSuc: u.sucursales.includes('*'),
    activo:   u.activo,
  }
  dialog.value = true
}

// ── Guardar ───────────────────────────────────────────────────────────────
async function guardar() {
  const { valid } = await formRef.value!.validate()
  if (!valid) return

  guardando.value = true
  try {
    if (!modoEdicion.value) {
      await crear({
        nombre:     form.value.nombre,
        email:      form.value.email,
        rol:        form.value.rol,
        sucursales: form.value.todasSuc ? ['*'] : ['beluga_principal'],
      })
    } else {
      await actualizar(editandoId.value, {
        nombre: form.value.nombre,
        rol:    form.value.rol,
      })
    }
    const esEdicion = modoEdicion.value
    const nombre    = form.value.nombre
    dialog.value = false
    await cargar()
    exito(
      esEdicion ? '¡Usuario actualizado!' : '¡Usuario creado!',
      esEdicion
        ? 'Los cambios fueron guardados correctamente.'
        : `Se creó el acceso para ${nombre}. La contraseña temporal es 12345678.`,
      4000,
    )
  } catch (e: any) {
    error.value = e.message
  } finally {
    guardando.value = false
  }
}

// ── Dar de baja / reactivar / eliminar ────────────────────────────────────
async function desactivar(u: Usuario) {
  const { isConfirmed } = await confirmar(
    '¿Dar de baja a este usuario?',
    `${u.nombre} perderá el acceso. Podrás reactivarlo después activando «Ver dados de baja».`,
  )
  if (!isConfirmed) return
  try {
    await actualizar(u.id, { activo: false })
    await cargar()
    exito('Usuario dado de baja', `${u.nombre} ya no puede entrar al sistema.`)
  } catch (e: any) { error.value = e.message }
}

async function reactivar(u: Usuario) {
  const { isConfirmed } = await confirmar(
    '¿Reactivar este usuario?',
    `${u.nombre} volverá a entrar con su rol de ${rolInfo(u.rol).label} y su contraseña anterior.`,
  )
  if (!isConfirmed) return
  try {
    await actualizar(u.id, { activo: true })
    await cargar()
    exito('Usuario reactivado', `${u.nombre} ya puede entrar de nuevo.`)
  } catch (e: any) { error.value = e.message }
}

/**
 * Borrado definitivo. El servidor solo lo permite en inactivos que no dejaron
 * movimientos: quien cobró, condonó o declaró un día inhábil se queda como
 * inactivo, porque esos registros deben seguir diciendo quién los hizo.
 */
async function borrar(u: Usuario) {
  const { isConfirmed } = await confirmar(
    '¿Eliminar definitivamente?',
    `Se borrará el usuario de ${u.nombre} y su correo quedará libre para volver a usarse. Esto no se puede deshacer.`,
  )
  if (!isConfirmed) return
  try {
    await eliminar(u.id)
    await cargar()
    exito('Usuario eliminado', `El correo ${u.email} queda libre.`)
  } catch (e: any) { error.value = e.message }
}

// ── Helpers ───────────────────────────────────────────────────────────────
function iniciales(n: string) {
  return n.split(' ').slice(0, 2).map(x => x[0]).join('').toUpperCase()
}

const reglasReq  = [(v: string) => !!v || 'Campo requerido']
const reglasEmail = [
  (v: string) => !!v || 'Campo requerido',
  (v: string) => /.+@.+\..+/.test(v) || 'Email no válido',
]
</script>

<template>
  <div class="d-flex flex-column ga-4">

    <!-- Toolbar -->
    <v-card rounded="xl" border>
      <v-card-text class="pa-4">
        <div class="d-flex align-center flex-wrap ga-3">
          <div style="font-size:13px;color:#6b7280">
            Gestiona los accesos y roles del equipo
          </div>
          <v-spacer />
          <v-switch
            v-model="verInactivos"
            label="Ver dados de baja"
            color="primary"
            density="compact"
            hide-details
            style="flex:0 0 auto"
          />
          <v-btn color="primary" prepend-icon="mdi-plus" rounded="lg" @click="abrirNuevo">
            Nuevo usuario
          </v-btn>
        </div>
      </v-card-text>
    </v-card>

    <!-- Error -->
    <v-alert v-if="error" type="error" rounded="xl" variant="tonal" closable @click:close="error=''">{{ error }}</v-alert>

    <!-- Tabla -->
    <v-card rounded="xl" border>
      <div v-if="cargando" class="text-center py-16">
        <v-progress-circular indeterminate color="primary" size="40" />
      </div>
      <v-table v-else density="comfortable" hover>
        <thead>
          <tr>
            <th class="col-h">Usuario</th>
            <th class="col-h">Email</th>
            <th class="col-h">Rol</th>
            <th class="col-h">Acceso</th>
            <th class="col-h">Status</th>
            <th class="col-h"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!usuarios.length">
            <td colspan="6" class="text-center py-12 text-medium-emphasis">Sin usuarios registrados</td>
          </tr>
          <tr v-for="u in usuarios" :key="u.id" :style="!u.activo ? 'opacity:.55' : ''">
            <!-- Nombre + avatar -->
            <td>
              <div class="d-flex align-center ga-3">
                <v-avatar size="34" :color="rolInfo(u.rol).bg">
                  <span :style="{ color: rolInfo(u.rol).color, fontWeight:'800', fontSize:'13px' }">
                    {{ iniciales(u.nombre) }}
                  </span>
                </v-avatar>
                <span style="font-weight:600;color:#0a1e6e;font-size:14px">{{ u.nombre }}</span>
              </div>
            </td>
            <!-- Email -->
            <td style="font-size:13px;color:#6b7280">{{ u.email }}</td>
            <!-- Rol -->
            <td>
              <v-chip
                size="x-small"
                label
                variant="flat"
                :style="{ background: rolInfo(u.rol).bg, color: rolInfo(u.rol).color, fontWeight:'700' }"
              >{{ rolInfo(u.rol).label }}</v-chip>
            </td>
            <!-- Sucursales -->
            <td style="font-size:12px;color:#9ca3af">
              {{ u.sucursales.includes('*') ? 'Todas las sucursales' : u.sucursales.join(', ') }}
            </td>
            <!-- Activo -->
            <td>
              <v-chip
                size="x-small"
                label
                :color="u.activo ? 'success' : 'default'"
                variant="tonal"
              >{{ u.activo ? 'Activo' : 'Inactivo' }}</v-chip>
            </td>
            <!-- Acciones -->
            <td>
              <div class="d-flex ga-1 justify-end">
                <v-btn icon size="x-small" variant="text" color="primary" title="Editar" @click="abrirEditar(u)">
                  <v-icon>mdi-pencil-outline</v-icon>
                </v-btn>

                <!-- Activo: se puede dar de baja -->
                <v-btn
                  v-if="u.activo"
                  icon size="x-small" variant="text"
                  color="grey" title="Dar de baja"
                  @click="desactivar(u)"
                >
                  <v-icon>mdi-account-off-outline</v-icon>
                </v-btn>

                <!-- Inactivo: reactivar, y borrar si no dejó movimientos -->
                <template v-else>
                  <v-btn
                    icon size="x-small" variant="text"
                    color="success" title="Reactivar"
                    @click="reactivar(u)"
                  >
                    <v-icon>mdi-account-reactivate-outline</v-icon>
                  </v-btn>
                  <v-btn
                    icon size="x-small" variant="text" color="error"
                    :disabled="!u.puedeEliminarse"
                    :title="u.puedeEliminarse
                      ? 'Eliminar definitivamente'
                      : 'No se puede eliminar: tiene movimientos a su nombre'"
                    @click="borrar(u)"
                  >
                    <v-icon>mdi-trash-can-outline</v-icon>
                  </v-btn>
                </template>
              </div>
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <!-- ── Modal crear / editar ─────────────────────────────────────────── -->
    <v-dialog v-model="dialog" max-width="500" persistent>
      <v-card rounded="xl">
        <!-- Header -->
        <div style="background:linear-gradient(135deg,#e8f4ff,#dbeafe);padding:20px 24px;border-radius:16px 16px 0 0;border-bottom:1px solid #b8d4ff">
          <div style="color:#1a3fe8;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin-bottom:4px">
            {{ modoEdicion ? 'Editar usuario' : 'Nuevo usuario' }}
          </div>
          <div style="color:#0a1e6e;font-size:18px;font-weight:700;font-family:'Fraunces',serif">
            {{ modoEdicion ? form.nombre || '—' : 'Crear acceso al sistema' }}
          </div>
        </div>

        <v-card-text class="pa-6">
          <v-form ref="formRef">
            <v-row dense>
              <v-col cols="12">
                <v-text-field
                  v-model="form.nombre"
                  label="Nombre completo *"
                  variant="outlined"
                  density="compact"
                  rounded="lg"
                  :rules="reglasReq"
                />
              </v-col>
              <!-- Email: solo en creación -->
              <v-col v-if="!modoEdicion" cols="12">
                <v-text-field
                  v-model="form.email"
                  label="Email *"
                  type="email"
                  variant="outlined"
                  density="compact"
                  rounded="lg"
                  :rules="reglasEmail"
                />
              </v-col>
              <!-- Aviso contraseña temporal: solo en creación -->
              <v-col v-if="!modoEdicion" cols="12">
                <v-alert
                  type="info"
                  variant="tonal"
                  density="compact"
                  rounded="lg"
                  icon="mdi-information-outline"
                >
                  Se asignará la contraseña temporal <strong>12345678</strong>.
                  El usuario deberá cambiarla en su primer acceso.
                </v-alert>
              </v-col>
              <v-col cols="12">
                <v-select
                  v-model="form.rol"
                  label="Rol *"
                  :items="ROLES.map(r => ({ title: r.label, value: r.value }))"
                  variant="outlined"
                  density="compact"
                  rounded="lg"
                  :rules="reglasReq"
                />
              </v-col>
              <!-- Sucursales: solo en creación -->
              <v-col v-if="!modoEdicion" cols="12">
                <v-checkbox
                  v-model="form.todasSuc"
                  label="Acceso a todas las sucursales"
                  color="primary"
                  density="compact"
                  hide-details
                />
              </v-col>
            </v-row>
          </v-form>
        </v-card-text>

        <v-card-actions class="pa-4 pt-0 ga-2">
          <v-spacer />
          <v-btn variant="text" rounded="lg" @click="dialog = false">Cancelar</v-btn>
          <v-btn
            color="primary"
            variant="flat"
            rounded="lg"
            :loading="guardando"
            @click="guardar"
          >{{ modoEdicion ? 'Guardar cambios' : 'Crear usuario' }}</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

  </div>
</template>

<style scoped>
.col-h {
  color: #9ca3af;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
}
</style>
