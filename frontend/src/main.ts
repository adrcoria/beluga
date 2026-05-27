import { createApp } from 'vue'
import { createPinia } from 'pinia'
import router from '@/router'
import App from './App.vue'

// Vuetify
import 'vuetify/styles'
import '@mdi/font/css/materialdesignicons.css'
import { createVuetify } from 'vuetify'
import * as components from 'vuetify/components'
import * as directives from 'vuetify/directives'

// Estilos globales: tokens de color + tipografía
import '@/assets/main.css'
import '@/assets/fonts.css'

const vuetify = createVuetify({
  components,
  directives,
  theme: {
    defaultTheme: 'beluga',
    themes: {
      beluga: {
        dark: false,
        colors: {
          // Semánticos Vuetify
          primary:    '#1a3fe8',   // blue-pure
          secondary:  '#2fe6b0',   // teal
          surface:    '#ffffff',   // paper
          background: '#f3fbff',   // foam
          error:      '#dc2626',
          warning:    '#d97706',
          success:    '#15803d',
          info:       '#2f6dff',   // blue-mid
          // Brand completo
          'blue-deep':  '#0a1e6e',
          'blue-pure':  '#1a3fe8',
          'blue-mid':   '#2f6dff',
          'blue-soft':  '#b8d4ff',
          'teal':       '#2fe6b0',
          'teal-soft':  '#b8f5e0',
          'foam':       '#f3fbff',
          'sky':        '#e8f4ff',
          'ink':        '#061235',
          'paper':      '#ffffff',
        },
      },
    },
  },
  defaults: {
    VBtn: { rounded: 'lg', elevation: 0 },
    VCard: { rounded: 'xl', elevation: 0 },
    VTextField: { variant: 'outlined', density: 'comfortable', rounded: 'lg', hideDetails: 'auto' },
    VSelect: { variant: 'outlined', density: 'comfortable', rounded: 'lg', hideDetails: 'auto' },
  },
})

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.use(vuetify)
app.mount('#app')
