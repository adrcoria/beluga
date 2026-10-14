<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useAlumnos, type AlumnoResumen } from '@/composables/useAlumnos'

const alumnosApi = useAlumnos()

const lista    = ref<AlumnoResumen[]>([])
const cargando = ref(true)
const mes      = ref(new Date().getMonth()) // 0–11

const MESES = [
  'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
]
const mesesOpts = MESES.map((label, value) => ({ label, value }))

onMounted(async () => {
  try {
    lista.value = await alumnosApi.listar({ status: 'activo' })
  } catch {}
  finally { cargando.value = false }
})

interface Cumple {
  folio: string
  nombre: string
  dia: number
  mes: number
  telefono?: string
  cumple: number | null // años que cumple este mes (null si no hay año)
}

const cumpleaneros = computed<Cumple[]>(() => {
  const hoyAnio = new Date().getFullYear()
  return lista.value
    .filter(a => !!a.fechaNacimiento)
    .map(a => {
      const d = new Date(a.fechaNacimiento + 'T12:00:00')
      return {
        folio: a.folio,
        nombre: a.nombre,
        dia: d.getDate(),
        mes: d.getMonth(),
        telefono: a.contacto?.telefono,
        cumple: d.getFullYear() ? hoyAnio - d.getFullYear() : null,
      }
    })
    .filter(c => c.mes === mes.value)
    .sort((a, b) => a.dia - b.dia)
})

const hoyDia = new Date().getDate()
const hoyMes = new Date().getMonth()
const esHoy = (dia: number) => mes.value === hoyMes && dia === hoyDia

const waLink = (tel?: string, nombre = '') => {
  if (!tel) return ''
  const num = tel.replace(/\D/g, '').slice(-10)
  const msg = encodeURIComponent(`¡Feliz cumpleaños, ${nombre}! 🎉🐳 Te desea todo el equipo de Acuática Beluga.`)
  return `https://wa.me/52${num}?text=${msg}`
}
</script>

<template>
  <div class="d-flex flex-column ga-4">
    <v-card rounded="xl" border>
      <v-card-text class="pa-4 d-flex flex-wrap align-center ga-4">
        <div class="d-flex align-center ga-2">
          <v-icon icon="mdi-cake-variant" color="#e11d8f" size="28" />
          <div>
            <div style="font-weight:700;color:#0a1e6e;font-size:16px;font-family:'Fraunces',serif">Cumpleañeros</div>
            <div style="font-size:12px;color:#6b7280">{{ cumpleaneros.length }} en {{ MESES[mes] }}</div>
          </div>
        </div>
        <v-spacer />
        <v-select
          v-model="mes"
          :items="mesesOpts"
          item-title="label"
          item-value="value"
          label="Mes"
          density="compact" variant="outlined" rounded="lg" hide-details
          style="max-width:200px"
        />
      </v-card-text>
    </v-card>

    <v-card rounded="xl" border>
      <div v-if="cargando" class="text-center py-10">
        <v-progress-circular indeterminate color="primary" size="32" />
      </div>
      <div v-else-if="!cumpleaneros.length" class="text-center py-10 text-medium-emphasis" style="font-size:14px">
        Sin cumpleañeros registrados en {{ MESES[mes] }}.
      </div>
      <v-table v-else density="comfortable" hover>
        <thead>
          <tr>
            <th class="col-header" style="width:80px">Día</th>
            <th class="col-header">Alumno</th>
            <th class="col-header">Folio</th>
            <th class="col-header">Cumple</th>
            <th class="col-header" style="width:120px">Felicitar</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in cumpleaneros" :key="c.folio" :style="esHoy(c.dia) ? 'background:#fdf2f8' : ''">
            <td>
              <div class="dia-chip" :class="{ hoy: esHoy(c.dia) }">
                {{ c.dia }}
                <v-icon v-if="esHoy(c.dia)" icon="mdi-party-popper" size="13" class="ml-1" />
              </div>
            </td>
            <td style="font-weight:600;color:#0a1e6e">{{ c.nombre }}</td>
            <td style="color:#6b7280">#{{ c.folio }}</td>
            <td style="color:#6b7280">{{ c.cumple !== null ? `${c.cumple} años` : '—' }}</td>
            <td>
              <v-btn
                v-if="c.telefono"
                :href="waLink(c.telefono, c.nombre)" target="_blank"
                icon="mdi-whatsapp" size="x-small" variant="text" color="#16a34a"
              />
              <span v-else style="font-size:12px;color:#cbd5e1">—</span>
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>
  </div>
</template>

<style scoped>
.col-header { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #9ca3af; font-weight: 700; }
.dia-chip {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 34px; height: 34px; border-radius: 10px; padding: 0 8px;
  background: #eef2f7; color: #0a1e6e; font-weight: 800; font-size: 14px;
}
.dia-chip.hoy { background: #fce7f3; color: #9d174d; }
</style>
