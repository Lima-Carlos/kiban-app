<?php

// Conexões entre os sistemas (App\Connections): a janela "seus sistemas agora trabalham juntos", a página
// "Sistemas juntos" e os textos das funções que ligam dois sistemas.
return [
    'and' => 'e',
    'window' => [
        'title' => 'Novidade: seus sistemas agora trabalham juntos',
        'intro' => 'A empresa usa :systems. Veja o que um sistema faz pelo outro:',
        'see_where' => 'Ver onde',
        'see_all' => 'Ver todas',
        'forever' => 'Não mostrar novamente',
        'ok' => 'Entendi',
    ],
    'page' => [
        'title' => 'Sistemas juntos',
        'help' => 'O que os sistemas da empresa fazem juntos: o que um registra, o outro aproveita.',
        'active' => 'Funcionando agora',
        'none' => 'Com um sistema só ainda não há conexões. Elas aparecem quando a empresa usa dois ou mais sistemas da Kiban Core.',
        'with' => 'Com o :system, você também teria',
        'with_help' => 'Estas ligações começam a funcionar sozinhas quando o sistema é contratado.',
        'plans' => 'Conhecer o :system e os planos',
    ],
    'items' => [
        'punch_reminder' => [
            'title' => 'Lembrete do ponto na primeira e na última OS',
            'body' => 'Ao tocar em "Cheguei" na primeira OS do dia sem ter batido a entrada, o técnico vê "Bater a entrada agora?". Ao concluir a última OS, vem o lembrete da saída. A batida continua sendo do funcionário, com selfie e local.',
        ],
        'utilization' => [
            'title' => 'Aproveitamento de cada técnico',
            'body' => 'No painel do Campo, as horas trabalhadas no Ponto ficam ao lado das horas em atendimento nas OS. Assim você vê quanto do dia virou serviço no cliente.',
        ],
        'field_km_to_fleet' => [
            'title' => 'O km da chegada vai para a Frota',
            'body' => 'O km que o técnico informa ao chegar no cliente entra no histórico do veículo na Frota. Ninguém digita duas vezes, e a troca de óleo e a revisão ficam em dia.',
        ],
        'travel_cost' => [
            'title' => 'Custo estimado do deslocamento',
            'body' => 'Na OS concluída e no painel do Campo, o gestor vê quanto custou, mais ou menos, ir até o cliente: os km rodados vezes o custo por km do veículo na Frota.',
        ],
        'inspection_after_punch' => [
            'title' => 'Vistoria lembrada ao bater o ponto',
            'body' => 'Quando o motorista bate a entrada e o veículo dele ainda não teve a vistoria, a tela do ponto mostra "Fazer a vistoria do veículo agora". É só uma sugestão.',
        ],
        'documents_license' => [
            'title' => 'A CNH cadastrada pelo RH avisa a Frota',
            'body' => 'O RH cadastra a CNH em Pessoas → Documentos. A ficha do motorista na Frota é atualizada sozinha, e o gestor da Frota vê no "Atenção" quando ela vence.',
        ],
        'documents_training' => [
            'title' => 'Os treinamentos NR avisam o Campo',
            'body' => 'A NR-10, a NR-35 e a NR-33 cadastradas pelo RH chegam ao escritório do Campo: o técnico com treinamento vencido aparece no "Atenção" e com um aviso na hora de agendar.',
        ],
        'one_person' => [
            'title' => 'Uma pessoa, um cadastro só',
            'body' => 'Cada pessoa é cadastrada uma vez em Pessoas e entra com o mesmo login em todos os sistemas. Os perfis prontos, como "Técnico de campo" e "Motorista", já dão o acesso aos dois sistemas de uma vez.',
        ],
    ],
    'clock' => [
        'in' => 'Você ainda não bateu o ponto. Bater a entrada agora?',
        'in_help' => 'A chegada já foi registrada. A batida é feita por você, na tela do ponto.',
        'in_button' => 'Bater a entrada',
        'out' => 'Este era o último serviço de hoje. Bater a saída agora?',
        'out_help' => 'O serviço já foi concluído. A batida é feita por você, na tela do ponto.',
        'out_button' => 'Bater a saída',
        'later' => 'Agora não',
    ],
    'inspection' => [
        'title' => 'Fazer a vistoria do veículo agora',
        'help' => 'O seu veículo ainda não teve a vistoria. Leva 1 ou 2 minutos.',
        'do' => 'Fazer a vistoria: :vehicle',
    ],
    'dashboard' => [
        'utilization' => 'Aproveitamento',
        'utilization_hours' => ':service de :worked',
        'utilization_help' => 'Aproveitamento = horas em atendimento nas OS (da chegada ao fim) ÷ horas trabalhadas no Ponto.',
        'travel' => 'Deslocamento (estimativa)',
        'travel_help' => 'Deslocamento = estimativa: km até o cliente (o km da chegada menos o anterior do mesmo dia) × custo por km do veículo na Frota.',
    ],
    'order' => [
        'travel' => 'Custo do deslocamento (estimativa)',
        'travel_help' => 'Km rodados até o cliente × :cost por :unit (custo do veículo na Frota nos últimos meses).',
    ],
];
