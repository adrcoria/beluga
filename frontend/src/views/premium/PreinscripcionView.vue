<script setup lang="ts">
import PremiumTeaser from '@/components/premium/PremiumTeaser.vue'

const features = [
  { icon: 'mdi-qrcode', titulo: 'QR público de preinscripción', texto: 'Pega un QR en recepción o redes; el prospecto lo escanea, llena sus datos desde su celular y entra solo al sistema.' },
  { icon: 'mdi-inbox-multiple', titulo: 'Buzón de prospectos', texto: 'Todas las preinscripciones llegan a un buzón. El coordinador revisa, aprueba y convierte al prospecto en alumno con un clic.' },
  { icon: 'mdi-map-marker-multiple', titulo: 'Multi-sucursal', texto: 'Cada sucursal con su propio pizarrón, cobranza, alumnos y reportes — administradas todas desde una sola cuenta.' },
  { icon: 'mdi-chart-box-multiple', titulo: 'Reportes consolidados', texto: 'Compara ingresos, ocupación y cartera entre sucursales, o revisa cada una por separado.' },
]
</script>

<template>
  <PremiumTeaser
    accent="#1a3fe8"
    titulo="Preinscripción QR + Multi-sucursal"
    tagline="Capta prospectos sin llenar formularios a mano y opera varias sucursales como si fueran una."
    descripcion="Convierte la captación en un proceso automático: el interesado escanea un QR, registra sus datos y queda en un buzón listo para aprobar. Y si creces a más de una sede, administra cada sucursal de forma independiente —con su operación y sus números— sin perder la visión del conjunto."
    :features="features"
  />
</template>
