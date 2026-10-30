<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { useCobranza } from '@/composables/useCobranza'
import { apiBase } from '@/composables/useApi'
import logoUrl from '@/assets/logo.jpg'

const router    = useRouter()
const authStore = useAuthStore()
const ui          = useUiStore()
const cobranzaApi = useCobranza()

// Entra al sistema: guarda sesión, sincroniza datos (genera cobros pendientes)
// con overlay y luego navega al dashboard. Si la generación falla, no bloquea.
async function entrar(usuario: any, token: string) {
  authStore.login(usuario, token)
  // Antes de navegar: sin los permisos cargados el router no sabe a qué
  // pantalla mandar a un rol que no tiene Dashboard.
  await authStore.cargarPermisos()

  // Generar cobros es una escritura sobre Cobranza. Los roles que no cobran
  // (instructor, coordinador) recibirían un 403; ni se intenta.
  if (authStore.puedeEditar('cobranza')) {
    ui.setSincronizando(true)
    try {
      await cobranzaApi.generarCobros()
    } catch {
      // ignoramos errores de sincronización; el ingreso no debe bloquearse
    } finally {
      ui.setSincronizando(false)
    }
  }
  router.push('/')
}

// ── Pasos del flujo ───────────────────────────────────────────────────────
type Paso = 'credenciales' | 'otp' | 'nueva-password'
const paso = ref<Paso>('credenciales')

// Paso 1 — credenciales
const email       = ref('')
const password    = ref('')
const mostrarPass = ref(false)
const recordar    = ref(false)

// Paso 2 — OTP
const otpToken    = ref('')
const emailMasked = ref('')
const codigoOtp   = ref('')
const segundos    = ref(300)
let   timerOtp: ReturnType<typeof setInterval> | null = null

const timerLabel = computed(() => {
  const m = Math.floor(segundos.value / 60)
  const s = segundos.value % 60
  return `${m}:${s.toString().padStart(2, '0')}`
})

function iniciarTimer() {
  segundos.value = 300
  if (timerOtp) clearInterval(timerOtp)
  timerOtp = setInterval(() => {
    segundos.value--
    if (segundos.value <= 0) {
      clearInterval(timerOtp!)
      error.value = 'El código expiró. Inicia sesión nuevamente.'
    }
  }, 1000)
}

// Paso 3 — nueva contraseña
const tokenTemporal  = ref('')
const usuarioTemporal = ref<any>(null)
const nuevaPassword  = ref('')
const confirmarPass  = ref('')
const mostrarNueva   = ref(false)
const mostrarConf    = ref(false)

// Común
const error    = ref('')
const cargando = ref(false)

const STORAGE_KEY = 'beluga_remembered'

/**
 * Lee la respuesta sin asumir que es JSON.
 *
 * Cuando el servidor truena antes de llegar a la aplicación (error de PHP,
 * de Apache, una página de error del hosting) la respuesta no es JSON, y
 * `res.json()` reventaba con "Unexpected end of JSON input": un mensaje que
 * no dice nada del problema real. Aquí se muestra el código HTTP y lo que
 * el servidor haya contestado.
 */
async function leerRespuesta(res: Response, fallback: string): Promise<any> {
  const crudo = (await res.text()).trim()

  // Cuerpo vacío: típico de un error fatal de PHP, que no alcanza a escribir nada
  if (crudo === '') {
    throw new Error(
      `El servidor respondió HTTP ${res.status} con el cuerpo vacío. ` +
      'Suele ser un error fatal de PHP; revisa el log de errores del hosting.'
    )
  }

  let data: any
  try {
    data = JSON.parse(crudo)
  } catch {
    // No era JSON: se muestra el texto, sin etiquetas HTML
    const texto = crudo
      .replace(/<script[\s\S]*?<\/script>/gi, ' ')
      .replace(/<style[\s\S]*?<\/style>/gi, ' ')
      .replace(/<[^>]+>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
    throw new Error(
      `El servidor respondió HTTP ${res.status} sin JSON. ` +
      (texto ? `Dice: "${texto.slice(0, 300)}"` : 'La respuesta no traía texto legible.')
    )
  }

  if (!res.ok) throw new Error(data.error ?? `${fallback} (HTTP ${res.status})`)
  return data
}

// ── Paso 1: iniciar sesión ────────────────────────────────────────────────
async function iniciarSesion() {
  error.value = ''; cargando.value = true
  try {
    const res  = await fetch(apiBase + '/api/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: email.value, password: password.value }),
    })
    const data = await leerRespuesta(res, 'Credenciales incorrectas')

    if (recordar.value) {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({ email: email.value, password: password.value }))
    } else {
      localStorage.removeItem(STORAGE_KEY)
    }

    if (data.step === 'login_ok') {
      await entrar(data.usuario, data.token)
      return
    }

    otpToken.value    = data.otpToken
    emailMasked.value = data.emailMasked
    paso.value        = 'otp'
    iniciarTimer()
  } catch (e: any) {
    error.value = e.message
  } finally {
    cargando.value = false
  }
}

// ── Paso 2: verificar OTP ─────────────────────────────────────────────────
async function verificarOtp() {
  error.value = ''; cargando.value = true
  try {
    const res  = await fetch(apiBase + '/api/auth/verificar-otp', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ otpToken: otpToken.value, codigo: codigoOtp.value }),
    })
    const data = await leerRespuesta(res, 'Código incorrecto')

    clearInterval(timerOtp!)

    if (data.debeChangiarPassword) {
      tokenTemporal.value   = data.token
      usuarioTemporal.value = data.usuario
      paso.value            = 'nueva-password'
    } else {
      await entrar(data.usuario, data.token)
    }
  } catch (e: any) {
    error.value = e.message
  } finally {
    cargando.value = false
  }
}

// ── Paso 3: cambiar contraseña ────────────────────────────────────────────
async function cambiarPassword() {
  error.value = ''
  if (nuevaPassword.value !== confirmarPass.value) {
    error.value = 'Las contraseñas no coinciden'; return
  }
  if (nuevaPassword.value.length < 8) {
    error.value = 'Mínimo 8 caracteres'; return
  }
  cargando.value = true
  try {
    const res = await fetch(apiBase + '/api/auth/cambiar-password', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${tokenTemporal.value}`,
      },
      body: JSON.stringify({ passwordNueva: nuevaPassword.value }),
    })
    await leerRespuesta(res, 'Error al cambiar contraseña')

    await entrar(usuarioTemporal.value, tokenTemporal.value)
  } catch (e: any) {
    error.value = e.message
  } finally {
    cargando.value = false
  }
}

function volverACredenciales() {
  paso.value = 'credenciales'
  error.value = ''
  codigoOtp.value = ''
  if (timerOtp) clearInterval(timerOtp)
}

onMounted(() => {
  const raw = localStorage.getItem(STORAGE_KEY)
  if (raw) {
    try {
      const saved = JSON.parse(raw)
      email.value    = saved.email    ?? ''
      password.value = saved.password ?? ''
      recordar.value = true
    } catch {}
  }

  const container = document.getElementById('bubbles-login')
  if (!container) return
  const count = window.innerWidth < 480 ? 8 : 18
  for (let i = 0; i < count; i++) {
    const b = document.createElement('span')
    const size = 6 + Math.random() * 22
    b.style.cssText = `position:absolute;bottom:-80px;border-radius:50%;
      background:radial-gradient(circle at 30% 30%,rgba(255,255,255,0.9),rgba(184,212,255,0.2) 60%);
      border:1px solid rgba(255,255,255,0.5);width:${size}px;height:${size}px;
      left:${Math.random()*100}%;animation:rise ${10+Math.random()*18}s linear infinite;
      animation-delay:${-Math.random()*20}s;opacity:0;`
    container.appendChild(b)
  }
})
</script>

<template>
  <div class="login-root">
    <div id="bubbles-login" class="bubbles-layer" aria-hidden="true"></div>

    <v-card class="login-card" rounded="xl" elevation="8">

      <!-- Logo -->
      <div class="d-flex justify-center pt-8 pb-2">
        <div class="logo-circle">
          <img :src="logoUrl" alt="Acuática Beluga" />
        </div>
      </div>

      <!-- Encabezado -->
      <v-card-title class="text-center pb-0 pt-3" style="font-family:'Fraunces',serif;font-size:24px;font-weight:400;color:#0a1e6e">
        Acuática <em style="color:#1a3fe8">Beluga</em>
      </v-card-title>
      <div class="text-center pb-1" style="font-family:'Fraunces',serif;font-style:italic;color:#1a3fe8;font-size:14px">
        ¡ Nadar es vida !
      </div>
      <div class="text-center pb-5" style="font-size:11px;text-transform:uppercase;letter-spacing:0.1em;color:#9ca3af;font-weight:600">
        Sistema de Administración
      </div>

      <!-- ── Paso 1: Credenciales ─────────────────────────────────────── -->
      <v-card-text v-if="paso === 'credenciales'" class="px-8 pb-6">
        <v-form @submit.prevent="iniciarSesion">
          <v-text-field
            v-model="email"
            label="Correo electrónico"
            type="email"
            prepend-inner-icon="mdi-email-outline"
            autocomplete="email"
            required
            class="mb-3"
          />
          <v-text-field
            v-model="password"
            label="Contraseña"
            :type="mostrarPass ? 'text' : 'password'"
            prepend-inner-icon="mdi-lock-outline"
            :append-inner-icon="mostrarPass ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
            @click:append-inner="mostrarPass = !mostrarPass"
            autocomplete="current-password"
            required
            class="mb-2"
          />
          <v-checkbox
            v-model="recordar"
            label="Recordar mis datos"
            density="compact"
            color="primary"
            hide-details
            class="mb-4"
          />
          <v-alert v-if="error" type="error" density="compact" rounded="lg" class="mb-4" variant="tonal">{{ error }}</v-alert>
          <v-btn
            type="submit"
            :loading="cargando"
            block size="large" rounded="lg"
            style="background:linear-gradient(135deg,#1a3fe8,#0a1e6e);color:#fff;font-weight:700;letter-spacing:0.03em"
          >Ingresar</v-btn>
        </v-form>
      </v-card-text>

      <!-- ── Paso 2: Código OTP ───────────────────────────────────────── -->
      <v-card-text v-else-if="paso === 'otp'" class="px-8 pb-6">
        <div class="text-center mb-5">
          <v-icon icon="mdi-email-fast-outline" size="40" color="primary" class="mb-2" />
          <div style="font-size:14px;font-weight:600;color:#0a1e6e">Revisa tu correo</div>
          <div style="font-size:13px;color:#6b7280;margin-top:4px">
            Enviamos un código de 6 dígitos a<br>
            <strong style="color:#1a3fe8">{{ emailMasked }}</strong>
          </div>
        </div>

        <v-form @submit.prevent="verificarOtp">
          <v-text-field
            v-model="codigoOtp"
            label="Código de verificación"
            prepend-inner-icon="mdi-shield-key-outline"
            inputmode="numeric"
            maxlength="6"
            autocomplete="one-time-code"
            required
            class="mb-1"
            style="letter-spacing:8px;font-size:22px;font-weight:700;text-align:center"
          />
          <div class="text-center mb-4" style="font-size:12px" :style="{ color: segundos <= 30 ? '#dc2626' : '#9ca3af' }">
            <v-icon icon="mdi-clock-outline" size="14" class="mr-1" />
            Expira en {{ timerLabel }}
          </div>
          <v-alert v-if="error" type="error" density="compact" rounded="lg" class="mb-4" variant="tonal">{{ error }}</v-alert>
          <v-btn
            type="submit"
            :loading="cargando"
            :disabled="codigoOtp.length < 6 || segundos <= 0"
            block size="large" rounded="lg"
            color="primary"
          >Verificar código</v-btn>
          <v-btn
            variant="text" block class="mt-2" size="small" color="grey"
            @click="volverACredenciales"
          >← Volver</v-btn>
        </v-form>
      </v-card-text>

      <!-- ── Paso 3: Nueva contraseña ───────────────────────────────────── -->
      <v-card-text v-else class="px-8 pb-6">
        <div class="text-center mb-5">
          <v-icon icon="mdi-lock-reset" size="40" color="warning" class="mb-2" />
          <div style="font-size:14px;font-weight:700;color:#0a1e6e">Establece tu contraseña</div>
          <div style="font-size:13px;color:#6b7280;margin-top:4px">
            Es tu primer acceso. Crea una contraseña segura.
          </div>
        </div>

        <v-form @submit.prevent="cambiarPassword">
          <v-text-field
            v-model="nuevaPassword"
            label="Nueva contraseña"
            :type="mostrarNueva ? 'text' : 'password'"
            prepend-inner-icon="mdi-lock-outline"
            :append-inner-icon="mostrarNueva ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
            @click:append-inner="mostrarNueva = !mostrarNueva"
            autocomplete="new-password"
            hint="Mínimo 8 caracteres"
            persistent-hint
            required
            class="mb-3"
          />
          <v-text-field
            v-model="confirmarPass"
            label="Confirmar contraseña"
            :type="mostrarConf ? 'text' : 'password'"
            prepend-inner-icon="mdi-lock-check-outline"
            :append-inner-icon="mostrarConf ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
            @click:append-inner="mostrarConf = !mostrarConf"
            autocomplete="new-password"
            required
            class="mb-4"
          />
          <v-alert v-if="error" type="error" density="compact" rounded="lg" class="mb-4" variant="tonal">{{ error }}</v-alert>
          <v-btn
            type="submit"
            :loading="cargando"
            :disabled="!nuevaPassword || !confirmarPass"
            block size="large" rounded="lg"
            style="background:linear-gradient(135deg,#1a3fe8,#0a1e6e);color:#fff;font-weight:700"
          >Guardar y entrar</v-btn>
        </v-form>
      </v-card-text>

    </v-card>

    <!-- Olas -->
    <div class="waves" aria-hidden="true">
      <svg class="wave3" viewBox="0 0 2400 320" preserveAspectRatio="none">
        <path d="M0,180 C200,120 400,240 600,180 C800,120 1000,240 1200,180 C1400,120 1600,240 1800,180 C2000,120 2200,240 2400,180 L2400,320 L0,320 Z" fill="#b8d4ff"/>
      </svg>
      <svg class="wave2" viewBox="0 0 2400 320" preserveAspectRatio="none">
        <path d="M0,210 C300,150 500,260 800,210 C1100,160 1300,260 1600,210 C1900,160 2100,260 2400,210 L2400,320 L0,320 Z" fill="#2f6dff"/>
      </svg>
      <svg class="wave4" viewBox="0 0 2400 320" preserveAspectRatio="none">
        <path d="M0,240 C400,180 600,290 1000,240 C1400,190 1600,290 2000,240 C2200,210 2300,260 2400,240 L2400,320 L0,320 Z" fill="#1a3fe8"/>
      </svg>
      <svg class="wave1" viewBox="0 0 2400 320" preserveAspectRatio="none">
        <path d="M0,265 C500,225 800,300 1200,265 C1600,230 1900,300 2400,265 L2400,320 L0,320 Z" fill="#2fe6b0"/>
      </svg>
    </div>
  </div>
</template>

<style scoped>
.login-root {
  position: fixed; inset: 0; overflow-y: auto;
  display: flex; align-items: center; justify-content: center;
  padding: 24px 16px 200px;
  background:
    radial-gradient(ellipse at 80% -10%, #c8e4ff 0%, transparent 55%),
    radial-gradient(ellipse at -10% 30%, #d6fff0 0%, transparent 50%),
    linear-gradient(180deg, #f3fbff 0%, #e8f4ff 55%, #d8ecff 100%);
}
.bubbles-layer { position:fixed;inset:0;overflow:hidden;pointer-events:none;z-index:1; }
@keyframes rise {
  0%   { transform:translateY(0) translateX(0);opacity:0; }
  10%  { opacity:0.75; }
  100% { transform:translateY(-110vh) translateX(-12px);opacity:0; }
}
.login-card { position:relative;z-index:2;width:100%;max-width:420px; }
.logo-circle {
  width:88px;height:88px;border-radius:50%;background:#fff;
  box-shadow:0 8px 32px rgba(0,0,0,0.12);display:flex;
  align-items:center;justify-content:center;overflow:hidden;
}
.logo-circle img { width:80px;height:80px;object-fit:contain; }
.waves { position:fixed;left:0;right:0;bottom:0;height:clamp(110px,18vh,180px);pointer-events:none;z-index:3;overflow:hidden; }
.waves svg { position:absolute;bottom:0;left:0;width:200%;height:100%;display:block; }
.wave1 { animation:drift 22s linear infinite;opacity:0.9; }
.wave2 { animation:drift 32s linear infinite reverse;opacity:0.55; }
.wave3 { animation:drift 42s linear infinite;opacity:0.35; }
.wave4 { animation:drift 26s linear infinite reverse;opacity:0.65; }
@keyframes drift { from{transform:translateX(0)} to{transform:translateX(-50%)} }
</style>
