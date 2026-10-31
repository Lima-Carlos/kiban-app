<?php

// Conexiones entre los sistemas (App\Connections): la ventana "sus sistemas ahora trabajan juntos", la página
// "Sistemas juntos" y los textos de las funciones que unen dos sistemas.
return [
    'and' => 'y',
    'window' => [
        'title' => 'Novedad: sus sistemas ahora trabajan juntos',
        'intro' => 'Su empresa usa :systems. Vea lo que un sistema hace por el otro:',
        'see_where' => 'Ver dónde',
        'see_all' => 'Ver todas',
        'forever' => 'No mostrar de nuevo',
        'ok' => 'Entendido',
    ],
    'page' => [
        'title' => 'Sistemas juntos',
        'help' => 'Lo que los sistemas de su empresa hacen juntos: lo que uno registra, el otro lo aprovecha.',
        'active' => 'Funcionando ahora',
        'none' => 'Con un solo sistema todavía no hay conexiones. Aparecen cuando la empresa usa dos o más sistemas de Kiban Core.',
        'with' => 'Con :system, usted también tendría',
        'with_help' => 'Estas conexiones empiezan a funcionar solas cuando se contrata el sistema.',
        'plans' => 'Conocer :system y los planes',
    ],
    'items' => [
        'punch_reminder' => [
            'title' => 'Recordatorio de la marcación en la primera y la última orden',
            'body' => 'Al tocar "Llegué" en la primera orden del día sin haber marcado la entrada, el técnico ve "¿Marcar la entrada ahora?". Al terminar la última orden, aparece el recordatorio de la salida. La marcación la sigue haciendo el empleado, con selfie y ubicación.',
        ],
        'utilization' => [
            'title' => 'Aprovechamiento de cada técnico',
            'body' => 'En el panel de Campo, las horas trabajadas en el Control horario quedan al lado de las horas en atención de las órdenes. Así usted ve cuánto del día se convirtió en servicio al cliente.',
        ],
        'field_km_to_fleet' => [
            'title' => 'El kilometraje de la llegada va a la Flota',
            'body' => 'El kilometraje que el técnico informa al llegar al cliente entra en el historial del vehículo en la Flota. Nadie lo escribe dos veces, y el cambio de aceite y el mantenimiento quedan al día.',
        ],
        'travel_cost' => [
            'title' => 'Costo estimado del desplazamiento',
            'body' => 'En la orden terminada y en el panel de Campo, el gestor ve cuánto costó, más o menos, llegar al cliente: los kilómetros recorridos por el costo por kilómetro del vehículo en la Flota.',
        ],
        'inspection_after_punch' => [
            'title' => 'Inspección recordada al marcar la entrada',
            'body' => 'Cuando el conductor marca la entrada y su vehículo todavía no tuvo la inspección, la pantalla de marcación muestra "Hacer la inspección del vehículo ahora". Es solo una sugerencia.',
        ],
        'documents_license' => [
            'title' => 'La licencia de conducir registrada por RR. HH. avisa a la Flota',
            'body' => 'RR. HH. registra la licencia en Personas → Documentos. La ficha del conductor en la Flota se actualiza sola, y el gestor de la Flota la ve en "Atención" cuando vence.',
        ],
        'documents_training' => [
            'title' => 'Las capacitaciones de seguridad avisan a Campo',
            'body' => 'Las capacitaciones registradas por RR. HH. (en Brasil, NR-10, NR-35 y NR-33) llegan a la oficina de Campo: el técnico con una capacitación vencida aparece en "Atención" y con un aviso al agendarlo.',
        ],
        'epi_field' => [
            'title' => 'El EPP entregado avisa a Campo',
            'body' => 'El tipo de servicio puede indicar qué EPP necesita (ej.: trabajo en altura → arnés de seguridad). El técnico que no recibió ese EPP aparece con un aviso al agendarlo y al abrir la orden en el celular. Es solo un aviso: nada se bloquea.',
        ],
        'one_person' => [
            'title' => 'Una persona, un solo registro',
            'body' => 'Cada persona se registra una vez en Personas y entra con el mismo usuario en todos los sistemas. Los perfiles listos, como "Técnico de campo" y "Conductor", ya dan acceso a los dos sistemas a la vez.',
        ],
    ],
    'clock' => [
        'in' => 'Usted todavía no marcó la entrada. ¿Marcar la entrada ahora?',
        'in_help' => 'Su llegada ya quedó registrada. La marcación la hace usted, en la pantalla de marcación.',
        'in_button' => 'Marcar la entrada',
        'out' => 'Este era el último servicio de hoy. ¿Marcar la salida ahora?',
        'out_help' => 'El servicio ya terminó. La marcación la hace usted, en la pantalla de marcación.',
        'out_button' => 'Marcar la salida',
        'later' => 'Ahora no',
    ],
    'inspection' => [
        'title' => 'Hacer la inspección del vehículo ahora',
        'help' => 'Su vehículo todavía no tuvo la inspección. Toma 1 o 2 minutos.',
        'do' => 'Hacer la inspección: :vehicle',
    ],
    'dashboard' => [
        'utilization' => 'Aprovechamiento',
        'utilization_hours' => ':service de :worked',
        'utilization_help' => 'Aprovechamiento = horas en atención de las órdenes (de la llegada al final) ÷ horas trabajadas en el Control horario.',
        'travel' => 'Desplazamiento (estimación)',
        'travel_help' => 'Desplazamiento = estimación: kilómetros hasta el cliente (el kilometraje de la llegada menos el anterior del mismo día) × costo por kilómetro del vehículo en la Flota.',
    ],
    'order' => [
        'travel' => 'Costo del desplazamiento (estimación)',
        'travel_help' => 'Kilómetros recorridos hasta el cliente × :cost por :unit (costo del vehículo en la Flota en los últimos meses).',
    ],
];
