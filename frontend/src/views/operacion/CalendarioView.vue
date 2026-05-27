<script setup lang="ts">
import { ref } from 'vue'
import type { DiaSemana } from '@/types'

const diasSemana: DiaSemana[] = ['L', 'M', 'X', 'J', 'V', 'S']
const diaActivo = ref<DiaSemana>('L')

const labelDia: Record<DiaSemana, string> = {
  L: 'Lun', M: 'Mar', X: 'Mié', J: 'Jue', V: 'Vie', S: 'Sáb',
}
</script>

<template>
  <div class="space-y-4">
    <!-- Selector de día -->
    <div class="flex items-center gap-2 bg-white p-1 rounded-xl shadow-sm border border-gray-100 w-fit">
      <button
        v-for="dia in diasSemana"
        :key="dia"
        @click="diaActivo = dia"
        class="px-4 py-1.5 rounded-lg text-sm font-medium transition-colors"
        :class="diaActivo === dia ? 'bg-[#0a1e6e] text-white' : 'text-gray-500 hover:text-[#0a1e6e]'"
      >
        {{ labelDia[dia] }}
      </button>
    </div>

    <!-- Pizarrón -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
      <div class="min-w-[700px]">
        <p class="text-sm text-gray-400 text-center py-20">
          Pizarrón del calendario — en construcción
        </p>
      </div>
    </div>
  </div>
</template>
