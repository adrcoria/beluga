<script setup lang="ts">
import AppDialogs from '@/components/AppDialogs.vue'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
</script>

<template>
  <v-app>
    <router-view />
    <AppDialogs />

    <!-- Overlay global: sincronización de datos (generación de cobros, etc.) -->
    <v-overlay
      :model-value="ui.sincronizando"
      persistent
      class="d-flex align-center justify-center"
      scrim="#0a1e6e"
      opacity="0.65"
      z-index="9999"
    >
      <div class="text-center px-6">
        <v-progress-circular indeterminate color="#2fe6b0" size="56" width="5" />
        <div style="color:#fff;font-weight:700;margin-top:18px;font-size:16px;font-family:'Fraunces',serif">
          Sincronizando datos…
        </div>
        <div style="color:rgba(255,255,255,.75);font-size:13px;margin-top:4px">
          Espera un momento, estamos poniendo todo al día.
        </div>
      </div>
    </v-overlay>
  </v-app>
</template>
