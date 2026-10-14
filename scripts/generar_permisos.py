"""
Genera backend/config/permisos.php a partir de la matriz que llena el cliente.

    python scripts/generar_permisos.py ruta/al/matriz_accesos_por_rol.xlsx

La matriz es el documento de origen: si el cliente cambia un permiso, se
reemplaza el Excel y se vuelve a correr esto. No editar permisos.php a mano,
se pierde en la siguiente regeneración.

El Excel solo define QUÉ ve cada rol. El mapa de qué rutas del API pertenecen a
cada módulo vive abajo, en RUTAS, porque eso es cosa del código y no del cliente.
"""
import sys
from datetime import date
from pathlib import Path
from openpyxl import load_workbook

# ── Qué rutas del API cubre cada módulo ──────────────────────────────────
# `rutas` son prefijos bajo /api. `lee` son módulos cuyos datos este módulo
# necesita consultar aunque el rol no tenga el menú: Cumpleañeros, por ejemplo,
# lista alumnos sin dar acceso al módulo de Alumnos.
RUTAS = {
    'dashboard':    {'rutas': [],                                          'lee': ['alumnos', 'cobranza']},
    'calendario':   {'rutas': ['/calendario', '/horarios', '/grupos', '/slots'], 'lee': ['alumnos', 'instructores']},
    'asistencia':   {'rutas': ['/asistencias'],                            'lee': ['alumnos']},
    # /ausencias es avisar que un alumno no viene: nace y muere con las reposiciones
    'reposiciones': {'rutas': ['/reposiciones', '/inhabiles', '/ausencias'], 'lee': ['alumnos', 'instructores']},
    'alumnos':      {'rutas': ['/alumnos'],                                'lee': ['catalogos']},
    'instructores': {'rutas': ['/instructores'],                           'lee': []},
    'cumpleanos':   {'rutas': [],                                          'lee': ['alumnos']},
    'cobranza':     {'rutas': ['/cobros', '/pagos', '/folio-pago'],        'lee': ['alumnos', 'catalogos']},
    'reportes':     {'rutas': ['/reportes'],                               'lee': []},
    'usuarios':     {'rutas': ['/usuarios'],                               'lee': []},
    'catalogos':    {'rutas': ['/planes'],                                 'lee': []},
    'parametros':   {'rutas': ['/configuracion'],                          'lee': []},
}

# El texto del Excel → la clave del módulo en el código
MENU_A_MODULO = {
    'Dashboard':                        'dashboard',
    'Calendario Inteligente':           'calendario',
    'Control de Asistencia':            'asistencia',
    'Reposiciones':                     'reposiciones',
    'Alumnos':                          'alumnos',
    'Instructores':                     'instructores',
    'Cumpleañeros':                     'cumpleanos',
    'Cobranza':                         'cobranza',
    'Inscripciones':                    'inscripciones',
    'Cambio de Plan':                   'cambio-plan',
    'Reportes':                         'reportes',
    'Usuarios del Sistema':             'usuarios',
    'Catálogos › Planes de pago':       'catalogos',
    'Catálogos › Parámetros generales': 'parametros',
    'Preinscripción QR':                'preinscripcion',
    'Portal del Alumno':                'portal-alumno',
    'Comunicación':                     'comunicacion',
}

NIVEL = {'Total': 'total', 'Solo ver': 'ver', 'Sin acceso': 'no'}


def rol_a_clave(etiqueta: str) -> str:
    """'Coordinador' → 'coordinador'. Debe coincidir con el ENUM de usuarios.rol."""
    return (etiqueta.strip().lower()
            .replace('á', 'a').replace('é', 'e').replace('í', 'i')
            .replace('ó', 'o').replace('ú', 'u').replace('ñ', 'n')
            .replace(' ', '_'))


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 1

    ws = load_workbook(sys.argv[1], data_only=True)['Accesos por rol']

    # Fila de encabezados: la que dice 'Sección' en la columna A
    fila_enc = next(
        (r for r in range(1, 15) if str(ws.cell(row=r, column=1).value or '').strip() == 'Sección'),
        None,
    )
    if fila_enc is None:
        print('No encontré la fila de encabezados (columna A = "Sección").')
        return 1

    # Columnas de rol: de la E hasta antes de "Observaciones"
    roles = []
    for col in range(5, ws.max_column + 1):
        etq = str(ws.cell(row=fila_enc, column=col).value or '').strip()
        if not etq or etq.startswith('Observaciones') or 'opcional' in etq.lower():
            continue
        roles.append((col, etq, rol_a_clave(etq)))

    print(f'Roles encontrados: {", ".join(c for _, _, c in roles)}')

    matriz, omitidos, sin_mapear = {}, [], []
    for r in range(fila_enc + 1, ws.max_row + 1):
        menu = str(ws.cell(row=r, column=2).value or '').strip()
        if not menu:
            continue

        modulo = MENU_A_MODULO.get(menu)
        if modulo is None:
            sin_mapear.append(menu)
            continue
        if modulo not in RUTAS:
            # El cliente dejó un módulo que no está en el paquete base
            omitidos.append(menu)
            continue

        fila = {}
        for col, _, clave in roles:
            texto = str(ws.cell(row=r, column=col).value or '').strip()
            fila[clave] = NIVEL.get(texto, 'no')
        matriz[modulo] = fila

    if sin_mapear:
        print(f'  AVISO · menús que no reconozco: {", ".join(sin_mapear)}')
    if omitidos:
        print(f'  Omitidos (fuera del paquete base): {", ".join(omitidos)}')

    faltantes = [m for m in RUTAS if m not in matriz]
    if faltantes:
        print(f'  AVISO · módulos sin fila en el Excel, quedan sin acceso: {", ".join(faltantes)}')
        for m in faltantes:
            matriz[m] = {clave: 'no' for _, _, clave in roles}

    # ── Escribir el PHP ───────────────────────────────────────────────────
    anchoM = max(len(m) for m in matriz) + 2
    anchoR = max(len(c) for _, _, c in roles) + 2

    lineas = [
        '<?php',
        '',
        'declare(strict_types=1);',
        '',
        '/**',
        ' * Permisos por rol. ARCHIVO GENERADO — no editar a mano.',
        ' *',
        ' * Sale de la matriz que llenó el cliente:',
        ' *     python scripts/generar_permisos.py docs/matriz_accesos_por_rol.xlsx',
        ' *',
        f' * Generado el {date.today().isoformat()}.',
        ' *',
        ' * Niveles:',
        " *   'total' → entra y modifica",
        " *   'ver'   → entra y consulta; el backend rechaza POST/PUT/PATCH/DELETE",
        " *   'no'    → no entra; el menú ni aparece",
        ' *',
        ' * `rutas` son prefijos bajo /api. `lee` son módulos cuyos datos este',
        ' * módulo necesita consultar aunque el rol no tenga ese menú: Cumpleañeros,',
        ' * por ejemplo, lista alumnos sin dar acceso al módulo de Alumnos.',
        ' */',
        'return [',
        '',
        '    // Roles válidos, deben coincidir con el ENUM de usuarios.rol',
        "    'roles' => [" + ', '.join(f"'{c}'" for _, _, c in roles) + '],',
        '',
        "    'matriz' => [",
    ]

    for modulo in RUTAS:                       # orden estable, el de RUTAS
        celdas = ', '.join(
            f"'{clave}' => {repr(matriz[modulo][clave]).replace(chr(34), chr(39)):<7}"
            for _, _, clave in roles
        )
        lineas.append(f"        {(repr(modulo).replace(chr(34), chr(39)) + ' =>'):<{anchoM + 4}} [{celdas}],")

    lineas += ['    ],', '', "    'modulos' => ["]

    for modulo, cfg in RUTAS.items():
        rutas = ', '.join(f"'{r}'" for r in cfg['rutas']) or ''
        lee   = ', '.join(f"'{m}'" for m in cfg['lee'])   or ''
        lineas.append(
            f"        {(repr(modulo).replace(chr(34), chr(39)) + ' =>'):<{anchoM + 4}} "
            f"['rutas' => [{rutas}], 'lee' => [{lee}]],"
        )

    lineas += [
        '    ],',
        '',
        '    // Alcanzables por cualquier usuario con sesión: catálogos que casi',
        '    // todas las pantallas necesitan para armarse.',
        "    'libres' => ['/sucursales', '/modalidades'],",
        '];',
        '',
    ]

    destino = Path(__file__).resolve().parent.parent / 'backend' / 'config' / 'permisos.php'
    destino.write_text('\n'.join(lineas), encoding='utf-8')

    print(f'\nEscrito: {destino}')
    print(f'Módulos: {len(RUTAS)} · Roles: {len(roles)}')
    print('\nResumen (módulos con algún acceso por rol):')
    for _, etiqueta, clave in roles:
        n = sum(1 for m in RUTAS if matriz[m][clave] != 'no')
        print(f'   {etiqueta:<16} {n}/{len(RUTAS)}')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
