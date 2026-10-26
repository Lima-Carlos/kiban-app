# Módulos e primeiros passos

**O que é:** cada sistema vendido (Frota, Ponto...) é um **módulo** dentro da casca. O módulo `demo` (Tarefas) é o exemplo que os sistemas de verdade vão copiar.

## Um módulo tem
- **Chave:** o código do produto no Core (ex.: `frota`). É assim que a licença liga o módulo.
- **Telas** (Filament): aparecem no menu quando o módulo é o sistema escolhido no seletor (veja abaixo).
- **Regras** (`ModulePolicy`): módulo contratado, somente consulta, passos obrigatórios e limite do plano. Elas valem em todo lugar, não só nas telas.
- **Primeiros passos:** a lista do que fazer, em ordem.

## Assistente de primeiros passos
- Fica na página **Início** do sistema escolhido, com uma barra de progresso.
- Cada passo olha os **dados reais** (ex.: "já existe uma lista?"). Ninguém precisa marcar nada à mão.
- Um passo pode **depender** de outros. Enquanto eles não estão feitos, o passo aparece com cadeado e a frase "Disponível depois de: ...".
- A tela que depende de outra explica o motivo. Exemplo: em Tarefas, sem nenhuma lista, aparece "Primeiro conclua o passo 'Crie sua primeira lista'". Assim o cliente não tenta cadastrar algo que depende de outra coisa antes.
- O andamento é enviado ao Core logo depois de cada mudança e também a cada 5 minutos. Só é enviado quando algo mudou. No Core, isso aparece na ficha do cliente → **Primeiros passos**, e o e-mail automático de ajuda usa essa informação.

## Seletor de sistemas (aprovado pelo dono em 25/10/2026)
- No **topo do menu da esquerda**, um ícone para cada sistema que a pessoa pode usar (ex.: Frota e Ponto), lado a lado. O escolhido fica azul.
- Ao tocar num ícone:
  - o **menu** mostra só as telas daquele sistema (acaba o menu comprido com todos os sistemas abertos);
  - o **Início** vira o Início daquele sistema: o quadro "Atenção", os resumos, o bloco pessoal (ex.: "Bater ponto") e os primeiros passos.
- **Lembra o último sistema** de cada pessoa em cada empresa. Na primeira vez, abre o primeiro da lista.
- **Troca sozinho:** quem abre uma tela de outro sistema (por exemplo, um aviso do sino da Frota enquanto está no Ponto) passa para aquele sistema.
- **Numerinho vermelho** no ícone dos outros sistemas: quantos itens há no "Atenção" deles. Assim nada passa despercebido (atualiza a cada 2 minutos).
- **"Empresa"** (Pessoas, Perfis de acesso, Equipes) fica fixo no fim do menu de todos os sistemas, só para quem gerencia pessoas.
- **Quem usa um só sistema não vê os ícones.**
- No celular, os ícones ficam no topo do menu que abre pelo botão ☰.
- O sino continua mostrando os avisos de todos os sistemas.

## O módulo de demonstração (Tarefas)
- Passos:
  1. Crie sua primeira lista;
  2. Adicione uma tarefa (depende da lista);
  3. Conclua uma tarefa.
- Limite do plano: `tasks` (se o plano no Core definir).
- Só o dono ou um administrador da empresa apaga listas.

## Como criar um módulo novo (para o programador)
1. Pasta `app/Modules/{Nome}/` com:
   - a classe do módulo (estende `Module`);
   - `Models/` (com `BelongsToTenant` e `ReportsFirstSteps`);
   - `Policies/` (estendem `ModulePolicy`);
   - `Filament/` (telas que estendem `ModuleResource`).
2. Tabelas com `tenant_id` e chaves compostas entre tabelas.
3. Listar a classe em `config/kiban.php` → `modules`.
4. Textos em `lang/{pt_BR,en,es}/{modulo}.php`.
5. Testes de isolamento e de passos.

## Arquivos
- `app/Modules/`:
  - `Module`, `Step`, `ModuleRegistry`;
  - `ModulePolicy`, `ReportsFirstSteps`, `OnboardingReporter`.
- `app/Modules/Demo/`: o módulo de demonstração (classe, modelos, regras e telas).
- `app/Filament/Support/ModuleResource.php` e `ModuleRecordsPage.php`.
- `app/Filament/Pages/Home.php` + `resources/views/filament/pages/home.blade.php`: página Início.
- `app/Providers/Filament/AppPanelProvider.php`: o painel da casca.
- `app/Providers/AppServiceProvider.php`: liga as regras de cada módulo.
- `database/migrations/2026_10_11_100100_create_demo_tables.php`.
- Traduções: `lang/*/app.php` e `lang/*/demo.php`. Os textos padrão do Laravel (`auth`, `validation`...) vieram do Core.
- Testes: `tests/Feature/FirstStepsTest.php`.
- Seletor de sistemas:
  - `app/Access/CurrentModule.php` (o sistema atual: escolhido, lembrado, trocado pela tela aberta);
  - `app/Access/ModuleSwitcher.php` (os ícones e os numerinhos);
  - `app/Modules/Concerns/InModuleMenu.php` (cada tela de sistema só aparece no menu do seu sistema);
  - `resources/views/filament/module-switcher.blade.php` (a fileira de ícones);
  - coluna `tenant_user.current_module`: `database/migrations/2026_10_26_100000_add_current_module_to_tenant_user.php`;
  - testes: `tests/Feature/ModuleSwitcherTest.php`.
