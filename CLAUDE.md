# Acuática Beluga — Sistema de Administración

Software a la medida para administrar la operación completa de una escuela de natación ubicada en Morelia, Michoacán. Reemplaza un flujo de trabajo basado en Excel y pizarrón físico. Inspirado en SwimSoft pero aterrizado sobre la operación real del cliente.

---

## Identidad del proyecto

- **Nombre del negocio:** Acuática Beluga
- **Eslogan:** Nadar es vida
- **Ubicación:** Morelia, Michoacán
- **Modelo de entrega:** Software a la medida. Una instancia por cliente, dominio y servidor propios. No es SaaS.
- **Documentación de referencia:** carpeta `docs/`
  - `cotizacion.pdf` — acuerdo y alcance comercial
  - `control_horarios.xlsx` — pizarrón de horarios real del cliente
  - `bd_alumnos.xlsx` — base de alumnos actual
  - `beluga_control.docx` — guía del cliente para interpretar los archivos Excel
  - `acuatica_beluga.jpg` — logotipo oficial
  - `index_template.html` — prototipo validado por el cliente (base del frontend)

---

## Estructura del monorepo

```
beluga/
├── CLAUDE.md
├── .gitignore
├── docs/                       # Documentación del cliente (solo lectura)
├── frontend/                   # Vue 3 + TypeScript + Vite
│   ├── src/
│   │   ├── assets/main.css     # Design tokens Tailwind v4 + Google Fonts
│   │   ├── types/index.ts      # Tipos TypeScript del dominio
│   │   ├── router/index.ts     # Rutas de los 13 módulos
│   │   ├── stores/             # Pinia: auth, sucursal
│   │   ├── components/
│   │   │   └── layout/         # AppLayout, AppSidebar, AppTopBar
│   │   └── views/              # Una vista por módulo
│   │       ├── DashboardView.vue
│   │       ├── LoginView.vue
│   │       ├── operacion/      # Calendario, Asistencia, Reposiciones
│   │       ├── personas/       # Alumnos, AlumnoDetalle, Instructores
│   │       ├── finanzas/       # Cobranza, Inscripciones, CambioPlan
│   │       ├── analisis/       # Reportes
│   │       ├── config/         # Usuarios
│   │       └── premium/        # Preinscripcion, PortalAlumno, Comunicacion
│   ├── vite.config.ts          # Proxy /api → localhost:8080
│   └── package.json
└── backend/                    # PHP 8.1 + Slim 4 + PHP-DI
    ├── public/
    │   ├── index.php           # Entry point
    │   └── .htaccess           # Rewrite para Apache
    ├── src/
    │   ├── Application/
    │   │   ├── Actions/        # Un Action por endpoint (patrón Command)
    │   │   └── Middleware/     # AuthMiddleware (JWT)
    │   └── Domain/             # Interfaces de repositorios y servicios
    │       ├── Alumno/
    │       ├── Instructor/
    │       ├── Calendario/
    │       ├── Pago/
    │       └── Inhabil/
    ├── config/
    │   ├── container.php       # Bindings PHP-DI (PDO, Logger)
    │   ├── middleware.php      # CORS, body parsing, error handling
    │   └── routes.php          # Todas las rutas agrupadas
    ├── .env.example
    └── composer.json
```

## Stack tecnológico

### Frontend
- **Vue 3** + Composition API + `<script setup>`
- **TypeScript** estricto
- **Vite** como bundler (proxy `/api` → `localhost:8080`)
- **Tailwind CSS v4** con `@tailwindcss/vite` — tokens en `@theme { }` dentro del CSS
- **Vue Router 4** — historia HTML5, rutas lazy-loaded
- **Pinia** — stores: `auth`, `sucursal`
- **VueUse** — composables de utilería

### Backend
- **PHP 8.1+** con `declare(strict_types=1)` en todos los archivos
- **Slim Framework 4** — micro-framework REST
- **PHP-DI 7** — inyección de dependencias
- **Firebase JWT** — autenticación stateless
- **PDO + MySQL/MariaDB** — acceso a datos
- **Monolog** — logging
- **Dotenv** — variables de entorno

### Servidor de producción
Apache con `.htaccess` en `backend/public/`. El frontend se compila con `npm run build` y se sirve desde la misma instancia Apache o como archivos estáticos.

### Comandos de desarrollo
```bash
# Frontend
cd frontend && npm run dev          # localhost:5173

# Backend (desarrollo rápido sin Apache)
cd backend && php -S 0.0.0.0:8080 -t public/
# o: composer start
```

### Variables de entorno del backend
Copiar `backend/.env.example` → `backend/.env` y ajustar antes de `composer install`.

---

## Identidad visual

### Tokens de color (del `index_template.html`)

```css
--blue-deep:  #0a1e6e   /* azul oscuro, texto principal */
--blue-pure:  #1a3fe8   /* azul puro, primario/acento */
--blue-mid:   #2f6dff   /* azul medio */
--blue-soft:  #b8d4ff   /* azul claro, fondos */
--teal:       #2fe6b0   /* teal brillante, acento secundario */
--teal-soft:  #b8f5e0   /* teal claro */
--foam:       #f3fbff   /* casi blanco con tinte azul */
--sky:        #e8f4ff   /* fondo cielo */
--ink:        #061235   /* negro azulado para texto */
--paper:      #ffffff
```

### Tipografía
- **Display / headings:** Fraunces (serif, variable)
- **Cuerpo / UI:** Plus Jakarta Sans

### Layout general
- Sidebar fijo a la izquierda con navegación agrupada: Inicio · Operación · Personas · Finanzas · Análisis · Premium
- Top bar con título de la vista activa y selector de sucursal
- Modales centrados para acciones (asignar, cobrar, declarar inhábil)
- Toast notifications en esquina inferior derecha

---

## Glosario operativo

| Término | Significado |
|---------|-------------|
| PART | Modalidad particular: 1 alumno con 1 instructor |
| GRUPAL | Modalidad grupal: varios alumnos con 1 instructor |
| MATRO | Matrona­tación: mamá/papá con bebé en el agua |
| Slot | Posición en el calendario: día + franja horaria + instructor + sucursal + modalidad |
| Asignación | Vínculo de un alumno con un slot en una fecha específica |
| Plan | Número de clases por semana contratadas (1, 2, 3 ó 5) |
| Ciclo de pago | Mes calendario contado desde la fecha de inicio del alumno |
| Toca pago | Alumno con vencimiento de mensualidad ≤ 7 días |
| Reposición | Clase otorgada para compensar un día inhábil declarado por la escuela |
| Folio | Identificador único del alumno. Estilo `041`, `042`. Se conserva si el alumno regresa |
| Día inhábil | Día sin clases que dispara reposiciones automáticas |
| Inscripción | Pago único por vida del alumno. Default $300 MXN |

---

## Reglas de negocio críticas

### Modalidades
- Existen exactamente tres: `PART`, `GRUPAL`, `MATRO`
- Los slots están especializados por modalidad. Un slot PART solo acepta alumnos PART
- En GRUPAL el cupo lo define el slot (típicamente 4–6)
- En PART y MATRO el cupo típico es 1

### Plan y ciclo de pago individual
- El plan es N clases por semana (1, 2, 3 ó 5)
- La mensualidad depende de `modalidad + número de clases`
- `diaPago = fechaInicio.getDate()` — cada alumno tiene su propio día de pago
- No existe corte de cobro global. El sistema calcula `próximo vencimiento` y `días de mora` por alumno
- `vencimiento = ultimoPago + 1 mes` (se recalcula al cobrar)
- "Toca pago" cuando `vencimiento - hoy <= 7` días y `vencimiento >= hoy`
- "Vencido" cuando `hoy > vencimiento`

### Inscripción única
- Pago único por vida. Si el alumno se da de baja y regresa, ya no paga inscripción
- Se controla con el flag `inscripcionPagada: boolean` en el alumno

### Maestro variable por sesión
- El instructor se asigna por sesión, **no por alumno**
- Un alumno con plan 3x/sem puede tener hasta 3 instructores distintos en la semana
- Ejemplo real: gemelos Rodrigo y Patricio (PART 3x/sem) → Lunes 4PM/Brisa, Miércoles 7PM/Edgardo, Viernes 5PM/Karla

### Reposiciones automáticas (solo por día inhábil)
- Las reposiciones son **automáticas y colectivas**, nunca por ausencia individual
- Al declarar día inhábil: el sistema identifica todos los alumnos con asignación ese día y busca slot compatible en los **siguientes 14 días**
- Una ausencia individual no genera reposición — el alumno pierde esa clase
- Las reposiciones se marcan visualmente distinto (color naranja + borde `#EA580C`)

### Descuentos con motivo
- Cada descuento guarda `porcentaje + motivo` (texto libre)
- Motivos típicos: Hermanos (15%), Pronto pago (10%), Empleados (20%)
- El descuento aplica sobre la mensualidad, **no** sobre la inscripción

### Alta de alumno vs asignación de horario (flujos separados)
- **Alta (módulo Alumnos):** registra solo datos personales + plan contratado (modalidad, clases/sem, fecha inicio, descuento, status). NO incluye horario ni slots.
- **Asignación (módulo Calendario):** el coordinador busca slots libres del instructor y asigna al alumno. El alumno no "declara disponibilidad" en el alta; la disponibilidad la gestiona el coordinador en el pizarrón.
- Las clases/semana del plan (1–5) determinan cuántos slots se deben asignar, pero el CUÁNDO depende de lo que haya libre en los instructores.
- Un alumno recién dado de alta puede quedar sin slots hasta que el coordinador lo asigne en el Calendario.

### Folio
- Único, secuencial, permanente. Las bajas y reincorporaciones conservan el folio original

---

## Modelo de datos

### Tipos base

```typescript
type Modalidad        = 'PART' | 'GRUPAL' | 'MATRO';
type DiaSemana        = 'L' | 'M' | 'X' | 'J' | 'V' | 'S';
type EstadoAsignacion = 'regular' | 'reposicion' | 'no_asiste' | 'toca_pago' | 'prueba' | 'mensaje';
```

### Entidades principales

```typescript
interface Alumno {
  folio: string;                    // único, estilo '041'
  nombre: string;
  fechaNacimiento?: Date;
  foto?: string;
  contacto: { tutor?: string; telefono: string; email?: string };
  modalidad: Modalidad;
  plan: { clasesSemana: number; mensualidad: number };
  fechaInicio: Date;
  diaPago: number;                  // derivado: fechaInicio.getDate()
  ultimoPago?: Date;
  vencimiento: Date;
  inscripcionPagada: boolean;
  descuento?: { pct: number; motivo: string };
  disponibilidad: { dias: DiaSemana[]; horaInicio: string; horaFin: string };
  status: 'activo' | 'baja' | 'prueba';
  sucursalId: string;
  creadoEn: Date;
}

interface Instructor {
  id: string;
  nombre: string;
  iniciales: string;
  telefono: string;
  email?: string;
  niveles: string[];                // bebes | infantil 1 | infantil 2 | jovenes | adultos | competencia | matro
  sucursales: string[];
  activo: boolean;
}

interface Slot {
  id: string;
  sucursalId: string;
  dia: DiaSemana;
  horaInicio: string;               // '16:00'
  horaFin: string;
  instructorId: string;
  modalidad: Modalidad;
  cupoMax: number;
  activo: boolean;
}

interface Asignacion {
  id: string;
  alumnoFolio: string;
  slotId: string;
  fecha: Date;                      // instancia específica, no recurrente
  estado: EstadoAsignacion;
  nota?: string;
  generadaPorInhabilId?: string;
  registradaEn: Date;
}

interface Pago {
  id: string;
  alumnoFolio: string;
  concepto: 'mensualidad' | 'inscripcion' | 'reposicion_extra' | 'otro';
  monto: number;
  metodo: 'efectivo' | 'transferencia' | 'debito' | 'credito' | 'oxxo';
  fecha: Date;
  ticket: string;
  cicloDesde?: Date;
  cicloHasta?: Date;
  usuarioId: string;
  sucursalId: string;
}

interface Inhabil {
  id: string;
  fecha: Date;
  motivo: string;
  sucursalId?: string;
  estado: 'pendiente' | 'aplicado';
  alumnosAfectados: number;
  reposicionesGeneradas: number;
  declaradoPor: string;
  declaradoEn: Date;
}

interface Usuario {
  id: string;
  nombre: string;
  email: string;
  rol: 'super_admin' | 'gerencia' | 'caja' | 'coordinacion' | 'secretaria';
  sucursales: string[];             // ['*'] para acceso a todas
  activo: boolean;
}

interface PlanCatalogo {
  id: string;
  modalidad: Modalidad;
  clasesSemana: number;
  mensualidad: number;
  label: string;
  activo: boolean;
}
```

---

## Catálogo de planes (configurable, no hardcodear montos)

| Modalidad | Clases/sem | Mensualidad MXN |
|-----------|-----------|----------------|
| PART      | 1         | $650           |
| PART      | 2         | $860           |
| PART      | 3         | $1,150         |
| PART      | 5         | $2,000         |
| GRUPAL    | 1         | $510           |
| GRUPAL    | 2         | $731           |
| MATRO     | 1         | $750           |
| MATRO     | 2         | $1,100         |

Los montos viven en la entidad `PlanCatalogo` y son configurables desde la UI de administración.

---

## Sistema de colores del calendario (estados)

El pizarrón replica la leyenda de Excel del cliente. **Respetar exactamente estos colores.**

| Estado / Modalidad | Fondo      | Texto   |
|-------------------|------------|---------|
| PART              | `#DBEAFE`  | `#1E3A8A` |
| GRUPAL            | `#FCE7F3`  | `#9D174D` |
| MATRO             | `#FED7AA`  | `#9A3412` |
| TOCA PAGO         | `#E5E7EB`  | `#1F2937` |
| NO ASISTE         | `#BBF7D0`  | `#14532D` |
| REPOSICIÓN        | `#FED7AA` + borde `#EA580C` | `#9A3412` |
| CLASE PRUEBA      | `#FBCFE8`  | `#831843` |
| MANDAR MENSAJE    | `#DDD6FE`  | `#4C1D95` |

- Triángulo rojo en esquina superior derecha cuando la celda tiene nota (igual que en Excel)

---

## Módulos del sistema

### Paquete base (10 módulos)

| # | Módulo | Descripción corta |
|---|--------|-------------------|
| 01 | Usuarios del Sistema | Roles, permisos, bitácora de acciones |
| 02 | Instructores | Catálogo, carga de alumnos, historial |
| 03 | Alumnos | Base central, folio, foto, disponibilidad, filtros |
| 04 | **Calendario Inteligente** | Pizarrón operativo (módulo estrella) |
| 05 | Inscripciones | Cobro único, comprobante, historial |
| 06 | Cambio de Plan | Migración entre modalidades y planes |
| 07 | Cobranza | Mensualidades, descuentos, tickets, cartera vencida |
| 08 | Reposiciones de Clases | Declaración de inhábiles, generación automática |
| 09 | Control de Asistencia | QR o búsqueda manual, validaciones automáticas |
| 10 | Reportes | Financieros, operativos, exportación Excel/CSV |

### Módulos premium (opcionales)

| # | Módulo | Descripción corta |
|---|--------|-------------------|
| 11 | Preinscripción QR + Multi-sucursal | QR público, buzón de prospectos, sucursales independientes |
| 12 | Portal del Alumno | Login personal, reagendado, historial de pagos, QR propio |
| 13 | Plataforma de Comunicación | Avisos masivos/individuales, WhatsApp Business API, plantillas automáticas |

---

## Módulo 04 — Calendario Inteligente (detalle)

Es el módulo central de la operación. Debe replicar el Excel del cliente visualmente:
- **Estructura:** filas = franjas horarias, columnas = instructores
- **Vista por día:** selector L–S en el encabezado
- **Click en celda vacía:** modal para asignar alumno compatible
- **Click en celda ocupada:** modal de detalle (cambio de estado + nota)
- **Cambio rápido de sucursal** en el top bar

### Asignación inteligente (algoritmo)
```
Dado un alumno:
1. Leer: modalidad, plan.clasesSemana, disponibilidad.dias, horaInicio, horaFin
2. Filtrar slots donde:
   - slot.modalidad == alumno.modalidad
   - slot.dia ∈ alumno.disponibilidad.dias
   - slot.horaInicio ∈ [alumno.horaInicio, alumno.horaFin]
   - cupoDisponible(slot, fecha) > 0
3. Mostrar opciones agrupadas por día
4. Usuario selecciona N slots (= plan.clasesSemana)
5. Crear N asignaciones con estado 'regular'
```

La asignación NO es permanente. Es por semana y puede variar.

---

## Flujos clave

### Declarar día inhábil → reposiciones automáticas
1. Usuario abre "Declarar día inhábil", ingresa fecha + motivo
2. Sistema muestra vista previa: X alumnos afectados, Y clases
3. Al confirmar:
   - Crea registro `Inhabil`
   - Para cada `Asignacion` en esa fecha: busca slot compatible en los **próximos 14 días**
   - Si encuentra: crea `Asignacion` nueva con `estado='reposicion'` y `generadaPorInhabilId`
   - Si no encuentra: queda "pendiente de asignar" para resolución manual
4. Muestra resultado: "14 reposiciones generadas, 2 pendientes"

### Registrar pago de mensualidad
1. Seleccionar alumno
2. Monto prellenado: `mensualidad - descuento`
3. Al confirmar:
   - Crea `Pago`
   - `alumno.ultimoPago = fechaPago`
   - `alumno.vencimiento = ultimoPago + 1 mes`
   - Genera ticket con folio

### Cambio de plan
1. Seleccionar alumno + nuevo plan
2. Sistema muestra previsualización del impacto (mensualidad, slots a liberar)
3. Si cambia modalidad: warning "Se liberan X slots, hay que reasignar"
4. Al confirmar: marca asignaciones futuras previas como "a reasignar", actualiza alumno

---

## Roles y permisos

| Rol | Acceso |
|-----|--------|
| `super_admin` | Todo |
| `gerencia` | Reportes, configuración, todas las sucursales |
| `caja` | Cobranza, inscripciones, alumnos (lectura) |
| `coordinacion` | Calendario, asignaciones, reposiciones, alumnos |
| `secretaria` | Alumnos, inscripciones, calendario (limitado) |

---

## Patrones obligatorios de UX

- Búsqueda en cualquier listado de alumnos: por nombre, folio o teléfono
- Cualquier acción destructiva requiere confirmación explícita
- Cualquier acción que afecte a múltiples alumnos muestra vista previa del impacto antes de ejecutar
- El pizarrón del Calendario debe parecerse al Excel del cliente: columnas = maestros, misma codificación de colores, triángulo rojo para celdas con nota
- Toasts en esquina inferior derecha para confirmaciones no críticas

### Formularios — convención obligatoria

- **Siempre usar Vuetify** para formularios: `v-text-field`, `v-select`, `v-checkbox`, etc.
- **Campos:** `variant="outlined"` + `density="compact"` + `rounded="lg"` en todos los inputs. Esto produce floating label pequeño automáticamente (Vuetify behavior nativo).
- **Validación con `rules`:** usar el prop `rules` de Vuetify en cada campo. No validar a mano con `if`. Ejemplo:
  ```vue
  <v-text-field
    v-model="form.nombre"
    label="Nombre *"
    variant="outlined"
    density="compact"
    rounded="lg"
    :rules="[v => !!v || 'Campo requerido', v => v.length >= 2 || 'Mínimo 2 caracteres']"
  />
  ```
- **Ref al formulario:** usar `ref` en `v-form` y llamar `formRef.value?.validate()` antes de submit para activar todos los errores en pantalla.
  ```vue
  const formRef = ref<InstanceType<typeof VForm>>()
  async function guardar() {
    const { valid } = await formRef.value!.validate()
    if (!valid) return
    // ...
  }
  ```
- **Nunca** usar `<input>` HTML nativo ni validaciones CSS manuales en formularios.

---

## Fuera de alcance (no implementar)

- Procesador de pagos en línea (Stripe, OpenPay, Conekta)
- Facturación electrónica / integración SAT
- Asistencia biométrica
- Integración con tornos físicos
- App móvil nativa para staff
- Multitenancy real estilo SaaS
- Procesamiento de imagen / reconocimiento facial

---

## Datos de referencia del cliente

### Instructores actuales
Brisa, Karla, Ram, Chío, Maru, Edgardo, Jacob

### Métodos de pago observados
Efectivo (mayoritario), transferencia, débito, crédito, OXXO (poco frecuente)

### Inscripción
- Monto: $300 MXN (configurable)
- Una sola vez por vida del alumno

### Caso real de referencia (gemelos)
- **Alumno:** Rodrigo Valladolid y Patricio Valladolid
- **Plan:** PART · 3 clases/semana
- **Descuento:** 15% por hermanos
- **Asignación:**
  - Lunes 4:00 PM → Instructor Brisa
  - Miércoles 7:00 PM → Instructor Edgardo
  - Viernes 5:00 PM → Instructor Karla
- **Inscripción:** pagada

### El "pizarrón" actual del cliente
Dos archivos Excel:
1. **Horarios:** hoja por día (L–S), columnas = maestros, celdas = "Nombre + folio", colores según estado
2. **Alumnos:** columnas Folio, Nombre, Foto, Día de pago, Modalidad, Días, Horario, Mensualidad, Inscripción, Descuento, Contacto

---

## Convenciones de desarrollo

- Los montos del catálogo de planes **nunca se hardcodean**; viven en BD y son editables desde la UI
- El `folio` es la llave de negocio del alumno y nunca cambia, aunque el alumno tenga varias bajas
- `diaPago` se deriva siempre de `fechaInicio.getDate()`, no se guarda independientemente a menos que el modelo de BD lo requiera por performance
- Las asignaciones son instancias concretas por fecha, no registros recurrentes; la recurrencia se gestiona en la capa de aplicación
- El campo `nota` en `Asignacion` equivale al comentario que el equipo escribe en el Excel; se muestra como triángulo rojo en la celda
