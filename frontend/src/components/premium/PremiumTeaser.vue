<script setup lang="ts">
const props = defineProps<{
  titulo: string
  tagline: string
  descripcion: string
  features: { icon: string; titulo: string; texto: string }[]
  accent?: string
}>()

const WHATSAPP = '524431371292' // 443 137 1292

function solicitar() {
  const msg = encodeURIComponent(
    `Hola, me interesa el módulo premium "${props.titulo}" de Acuática Beluga. ¿Me podrías dar más información?`,
  )
  window.open(`https://wa.me/${WHATSAPP}?text=${msg}`, '_blank')
}
</script>

<template>
  <div class="d-flex flex-column ga-4">
    <!-- Hero -->
    <v-card rounded="xl" border>
      <div class="hero" :style="{ '--accent': accent || '#1a3fe8' }">
        <v-chip color="warning" variant="flat" size="small" class="font-weight-bold mb-3" style="letter-spacing:.06em">
          <v-icon start icon="mdi-star-four-points" size="14" /> MÓDULO PREMIUM
        </v-chip>
        <div class="hero-title">{{ titulo }}</div>
        <div class="hero-tagline">{{ tagline }}</div>
      </div>
      <v-card-text class="pa-6">
        <p style="font-size:15px;line-height:1.6;color:#374151;max-width:760px">{{ descripcion }}</p>
      </v-card-text>
    </v-card>

    <!-- Features -->
    <div class="text-overline" style="color:#9ca3af;letter-spacing:.12em">Qué incluye</div>
    <v-row dense>
      <v-col v-for="f in features" :key="f.titulo" cols="12" sm="6">
        <v-card rounded="xl" border height="100%">
          <v-card-text class="pa-4 d-flex ga-3">
            <div class="feat-icon" :style="{ background: (accent || '#1a3fe8') + '1a', color: accent || '#1a3fe8' }">
              <v-icon :icon="f.icon" size="22" />
            </div>
            <div>
              <div style="font-weight:700;color:#0a1e6e;font-size:14px;margin-bottom:2px">{{ f.titulo }}</div>
              <div style="font-size:13px;color:#6b7280;line-height:1.5">{{ f.texto }}</div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- CTA -->
    <v-card rounded="xl" class="cta" :style="{ '--accent': accent || '#1a3fe8' }">
      <v-card-text class="pa-6 d-flex flex-wrap align-center justify-space-between ga-4">
        <div>
          <div style="font-size:17px;font-weight:700;color:#fff;font-family:'Fraunces',serif">¿Quieres activar este módulo?</div>
          <div style="font-size:13px;color:rgba(255,255,255,.8);margin-top:2px">
            Es un complemento opcional. Contáctanos para incluirlo en tu sistema.
          </div>
        </div>
        <v-btn color="white" variant="flat" size="large" rounded="lg" class="font-weight-bold" style="color:#0a1e6e" prepend-icon="mdi-whatsapp" @click="solicitar">
          Solicitar información
        </v-btn>
      </v-card-text>
    </v-card>
  </div>
</template>

<style scoped>
.hero {
  background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 12%, #fff), color-mix(in srgb, var(--accent) 28%, #fff));
  padding: 32px 28px;
  border-radius: 16px 16px 0 0;
  border-bottom: 1px solid color-mix(in srgb, var(--accent) 30%, #fff);
}
.hero-title { font-size: 28px; font-weight: 800; color: #0a1e6e; font-family: 'Fraunces', serif; line-height: 1.1; }
.hero-tagline { font-size: 15px; color: #1f2937; margin-top: 6px; max-width: 620px; }
.feat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.cta { background: linear-gradient(135deg, var(--accent), #0a1e6e); }
</style>
