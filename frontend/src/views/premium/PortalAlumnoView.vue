<script setup lang="ts">
import PremiumTeaser from '@/components/premium/PremiumTeaser.vue'

const features = [
  { icon: 'mdi-account-key', titulo: 'Acceso personal', texto: 'Cada alumno o tutor entra con su propia cuenta desde el celular, sin pasar por recepción.' },
  { icon: 'mdi-calendar-check', titulo: 'Su horario y clases', texto: 'Consulta en qué días y horas tiene clase, con qué instructor, y sus próximas sesiones.' },
  { icon: 'mdi-receipt-text-check', titulo: 'Pagos y comprobantes', texto: 'Ve su historial de pagos, lo que debe y descarga sus recibos cuando los necesite.' },
  { icon: 'mdi-qrcode', titulo: 'Su QR siempre a la mano', texto: 'El código de acceso vive en su portal; lo muestra al llegar sin depender de un mensaje viejo.' },
  { icon: 'mdi-calendar-sync', titulo: 'Reagendar en línea', texto: 'Solicita reposiciones o cambios de horario según la disponibilidad, sin llamadas ni WhatsApp.' },
  { icon: 'mdi-bell-ring', titulo: 'Avisos personalizados', texto: 'Recibe recordatorios de pago y notificaciones de su clase directamente en su portal.' },
]
</script>

<template>
  <PremiumTeaser
    accent="#2fb39a"
    titulo="Portal del Alumno"
    tagline="Tus alumnos se autogestionan desde su celular y descargan trabajo de recepción."
    descripcion="Da a cada familia su propio espacio: consultan horarios, pagos y su código de acceso, y pueden solicitar reposiciones en línea. Menos llamadas, menos filas en recepción y una experiencia más profesional para tus clientes."
    :features="features"
  />
</template>
