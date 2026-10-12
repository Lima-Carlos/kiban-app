<?php

return [
    'module' => 'Tareas (demostración)',

    'steps' => [
        'list' => 'Cree su primera lista',
        'list_help' => 'Las listas organizan las tareas, por ejemplo: "Tienda" u "Oficina".',
        'task' => 'Agregue una tarea',
        'task_help' => 'Toda tarea pertenece a una lista, por eso la lista va primero.',
        'complete' => 'Complete una tarea',
        'complete_help' => 'Marque una tarea como hecha para ver cómo queda.',
    ],

    'lists' => [
        'singular' => 'lista',
        'plural' => 'listas',
        'empty' => 'Ninguna lista todavía',
    ],

    'tasks' => [
        'singular' => 'tarea',
        'plural' => 'tareas',
        'empty' => 'Ninguna tarea todavía',
    ],

    'fields' => [
        'tasks' => 'Tareas',
        'name' => 'Nombre',
        'list' => 'Lista',
        'title' => 'Tarea',
        'notes' => 'Observaciones',
        'due_on' => 'Plazo',
        'completed' => 'Completada',
    ],

    'actions' => [
        'complete' => 'Completar',
        'reopen' => 'Reabrir',
    ],
];
