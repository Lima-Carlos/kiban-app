<?php

// Weekly summary of all systems (App\Digest).
return [
    'title' => 'Resumo da Semana',
    'week' => 'Semana de :from a :to',
    'previous' => 'Semana anterior',
    'next' => 'Próxima semana',
    'email_on' => 'Por e-mail: ligado',
    'email_off' => 'Por e-mail: desligado',
    'email_help' => 'Toda segunda de manhã, o dono e os administradores recebem este resumo no sino e por e-mail. Clique para ligar ou desligar o e-mail.',
    'email_turned_on' => 'O resumo volta a chegar por e-mail toda segunda.',
    'email_turned_off' => 'O resumo não vai mais por e-mail (continua no sino).',
    'empty' => 'Nada para mostrar nesta semana.',
    'attention' => 'Precisa de atenção agora',
    'footer' => 'Todos os seus sistemas Kiban juntos, numa página. Chega toda segunda de manhã.',
    'notice_title' => 'Resumo da semana: :week',
    'notice_body' => 'Todos os seus sistemas juntos, numa página. Toque para ver.',
    'console' => 'Resumo da semana enviado a :count empresas.',
    'timeclock' => [
        'extra' => '{1} :hours de horas extras (1 pessoa).|[2,*] :hours de horas extras (:count pessoas).',
        'absences' => '{1} 1 falta.|[2,*] :count faltas.',
        'late' => ':hours de atrasos e saídas antes da hora.',
        'missing' => '{1} 1 dia com batida faltando.|[2,*] :count dias com batida faltando.',
        'clean' => 'Semana sem horas extras, faltas ou atrasos (:count pessoas).',
    ],
    'fleet' => [
        'fuel' => '{1} :amount em 1 abastecimento (:liters litros).|[2,*] :amount em :count abastecimentos (:liters litros, :vehicles veículos).',
        'no_fuel' => 'Nenhum abastecimento registrado na semana.',
    ],
    'field' => [
        'done' => '{0} Nenhuma ordem de serviço concluída.|{1} 1 ordem de serviço concluída.|[2,*] :count ordens de serviço concluídas.',
        'rating' => 'Nota média dos clientes: :rating de 5.',
        'late' => '{1} 1 OS com o horário já passado e ainda não feita.|[2,*] :count OS com o horário já passado e ainda não feitas.',
        'quotes' => '{1} 1 orçamento esperando a resposta do cliente.|[2,*] :count orçamentos esperando a resposta do cliente.',
    ],
    'agenda' => [
        'served' => '{0} Nenhum atendimento concluído.|{1} 1 atendimento concluído.|[2,*] :count atendimentos concluídos.',
        'no_show' => '{1} 1 cliente faltou.|[2,*] :count clientes faltaram.',
    ],
    'expenses' => [
        'approved' => ':amount em despesas aprovadas.',
        'pending' => '{1} 1 despesa esperando aprovação.|[2,*] :count despesas esperando aprovação.',
    ],
];
