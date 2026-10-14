<script setup lang="ts">
/**
 * Aviso de que la pantalla está en modo consulta.
 *
 * Se pone arriba de las vistas cuyos roles pueden entrar pero no modificar, para
 * que el usuario entienda de entrada por qué no ve los botones de captura y no
 * crea que el sistema está fallando.
 */
import { usePermisos } from '@/composables/usePermisos'

const props = defineProps<{
  modulo: string
  /** Qué no se puede hacer aquí, en palabras de la pantalla. */
  detalle?: string
}>()

const { soloLectura } = usePermisos(props.modulo)
</script>

<template>
  <v-alert
    v-if="soloLectura"
    type="info"
    variant="tonal"
    density="compact"
    rounded="lg"
    icon="mdi-eye-outline"
    class="mb-1"
  >
    <div style="font-size:12.5px;line-height:1.45">
      <strong>Modo consulta.</strong>
      Tu perfil puede ver esta información{{ detalle ? ', pero no ' + detalle : ', pero no modificarla' }}.
    </div>
  </v-alert>
</template>
