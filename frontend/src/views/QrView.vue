<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import QRCode from 'qrcode'
import { useApi } from '@/composables/useApi'

const route = useRoute()
const api   = useApi()
const folio = String(route.params.folio)

const nombre = ref('')
const qrUrl  = ref('')
const error  = ref(false)
const cargando = ref(true)

onMounted(async () => {
  try {
    const r = await api.get<{ folio: string; nombre: string }>(`/api/public/qr/${folio}`)
    nombre.value = r.nombre
  } catch {
    error.value = true
  }
  try {
    qrUrl.value = await QRCode.toDataURL(folio, {
      width: 300, margin: 2, color: { dark: '#0a1e6e', light: '#ffffff' },
    })
  } catch {}
  cargando.value = false
})
</script>

<template>
  <div class="qr-page">
    <div class="qr-card">
      <div class="logo-wave" aria-hidden="true">
        <svg viewBox="0 0 32 16" fill="none" xmlns="http://www.w3.org/2000/svg" width="40" height="20">
          <path d="M0 8 C4 3, 7 13, 11 8 C15 3, 18 13, 22 8 C26 3, 29 13, 32 8" stroke="#2fe6b0" stroke-width="2.8" stroke-linecap="round"/>
        </svg>
      </div>
      <div class="marca">Acuática<span style="color:#2fe6b0">Beluga</span></div>

      <template v-if="cargando">
        <div style="padding:40px 0"><v-progress-circular indeterminate color="primary" /></div>
      </template>

      <template v-else-if="error">
        <p style="color:#dc2626;margin-top:24px">Código no encontrado.</p>
      </template>

      <template v-else>
        <div class="eyebrow">Tu código de acceso</div>
        <div class="nombre">{{ nombre }}</div>
        <div class="folio">Folio #{{ folio }}</div>
        <img v-if="qrUrl" :src="qrUrl" alt="QR" class="qr-img" />
        <p class="instr">Muestra este código al llegar a tu clase 🏊</p>
      </template>
    </div>
  </div>
</template>

<style scoped>
.qr-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: linear-gradient(180deg, #f3fbff 0%, #e8f4ff 60%, #d8ecff 100%);
}
.qr-card {
  background: #fff;
  border-radius: 24px;
  padding: 32px 28px;
  width: 100%;
  max-width: 360px;
  text-align: center;
  box-shadow: 0 10px 40px rgba(10,30,110,.12);
}
.logo-wave { display:inline-block; }
.marca { font-size: 16px; font-weight: 800; color: #0a1e6e; letter-spacing: -.01em; margin-top: 4px; }
.eyebrow { font-size: 11px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #1a3fe8; margin-top: 24px; }
.nombre { font-size: 20px; font-weight: 700; color: #0a1e6e; font-family: 'Fraunces', serif; margin-top: 4px; }
.folio { font-size: 12px; color: #9ca3af; font-family: monospace; margin-bottom: 16px; }
.qr-img { width: 260px; height: 260px; }
.instr { font-size: 13px; color: #6b7280; margin-top: 16px; }
</style>
