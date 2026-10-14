<script setup lang="ts">
import PremiumTeaser from '@/components/premium/PremiumTeaser.vue'

const features = [
  { icon: 'mdi-whatsapp', titulo: 'WhatsApp Business integrado', texto: 'Envía mensajes oficiales por WhatsApp directo desde el sistema, sin copiar y pegar número por número.' },
  { icon: 'mdi-bullhorn', titulo: 'Avisos masivos o segmentados', texto: 'Manda un comunicado a todos, o solo a un grupo: por modalidad, sucursal, alumnos con adeudo, etc.' },
  { icon: 'mdi-file-document-edit', titulo: 'Plantillas automáticas', texto: 'Recordatorio de pago, bienvenida, aviso de día inhábil y reposición — se envían solos con los datos de cada alumno.' },
  { icon: 'mdi-history', titulo: 'Historial de envíos', texto: 'Registro de qué se mandó, a quién y cuándo, para dar seguimiento y evitar mensajes duplicados.' },
]
</script>

<template>
  <PremiumTeaser
    accent="#16a34a"
    titulo="Plataforma de Comunicación"
    tagline="Mensajes a tus alumnos por WhatsApp, masivos o individuales, en segundos."
    descripcion="Centraliza la comunicación con tus familias: recordatorios de pago automáticos, avisos de días inhábiles, promociones o comunicados generales. Todo por WhatsApp, con plantillas que se llenan solas y un historial de lo enviado — para cobrar más rápido y mantener a tus alumnos informados."
    :features="features"
  />
</template>
