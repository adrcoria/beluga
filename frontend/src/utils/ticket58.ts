/**
 * Impresión del comprobante por el diálogo del navegador, en papel de 58 mm.
 *
 * Única ruta de impresión del comprobante: el usuario elige la impresora en el
 * diálogo del navegador (la térmica de 58 mm entre ellas). No hay impresión
 * silenciosa desde el servidor. En lugar de rearmar el diseño, se CLONA el
 * comprobante que el usuario ya tiene en pantalla: así lo impreso es idéntico a
 * lo que ve. El clon conserva sus atributos `data-v-*`, y al copiar las hojas de
 * estilo de la app también aplican sus reglas `scoped`.
 *
 * Se imprime desde un iframe propio —no con `window.print()` sobre la vista—
 * para no depender de reglas `@media print` globales que afectarían al resto
 * del sistema.
 */

const CSS_58MM = `
  @page { size: 58mm auto; margin: 0; }

  html, body {
    margin: 0;
    padding: 0;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  /* 46 mm, NO 58 mm. El papel mide 58 mm pero el cabezal solo imprime 384
     puntos, que a 203 dpi son 48 mm: lo que se dibuje más allá se pierde por el
     lado derecho. Se dejan 2 mm de holgura porque el punto exacto donde corta
     depende de cómo esté calibrado el driver. El ancho fijo además mantiene el
     comprobante angosto aunque el driver ignore @page y caiga a Carta; sin él,
     el texto centrado se abre a todo el ancho de la hoja. */
  body { width: 46mm; }

  #comprobante-impresion {
    width: 100% !important;
    margin: 0 !important;
    padding: 2mm 1.5mm 4mm !important;   /* el área imprimible es angosta, no sobra margen */
    background: #fff !important;   /* el fondo gris del modal ensucia el papel */
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
  }

  /* TODO el texto en negro puro. La térmica no tiene grises: los simula con una
     trama de puntos que, en texto de 10 px, sale casi invisible en el papel. En
     pantalla el gris da jerarquía; impreso solo resta legibilidad, y la
     jerarquía la siguen cargando el tamaño y el peso de la fuente.
     Va con !important porque los colores del comprobante son estilos inline.

     La fuente también se cambia: la térmica imprime a 203 dpi y 1 bit por punto,
     así que los remates y trazos finos de Fraunces (serif de la marca) y de
     Plus Jakarta se deshacen al binarizar. Una grotesca de grosor parejo
     sobrevive mucho mejor. */
  #comprobante-impresion,
  #comprobante-impresion * {
    color: #000 !important;
    font-family: Arial, Helvetica, sans-serif !important;
    text-rendering: geometricPrecision;
  }

  /* Trazo más grueso en todo el ticket: a 1 bit, el peso normal se adelgaza y
     sale tenue. Va sin !important y en el contenedor a propósito, para que se
     herede solo donde no hay peso propio; los títulos e importes traen el suyo
     inline y lo conservan. */
  #comprobante-impresion { font-weight: 600; }

  /* La leyenda fiscal es la más chica y la que peor aguanta: peso extra. */
  #comprobante-impresion .ticket-legal { font-weight: 700 !important; }

  /* El monto es lo más ancho del ticket y va pegado al margen derecho: a 18 px
     se sale del cabezal. En pantalla se queda grande; solo se acota al imprimir. */
  #comprobante-impresion .ticket-total { font-size: 15px !important; }

  /* Por lo mismo, los separadores punteados en negro: en gris claro desaparecen */
  #comprobante-impresion .ticket-divider { border-top: 1px dashed #000; }
`

/**
 * Sube al mínimo legible el texto más chico del comprobante. A 203 dpi y 1 bit
 * por punto, por debajo de ~11 px los rasgos de la letra caen entre puntos y la
 * leyenda sale borrosa.
 *
 * No se puede hacer desde el CSS porque los tamaños vienen en estilos inline.
 * Se leen del elemento ORIGINAL —el clon todavía no está en un documento y no
 * tiene estilos calculados— y se aplican al clon, que es idéntico en estructura.
 */
function tamanoMinimo(original: HTMLElement, clon: HTMLElement, minimoPx = 11.5): void {
  const origen = [original, ...Array.from(original.querySelectorAll<HTMLElement>('*'))]
  const copia  = [clon,     ...Array.from(clon.querySelectorAll<HTMLElement>('*'))]

  for (let i = 0; i < origen.length && i < copia.length; i++) {
    const px = parseFloat(getComputedStyle(origen[i]).fontSize)
    if (px && px < minimoPx) copia[i].style.fontSize = `${minimoPx}px`
  }
}

/** Espera a que las hojas de estilo copiadas estén aplicadas (o fallen). */
function esperarEstilos(doc: Document, msMax = 1500): Promise<void> {
  const pendientes = Array.from(doc.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"]'))
    .filter(l => !l.sheet)

  if (!pendientes.length) return Promise.resolve()

  return Promise.race([
    Promise.all(pendientes.map(l => new Promise<void>(listo => {
      l.onload = l.onerror = () => listo()
    }))).then(() => undefined),
    new Promise<void>(listo => setTimeout(listo, msMax)),
  ])
}

/**
 * Imprime una copia del elemento dado en formato de 58 mm.
 *
 * @param elemento Nodo del comprobante en pantalla (p. ej. `#ticket-print`)
 * @param titulo   Nombre del documento en la cola de impresión
 */
export async function imprimirComprobante(elemento: HTMLElement, titulo = 'Comprobante'): Promise<void> {
  document.getElementById('beluga-ticket-frame')?.remove()

  const frame = document.createElement('iframe')
  frame.id = 'beluga-ticket-frame'
  frame.setAttribute('aria-hidden', 'true')
  // Fuera de pantalla pero con tamaño real: los iframes ocultos con
  // display/visibility no se imprimen en algunos navegadores.
  frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:80mm;height:250mm;border:0'
  document.body.appendChild(frame)

  const doc = frame.contentDocument
  if (!doc) { frame.remove(); return }

  doc.open()
  doc.write('<!doctype html><html lang="es"><head><meta charset="utf-8"></head><body></body></html>')
  doc.close()

  doc.title = titulo

  // Estilos de la app (incluye Vuetify, tokens de marca y las reglas scoped
  // de la vista), tal como los tenga el documento anfitrión
  document.querySelectorAll('link[rel="stylesheet"], style').forEach(nodo => {
    doc.head.appendChild(nodo.cloneNode(true))
  })

  const estilo = doc.createElement('style')
  estilo.textContent = CSS_58MM
  doc.head.appendChild(estilo)

  const clon = elemento.cloneNode(true) as HTMLElement
  clon.id = 'comprobante-impresion'
  tamanoMinimo(elemento, clon)
  doc.body.appendChild(clon)

  // Sin esperar estilos y fuentes, el diálogo puede abrirse con el comprobante
  // sin formato o con la métrica equivocada
  await esperarEstilos(doc)
  try { await (doc as Document & { fonts?: FontFaceSet }).fonts?.ready } catch { /* navegador sin Font Loading API */ }
  await new Promise(r => setTimeout(r, 120))

  const win = frame.contentWindow
  if (!win) { frame.remove(); return }

  win.onafterprint = () => frame.remove()
  win.focus()
  win.print()
  // Red de seguridad: algunos navegadores no disparan onafterprint
  setTimeout(() => frame.remove(), 60_000)
}
