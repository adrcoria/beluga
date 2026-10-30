<?php

declare(strict_types=1);

/**
 * Permisos por rol. ARCHIVO GENERADO — no editar a mano.
 *
 * Sale de la matriz que llenó el cliente:
 *     python scripts/generar_permisos.py docs/matriz_accesos_por_rol.xlsx
 *
 * Generado el 2026-10-28.
 *
 * Niveles:
 *   'total' → entra y modifica
 *   'ver'   → entra y consulta; el backend rechaza POST/PUT/PATCH/DELETE
 *   'no'    → no entra; el menú ni aparece
 *
 * `rutas` son prefijos bajo /api. `lee` son módulos cuyos datos este
 * módulo necesita consultar aunque el rol no tenga ese menú: Cumpleañeros,
 * por ejemplo, lista alumnos sin dar acceso al módulo de Alumnos.
 */
return [

    // Roles válidos, deben coincidir con el ENUM de usuarios.rol
    'roles' => ['administrador', 'gerencia', 'operativo', 'instructor', 'coordinador'],

    'matriz' => [
        'dashboard' =>     ['administrador' => 'total', 'gerencia' => 'total', 'operativo' => 'total', 'instructor' => 'no'   , 'coordinador' => 'no'   ],
        'calendario' =>    ['administrador' => 'total', 'gerencia' => 'total', 'operativo' => 'total', 'instructor' => 'ver'  , 'coordinador' => 'ver'  ],
        'asistencia' =>    ['administrador' => 'total', 'gerencia' => 'total', 'operativo' => 'total', 'instructor' => 'total', 'coordinador' => 'total'],
        'reposiciones' =>  ['administrador' => 'total', 'gerencia' => 'total', 'operativo' => 'total', 'instructor' => 'no'   , 'coordinador' => 'ver'  ],
        'alumnos' =>       ['administrador' => 'total', 'gerencia' => 'total', 'operativo' => 'total', 'instructor' => 'ver'  , 'coordinador' => 'ver'  ],
        'instructores' =>  ['administrador' => 'total', 'gerencia' => 'ver'  , 'operativo' => 'ver'  , 'instructor' => 'ver'  , 'coordinador' => 'total'],
        'cumpleanos' =>    ['administrador' => 'total', 'gerencia' => 'total', 'operativo' => 'total', 'instructor' => 'total', 'coordinador' => 'total'],
        'cobranza' =>      ['administrador' => 'total', 'gerencia' => 'total', 'operativo' => 'total', 'instructor' => 'no'   , 'coordinador' => 'no'   ],
        'reportes' =>      ['administrador' => 'total', 'gerencia' => 'ver'  , 'operativo' => 'no'   , 'instructor' => 'no'   , 'coordinador' => 'no'   ],
        'usuarios' =>      ['administrador' => 'total', 'gerencia' => 'no'   , 'operativo' => 'no'   , 'instructor' => 'no'   , 'coordinador' => 'no'   ],
        'catalogos' =>     ['administrador' => 'total', 'gerencia' => 'ver'  , 'operativo' => 'ver'  , 'instructor' => 'ver'  , 'coordinador' => 'ver'  ],
        'parametros' =>    ['administrador' => 'total', 'gerencia' => 'ver'  , 'operativo' => 'no'   , 'instructor' => 'no'   , 'coordinador' => 'no'   ],
    ],

    'modulos' => [
        'dashboard' =>     ['rutas' => [], 'lee' => ['alumnos', 'cobranza']],
        'calendario' =>    ['rutas' => ['/calendario', '/horarios', '/grupos', '/slots'], 'lee' => ['alumnos', 'instructores']],
        'asistencia' =>    ['rutas' => ['/asistencias'], 'lee' => ['alumnos']],
        'reposiciones' =>  ['rutas' => ['/reposiciones', '/inhabiles', '/ausencias'], 'lee' => ['alumnos', 'instructores']],
        'alumnos' =>       ['rutas' => ['/alumnos'], 'lee' => ['catalogos']],
        'instructores' =>  ['rutas' => ['/instructores'], 'lee' => []],
        'cumpleanos' =>    ['rutas' => [], 'lee' => ['alumnos']],
        'cobranza' =>      ['rutas' => ['/cobros', '/pagos', '/folio-pago'], 'lee' => ['alumnos', 'catalogos']],
        'reportes' =>      ['rutas' => ['/reportes'], 'lee' => []],
        'usuarios' =>      ['rutas' => ['/usuarios'], 'lee' => []],
        'catalogos' =>     ['rutas' => ['/planes'], 'lee' => []],
        'parametros' =>    ['rutas' => ['/configuracion'], 'lee' => []],
    ],

    // Alcanzables por cualquier usuario con sesión: catálogos que casi
    // todas las pantallas necesitan para armarse.
    'libres' => ['/sucursales', '/modalidades'],
];
