import mysql from 'mysql2/promise'

const conn = await mysql.createConnection({
  host:     '50.6.138.11',
  user:     'joseadr3__root',
  password: '@z{]gq~ytn;6',
  database: 'joseadr3_beluga',
})

console.log('✓ Conectado\n')

const [tablas] = await conn.query(`
  SELECT table_name, table_rows
  FROM information_schema.tables
  WHERE table_schema = 'joseadr3_beluga'
  ORDER BY table_name
`)

console.log('Tablas en joseadr3_beluga:')
tablas.forEach(t => console.log(`  ✓  ${t.table_name.padEnd(25)} (~${t.table_rows} filas)`))

const [sucursales]    = await conn.query('SELECT id, nombre FROM sucursales')
const [instructores]  = await conn.query('SELECT nombre FROM instructores ORDER BY nombre')
const [planes]        = await conn.query('SELECT label, mensualidad FROM planes_catalogo WHERE activo=1')
const [usuarios]      = await conn.query('SELECT nombre, email, rol FROM usuarios')

console.log('\nSucursales:',   sucursales.map(r => r.nombre))
console.log('Instructores:', instructores.map(r => r.nombre))
console.log('Planes:',       planes.map(r => `${r.label} $${r.mensualidad}`))
console.log('Usuarios:',     usuarios.map(r => `${r.nombre} (${r.rol})`))

await conn.end()
