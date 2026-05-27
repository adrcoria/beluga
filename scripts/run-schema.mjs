import mysql from 'mysql2/promise'
import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, join } from 'path'

const __dir = dirname(fileURLToPath(import.meta.url))

const conn = await mysql.createConnection({
  host:               '50.6.138.11',
  user:               'joseadr3__root',
  password:           '@z{]gq~ytn;6',
  database:           'joseadr3_beluga',
  multipleStatements: false,   // ejecutamos uno a uno para mejor control de errores
})

console.log('✓ Conectado a MySQL en 50.6.138.11\n')

const sql = readFileSync(join(__dir, 'schema.sql'), 'utf8')

// Dividir por ; al final de línea, ignorar comentarios y vacíos
const statements = sql
  .split(/;\s*\n/)
  .map(s => s.trim())
  .filter(s => s.length > 0 && !s.startsWith('--') && !s.startsWith('/*'))

let ok = 0, err = 0

for (const stmt of statements) {
  const preview = stmt.replace(/\s+/g, ' ').slice(0, 70)
  try {
    await conn.query(stmt)
    console.log(`  ✓  ${preview}`)
    ok++
  } catch (e) {
    console.error(`  ✗  ${preview}`)
    console.error(`     → ${e.message}\n`)
    err++
  }
}

await conn.end()
console.log(`\n────────────────────────────────────────`)
console.log(`Finalizado: ${ok} sentencias OK · ${err} errores`)
