<script setup lang="ts">
import { _confirmState, _snackState } from '@/composables/useSwal'

function responder(valor: boolean) {
  _confirmState.open = false
  _confirmState.resolve?.(valor)
  _confirmState.resolve = null
}
</script>

<template>
  <!-- ── Diálogo de confirmación ─────────────────────────────────────────── -->
  <v-dialog v-model="_confirmState.open" max-width="420" persistent>
    <v-card rounded="xl" elevation="0" border>
      <v-card-text class="pa-6 pb-2">
        <div class="d-flex align-start ga-4">
          <v-icon icon="mdi-alert-circle-outline" color="warning" size="32" class="mt-1 flex-shrink-0" />
          <div>
            <div style="font-size:17px;font-weight:700;color:#0a1e6e;margin-bottom:6px">
              {{ _confirmState.title }}
            </div>
            <div v-if="_confirmState.text" style="font-size:14px;color:#6b7280;line-height:1.5">
              {{ _confirmState.text }}
            </div>
          </div>
        </div>
      </v-card-text>

      <v-card-actions class="pa-4 pt-2">
        <v-spacer />
        <v-btn
          variant="text"
          rounded="lg"
          color="grey-darken-1"
          @click="responder(false)"
        >Cancelar</v-btn>
        <v-btn
          variant="flat"
          rounded="lg"
          color="error"
          @click="responder(true)"
        >Sí, continuar</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- ── Snackbar de notificación ───────────────────────────────────────── -->
  <v-snackbar
    v-model="_snackState.open"
    :timeout="_snackState.timeout"
    :color="_snackState.color"
    location="bottom right"
    rounded="lg"
    min-width="320"
  >
    <div style="font-weight:700;font-size:14px">{{ _snackState.title }}</div>
    <div v-if="_snackState.text" style="font-size:13px;opacity:.85;margin-top:2px">
      {{ _snackState.text }}
    </div>

    <template #actions>
      <v-btn
        icon="mdi-close"
        variant="text"
        size="small"
        @click="_snackState.open = false"
      />
    </template>
  </v-snackbar>
</template>
