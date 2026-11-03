# Kiban Core Rotina (Daily Log / Registro Diario)

Primeira versão (02/11/2026). Tarefas do dia e horas por projeto ou cliente, para equipes pequenas (escritório, home office, campo). **Não é controle de jornada**: isso é o Kiban Core Ponto, e a própria tela de resumo avisa. Código do produto no Core: `rotina`. Preço provisório (a confirmar pelo dono): R$ 49 com 5 pessoas + R$ 8 por pessoa extra (US$ 10 + 1,5).

## O que tem
- **Tarefas** (Minhas tarefas): cada colaborador monta a sua lista, marca como feita, passa para amanhã. O gestor vê as de todos.
- **Horas**: cronômetro (Iniciar / Parar) ou lançamento digitando horas e minutos, com o projeto e o que foi feito.
- **Projetos**: projeto e, se quiser, o nome do cliente. Só o gestor cadastra e muda.
- **Resumo do mês** (gestor): horas por projeto/cliente e por colaborador, tarefas feitas e planilha do Excel.

## Quem vê o quê
O dono, os administradores e quem recebeu a função "Gestor" veem tudo. Os demais veem e mudam só o que é seu (`RotinaAccess`, `RotinaPolicy`).

## Arquivos
- `app/Modules/Rotina/RotinaModule.php`: o módulo (passos: projeto, tarefa, horas).
- `Models/` (`Project`, `Task`, `Entry`), `Policies/`, `Services/RotinaAccess.php` e `RotinaReports.php`.
- `Filament/`: `Tasks`, `Entries` (com o cronômetro), `Projects` e `SummaryPage` (+ `resources/views/rotina/summary.blade.php`).
- `database/migrations/2026_11_10_100000_create_rotina_tables.php` (`ro_projects`, `ro_tasks`, `ro_entries`).
- Textos: `lang/{pt_BR,en,es}/rotina.php`. Testes: `tests/Feature/RotinaTest.php`.

## Com o Ponto (03/11/2026)
Se a empresa também tem o Ponto, o "Resumo do mês" mostra, para cada colaborador que bate ponto, a **jornada no Ponto** e a parte dela que foi lançada **em projetos** (ex.: 75%). A planilha leva as duas colunas. A Rotina continua não sendo controle de jornada.

## Limite do plano (03/11/2026)
Pessoas usando a Rotina (com tarefas ou horas): uma pessoa nova só entra enquanto houver vaga (`RotinaPolicy::create`). Projetos não contam.

## Falta (próximas versões)
Reaproveitar os clientes do Campo nos projetos e os vídeos.
