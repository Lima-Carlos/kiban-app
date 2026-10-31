<?php

return [
    'module' => 'Tasks (demo)',

    'steps' => [
        'list' => 'Create your first list',
        'list_help' => 'Lists organize the tasks, for example: "Store" or "Office".',
        'task' => 'Add a task',
        'task_help' => 'Every task belongs to a list, so the list comes first.',
        'complete' => 'Complete a task',
        'complete_help' => 'Mark a task as done to see how it looks.',
    ],

    'lists' => [
        'singular' => 'list',
        'new' => 'New list',
        'plural' => 'lists',
        'empty' => 'No lists yet',
    ],

    'tasks' => [
        'singular' => 'task',
        'new' => 'New task',
        'plural' => 'tasks',
        'empty' => 'No tasks yet',
    ],

    'fields' => [
        'tasks' => 'Tasks',
        'name' => 'Name',
        'list' => 'List',
        'title' => 'Task',
        'notes' => 'Notes',
        'due_on' => 'Due date',
        'completed' => 'Completed',
    ],

    'actions' => [
        'complete' => 'Complete',
        'reopen' => 'Reopen',
    ],
];
