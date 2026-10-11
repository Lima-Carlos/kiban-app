<?php

return [
    'module' => 'Tarefas (demonstração)',

    'steps' => [
        'list' => 'Crie sua primeira lista',
        'list_help' => 'As listas organizam as tarefas, por exemplo: "Loja" ou "Escritório".',
        'task' => 'Adicione uma tarefa',
        'task_help' => 'Toda tarefa pertence a uma lista, por isso a lista vem primeiro.',
        'complete' => 'Conclua uma tarefa',
        'complete_help' => 'Marque uma tarefa como feita para ver como fica.',
    ],

    'lists' => [
        'singular' => 'lista',
        'plural' => 'listas',
        'empty' => 'Nenhuma lista ainda',
    ],

    'tasks' => [
        'singular' => 'tarefa',
        'plural' => 'tarefas',
        'empty' => 'Nenhuma tarefa ainda',
    ],

    'fields' => [
        'name' => 'Nome',
        'list' => 'Lista',
        'title' => 'Tarefa',
        'notes' => 'Observações',
        'due_on' => 'Prazo',
        'completed' => 'Concluída',
    ],

    'actions' => [
        'complete' => 'Concluir',
        'reopen' => 'Reabrir',
    ],
];
