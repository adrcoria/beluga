<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAlumnos, type AlumnoResumen } from '@/composables/useAlumnos'
import { useCobranza } from '@/composables/useCobranza'

const router     = useRouter()
const alumnos    = useAlumnos()
const cobranzaApi = useCobranza()

const lista              = ref<AlumnoResumen[]>([])
const loading            = ref(true)
const procesandoCobros   = ref(true)
const cobrosGenerados    = ref(0)

onMounted(async () => {
  // Ambas peticiones en paralelo
  await Promise.allSettled([
    alumnos.listar({ status: 'activo' }).then(r => { lista.value = r }).finally(() => { loading.value = false }),
    cobranzaApi.generarCobros().then(r => { cobrosGenerados.value = r.generados }),
  ])
  procesandoCobros.value = false
})

const activos        = () => lista.value.length
const tocaPago       = () => lista.value.filter(a => a.tocaPago && a.diasMora === 0)
const vencidos       = () => lista.value.filter(a => a.diasMora > 0)
const sinInscripcion = () => lista.value.filter(a => !a.inscripcionPagada)
const alertas        = () => [...vencidos(), ...tocaPago()].slice(0, 6)

const iniciales = (n: string) => n.split(' ').slice(0,2).map(x => x[0]).join('').toUpperCase()

const fmt = (n: number) => new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN',minimumFractionDigits:0}).format(n)
const fmtFecha = (f: string) => new Date(f+'T12:00:00').toLocaleDateString('es-MX',{day:'2-digit',month:'short',year:'numeric'})

const acciones = [
  { label:'Nuevo alumno',    sub:'Registrar en el sistema',    path:'/alumnos',      color:'#2fe6b0', bg:'#2fe6b010', icon:'mdi-account-plus-outline' },
  { label:'Registrar pago',  sub:'Mensualidad o inscripción',  path:'/cobranza',     color:'#1a3fe8', bg:'#1a3fe810', icon:'mdi-cash-plus' },
  { label:'Declarar inhábil',sub:'Genera reposiciones auto',   path:'/reposiciones', color:'#f59e0b', bg:'#f59e0b10', icon:'mdi-calendar-remove-outline' },
  { label:'Ver reportes',    sub:'Financieros y operativos',   path:'/reportes',     color:'#8b5cf6', bg:'#8b5cf610', icon:'mdi-chart-bar' },
]
</script>

<template>
  <div class="d-flex flex-column ga-5">

    <!-- Banner: procesando cobros -->
    <v-alert
      v-if="procesandoCobros"
      density="compact"
      variant="tonal"
      color="primary"
      rounded="lg"
      icon="mdi-refresh"
    >
      <div class="d-flex align-center ga-3">
        <v-progress-circular indeterminate size="16" width="2" color="primary" />
        <span style="font-size:13px">Generando cobros del día…</span>
      </div>
    </v-alert>

    <v-alert
      v-else-if="cobrosGenerados > 0"
      density="compact"
      variant="tonal"
      color="success"
      rounded="lg"
      icon="mdi-check-circle-outline"
      closable
    >
      <span style="font-size:13px">
        <strong>{{ cobrosGenerados }} cobro{{ cobrosGenerados !== 1 ? 's' : '' }}</strong> generado{{ cobrosGenerados !== 1 ? 's' : '' }} para hoy
      </span>
    </v-alert>

    <!-- Stats -->
    <v-row dense>
      <v-col cols="12" sm="6" md="3">
        <v-card rounded="xl" border>
          <v-card-text>
            <div class="text-caption font-weight-bold text-uppercase text-medium-emphasis mb-2" style="letter-spacing:.08em">Alumnos Activos</div>
            <div class="text-h3 font-weight-black" style="color:#0a1e6e;font-family:'Fraunces',serif">{{ loading ? '—' : activos() }}</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card rounded="xl" border style="cursor:pointer" @click="router.push('/alumnos')">
          <v-card-text>
            <div class="text-caption font-weight-bold text-uppercase text-medium-emphasis mb-2" style="letter-spacing:.08em">Toca Pago Hoy</div>
            <div class="text-h3 font-weight-black" style="color:#d97706;font-family:'Fraunces',serif">{{ loading ? '—' : tocaPago().length }}</div>
            <div class="text-caption text-medium-emphasis mt-1">Revisa cobranza</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card rounded="xl" border style="cursor:pointer" @click="router.push('/alumnos')">
          <v-card-text>
            <div class="text-caption font-weight-bold text-uppercase text-medium-emphasis mb-2" style="letter-spacing:.08em">Cartera Vencida</div>
            <div class="text-h3 font-weight-black" style="color:#dc2626;font-family:'Fraunces',serif">{{ loading ? '—' : vencidos().length }}</div>
            <div class="text-caption text-medium-emphasis mt-1">Alumnos con mora</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card rounded="xl" border>
          <v-card-text>
            <div class="text-caption font-weight-bold text-uppercase text-medium-emphasis mb-2" style="letter-spacing:.08em">Sin Inscripción</div>
            <div class="text-h3 font-weight-black" style="color:#374151;font-family:'Fraunces',serif">{{ loading ? '—' : sinInscripcion().length }}</div>
            <div class="text-caption text-medium-emphasis mt-1">Por cobrar única</div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- Alertas + Resumen -->
    <v-row dense>
      <v-col cols="12" md="8">
        <v-card rounded="xl" border height="100%">
          <v-card-title class="d-flex align-center justify-space-between pa-5 pb-3">
            <span style="font-size:15px;font-weight:700;color:#0a1e6e">Alertas operativas del día</span>
            <v-btn variant="text" size="small" color="primary" @click="router.push('/alumnos')">Ver todas →</v-btn>
          </v-card-title>
          <v-card-text class="pa-3 pt-0">
            <div v-if="loading" class="text-center py-10 text-medium-emphasis">Cargando…</div>
            <div v-else-if="!alertas().length" class="text-center py-10 text-medium-emphasis">Sin alertas hoy 🎉</div>
            <v-list v-else lines="two" class="pa-0">
              <v-list-item
                v-for="a in alertas()"
                :key="a.folio"
                rounded="lg"
                class="mb-1"
              >
                <template #prepend>
                  <v-avatar :color="a.diasMora > 0 ? '#fee2e2' : '#fef3c7'" size="38">
                    <span :style="{ color: a.diasMora > 0 ? '#b91c1c' : '#92400e', fontSize:'12px', fontWeight:'700' }">{{ iniciales(a.nombre) }}</span>
                  </v-avatar>
                </template>
                <v-list-item-title style="font-size:13px;font-weight:600">
                  {{ a.nombre }}
                  <span style="font-weight:400;color:#9ca3af;font-size:12px"> · folio {{ a.folio }}</span>
                </v-list-item-title>
                <v-list-item-subtitle style="font-size:11.5px">
                  Vence {{ fmtFecha(a.vencimiento) }} · {{ a.modalidad }} · {{ a.plan.clasesSemana }}x/sem · {{ fmt(a.montoConDescuento) }}
                  <v-chip v-if="a.diasMora > 0" size="x-small" color="error" variant="tonal" class="ml-1">{{ a.diasMora }}d mora</v-chip>
                </v-list-item-subtitle>
                <template #append>
                  <v-btn size="small" rounded="lg" style="background:#2fe6b0;color:#0a1e6e;font-weight:700" @click="router.push('/cobranza')">
                    Cobrar
                  </v-btn>
                </template>
              </v-list-item>
            </v-list>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="4">
        <v-card rounded="xl" border height="100%">
          <v-card-title class="pa-5 pb-3" style="font-size:15px;font-weight:700;color:#0a1e6e">Resumen</v-card-title>
          <v-card-text class="pa-5 pt-0 d-flex flex-column ga-3">
            <div v-for="item in [
              { label:'Alumnos activos', val: activos(),            color:'#2fe6b0' },
              { label:'Toca pago',       val: tocaPago().length,    color:'#d97706' },
              { label:'Con mora',        val: vencidos().length,    color:'#dc2626' },
              { label:'Sin inscripción', val: sinInscripcion().length, color:'#374151' },
            ]" :key="item.label" class="d-flex align-center justify-space-between">
              <span style="font-size:13px;color:#6b7280">{{ item.label }}</span>
              <span :style="{ color: item.color, fontSize:'20px', fontWeight:'800', fontFamily:'Fraunces,serif' }">{{ loading ? '—' : item.val }}</span>
            </div>
          </v-card-text>
          <v-card-actions class="pa-4 pt-0">
            <v-btn block rounded="lg" style="background:#0a1e6e;color:#2fe6b0;font-weight:700" @click="router.push('/alumnos')">
              Ver todos los alumnos
            </v-btn>
          </v-card-actions>
        </v-card>
      </v-col>
    </v-row>

    <!-- Acciones rápidas -->
    <v-row dense>
      <v-col v-for="ac in acciones" :key="ac.label" cols="12" sm="6" md="3">
        <v-card rounded="xl" border style="cursor:pointer" @click="router.push(ac.path)" class="accion-card">
          <v-card-text class="d-flex align-center ga-4">
            <div :style="{ background: ac.bg, borderRadius:'12px', width:'44px', height:'44px', display:'flex', alignItems:'center', justifyContent:'center', flexShrink:0 }">
              <v-icon :icon="ac.icon" :color="ac.color" size="22" />
            </div>
            <div>
              <div style="font-size:13px;font-weight:700;color:#111827">{{ ac.label }}</div>
              <div style="font-size:11px;color:#9ca3af;margin-top:1px">{{ ac.sub }}</div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

  </div>
</template>

<style scoped>
.accion-card { transition: box-shadow .15s, transform .15s; }
.accion-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08) !important; transform: translateY(-2px); }
</style>
