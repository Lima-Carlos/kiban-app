<?php

// Weekly summary of all systems (App\Digest).
return [
    'title' => 'Resumen de la Semana',
    'week' => 'Semana del :from al :to',
    'previous' => 'Semana anterior',
    'next' => 'Próxima semana',
    'email_on' => 'Por correo: activado',
    'email_off' => 'Por correo: desactivado',
    'email_help' => 'Cada lunes por la mañana, el dueño y los administradores reciben este resumen en la campana y por correo. Haga clic para activar o desactivar el correo.',
    'email_turned_on' => 'El resumen vuelve a llegar por correo cada lunes.',
    'email_turned_off' => 'El resumen ya no va por correo (sigue en la campana).',
    'empty' => 'Nada para mostrar esta semana.',
    'attention' => 'Necesita atención ahora',
    'footer' => 'Todos sus sistemas Kiban juntos, en una página. Llega cada lunes por la mañana.',
    'notice_title' => 'Resumen de la semana: :week',
    'notice_body' => 'Todos sus sistemas juntos, en una página. Toque para ver.',
    'console' => 'Resumen de la semana enviado a :count empresas.',
    'timeclock' => [
        'extra' => '{1} :hours de horas extras (1 persona).|[2,*] :hours de horas extras (:count personas).',
        'absences' => '{1} 1 falta.|[2,*] :count faltas.',
        'late' => ':hours de atrasos y salidas anticipadas.',
        'missing' => '{1} 1 día con una marcación faltante.|[2,*] :count días con una marcación faltante.',
        'clean' => 'Semana sin horas extras, faltas ni atrasos (:count personas).',
    ],
    'fleet' => [
        'fuel' => '{1} :amount en 1 carga de combustible (:liters litros).|[2,*] :amount en :count cargas de combustible (:liters litros, :vehicles vehículos).',
        'no_fuel' => 'Ninguna carga de combustible registrada en la semana.',
    ],
    'field' => [
        'done' => '{0} Ninguna orden de servicio concluida.|{1} 1 orden de servicio concluida.|[2,*] :count órdenes de servicio concluidas.',
        'rating' => 'Nota media de los clientes: :rating de 5.',
        'late' => '{1} 1 orden con el horario ya pasado y aún no hecha.|[2,*] :count órdenes con el horario ya pasado y aún no hechas.',
        'quotes' => '{1} 1 presupuesto esperando la respuesta del cliente.|[2,*] :count presupuestos esperando la respuesta del cliente.',
    ],
    'agenda' => [
        'served' => '{0} Ninguna atención concluida.|{1} 1 atención concluida.|[2,*] :count atenciones concluidas.',
        'no_show' => '{1} 1 cliente no vino.|[2,*] :count clientes no vinieron.',
    ],
    'expenses' => [
        'approved' => ':amount en gastos aprobados.',
        'pending' => '{1} 1 gasto esperando aprobación.|[2,*] :count gastos esperando aprobación.',
    ],
];
