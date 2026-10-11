<?php

return [
    'date_format' => 'd/m/Y',
    'datetime_format' => 'd/m/Y \a \l\a\s H:i',

    'menu' => [
        'account' => 'Mi cuenta Kiban',
    ],

    'home' => [
        'title' => 'Inicio',
        'welcome' => 'Bienvenido(a) a :name',
        'progress' => 'Primeros pasos: :done de :total completados.',
        'all_done' => 'Primeros pasos completados. ¡Todo listo para usar!',
        'locked' => 'Disponible después de: :steps',
        'do_it' => 'Hacerlo ahora',
        'no_modules' => 'Ningún sistema contratado',
        'no_modules_help' => 'Los sistemas contratados por la empresa aparecen aquí. Hable con Kiban para contratar.',
    ],

    'license' => [
        'read_only_badge' => 'Solo consulta',
        'read_only' => ':module está en modo solo consulta: se pueden ver los datos, pero no modificarlos. Regularice la suscripción en "Mi cuenta Kiban".',
        'trial' => 'El período de prueba de :module termina el :date.',
        'offline' => 'No hemos podido comunicarnos con Kiban desde hace un tiempo. El sistema sigue funcionando hasta el :date. Si el aviso continúa, hable con soporte.',
    ],

    'denied' => [
        'module' => 'Este sistema no forma parte del plan de la empresa.',
        'read_only' => 'Solo consulta: los datos no se pueden modificar hasta que se regularice la suscripción.',
        'step' => 'Primero complete el paso ":step".',
        'limit' => 'El plan de la empresa permite hasta :limit registros aquí. Para registrar más, amplíe el plan en "Mi cuenta Kiban".',
        'manager' => 'Solo el dueño o un administrador de la empresa puede hacer esto.',
    ],

    'central' => [
        'title' => 'Entrar al sistema',
        'intro' => 'Escriba la dirección de su empresa.',
        'address' => 'Dirección de la empresa',
        'placeholder' => 'suempresa',
        'not_found' => 'No encontramos una empresa con esa dirección.',
        'go' => 'Continuar',
        'account' => '¿No sabe la dirección? Véala en <a href=":url">Mi cuenta Kiban</a>.',
    ],

    'not_found' => [
        'title' => 'Empresa no encontrada',
        'body' => 'No existe una empresa con esta dirección. Verifique que esté escrita correctamente.',
        'action' => 'Buscar mi empresa',
    ],

    'blocked' => [
        'title' => 'Acceso no disponible',
        'inactive' => 'El acceso de esta empresa está suspendido. El responsable puede ver los detalles en "Mi cuenta Kiban".',
        'expired' => 'No fue posible confirmar la licencia de esta empresa con Kiban. Intente de nuevo en unos minutos; si continúa, hable con soporte.',
        'unavailable' => 'No fue posible confirmar la licencia de esta empresa ahora. Intente de nuevo en unos minutos.',
        'action' => 'Ir a Mi cuenta Kiban',
    ],

    'no_access' => [
        'title' => 'Sin acceso a esta empresa',
        'body' => 'Su cuenta no forma parte del equipo de :company. Pida al responsable de la empresa que lo invite.',
        'signed_as' => 'Entró como :email.',
        'account' => 'Ir a Mi cuenta Kiban',
        'retry' => 'Intentar de nuevo',
    ],

    'signed_out' => [
        'title' => 'Usted salió',
        'body' => 'Usted salió del sistema de :company.',
        'denied_title' => 'Acceso cancelado',
        'denied' => 'El acceso fue cancelado. Puede intentar de nuevo cuando quiera.',
        'again' => 'Entrar de nuevo',
    ],

    'failed' => [
        'title' => 'No fue posible entrar',
        'expired' => 'El enlace de acceso expiró o ya fue usado. Intente de nuevo.',
        'core' => 'No pudimos comunicarnos con Kiban ahora. Intente de nuevo en unos minutos.',
        'again' => 'Intentar de nuevo',
    ],

    'console' => [
        'licenses' => 'Licencias actualizadas: :ok. Sin respuesta del Core: :failed.',
    ],
];
