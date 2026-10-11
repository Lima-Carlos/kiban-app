<?php

return [
    'date_format' => 'd/m/Y',
    'datetime_format' => 'd/m/Y \à\s H:i',

    'menu' => [
        'account' => 'Minha conta Kiban',
    ],

    'home' => [
        'title' => 'Início',
        'welcome' => 'Bem-vindo(a) à :name',
        'progress' => 'Primeiros passos: :done de :total concluídos.',
        'all_done' => 'Primeiros passos concluídos. Tudo pronto para usar!',
        'locked' => 'Disponível depois de: :steps',
        'do_it' => 'Fazer agora',
        'no_modules' => 'Nenhum sistema contratado',
        'no_modules_help' => 'Os sistemas contratados pela empresa aparecem aqui. Fale com a Kiban para contratar.',
    ],

    'license' => [
        'read_only_badge' => 'Somente consulta',
        'read_only' => ':module está em modo somente consulta: dá para ver os dados, mas não alterar. Regularize a assinatura em "Minha conta Kiban".',
        'trial' => 'O período de teste de :module termina em :date.',
        'offline' => 'Não conseguimos falar com a Kiban há algum tempo. O sistema continua funcionando até :date. Se o aviso continuar, fale com o suporte.',
    ],

    'denied' => [
        'module' => 'Este sistema não faz parte do plano da empresa.',
        'read_only' => 'Somente consulta: os dados não podem ser alterados enquanto a assinatura não for regularizada.',
        'step' => 'Primeiro conclua o passo ":step".',
        'limit' => 'O plano da empresa permite até :limit cadastros aqui. Para cadastrar mais, aumente o plano em "Minha conta Kiban".',
        'manager' => 'Apenas o dono ou um administrador da empresa pode fazer isso.',
    ],

    'central' => [
        'title' => 'Entrar no sistema',
        'intro' => 'Digite o endereço da sua empresa.',
        'address' => 'Endereço da empresa',
        'placeholder' => 'suaempresa',
        'not_found' => 'Não encontramos uma empresa com esse endereço.',
        'go' => 'Continuar',
        'account' => 'Não sabe o endereço? Veja em <a href=":url">Minha conta Kiban</a>.',
    ],

    'not_found' => [
        'title' => 'Empresa não encontrada',
        'body' => 'Não existe uma empresa com este endereço. Confira se ele foi digitado corretamente.',
        'action' => 'Procurar minha empresa',
    ],

    'blocked' => [
        'title' => 'Acesso indisponível',
        'inactive' => 'O acesso desta empresa está suspenso. O responsável pode ver os detalhes em "Minha conta Kiban".',
        'expired' => 'Não foi possível confirmar a licença desta empresa com a Kiban. Tente de novo em alguns minutos; se continuar, fale com o suporte.',
        'unavailable' => 'Não foi possível confirmar a licença desta empresa agora. Tente de novo em alguns minutos.',
        'action' => 'Ir para Minha conta Kiban',
    ],

    'no_access' => [
        'title' => 'Sem acesso a esta empresa',
        'body' => 'Sua conta não faz parte da equipe de :company. Peça ao responsável pela empresa para convidar você.',
        'signed_as' => 'Você entrou como :email.',
        'account' => 'Ir para Minha conta Kiban',
        'retry' => 'Tentar de novo',
    ],

    'signed_out' => [
        'title' => 'Você saiu',
        'body' => 'Você saiu do sistema de :company.',
        'denied_title' => 'Entrada cancelada',
        'denied' => 'A entrada foi cancelada. Você pode tentar de novo quando quiser.',
        'again' => 'Entrar de novo',
    ],

    'failed' => [
        'title' => 'Não foi possível entrar',
        'expired' => 'O link de entrada expirou ou já foi usado. Tente de novo.',
        'core' => 'Não conseguimos falar com a Kiban agora. Tente de novo em alguns minutos.',
        'again' => 'Tentar de novo',
    ],

    'console' => [
        'licenses' => 'Licenças atualizadas: :ok. Sem resposta do Core: :failed.',
    ],
];
