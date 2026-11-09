# Leiautes de importação de eventos na folha (para o gerador do Ponto)

Pesquisa de 09/11/2026. Objetivo: gerar, a partir do fechamento do mês do Ponto, o arquivo que o contador importa no sistema de folha dele. Os eventos são: HE 50%, HE 100%, adicional noturno, faltas (dias/horas), atrasos, DSR descontado e banco de horas.

## Legenda de confiança

- **[OFICIAL]**: confirmado em documento do fabricante (URL ao lado).
- **[OFICIAL-EX]**: tirado de um exemplo oficial, mas a regra não está escrita. É dedução segura, porém precisa de teste.
- **[INFERIDO]**: vem de fórum, de integração de terceiros ou de dedução minha. Precisa ser validado com um contador que use o sistema.
- **[DESCONHECIDO]**: não encontrei. Precisa perguntar ao contador ou ao suporte do fabricante.

## Resumo de solidez

| Sistema | Tipo de arquivo | Solidez | Observação |
|---|---|---|---|
| Domínio (Thomson Reuters) | TXT de largura fixa, 48 posições (registro 10) | **Sólido** | Leiaute e exemplos oficiais. Faltam a codificação e o terminador de linha (usar padrão Windows). |
| RM Labore (TOTVS) | TXT de largura fixa, 81 posições | **Sólido** | Leiaute oficial no TDN. Há uma dúvida sobre "HHH:MM" e o separador decimal. |
| Alterdata (WDP / DP) | TXT de largura fixa, 86 posições (até 128) | **Médio** | Posições oficiais. Faltam o formato de horas no "Valor Evento" e os valores do campo "Processo". |
| Senior (Rubi/Vetorh, via Gerador modelo 35) | TXT de largura fixa, 62 posições | **Médio** | Artigo oficial de suporte, mas o modelo de importação é criado pelo cliente. Ele precisa do "código de cálculo" do Senior. |
| Fortes Pessoal (Fortes AC) | CSV (planilha) com cabeçalho e rodapé | **Médio/fraco** | Estrutura oficial descrita em texto. A sintaxe exata da linha 1 vem de uma imagem. O `.ps` do Fortes Ponto não é público. |
| Questor (Folha) | CSV/TXT `;` em matriz (empregado × eventos) | **Fraco** | O site oficial de documentação saiu do ar. Só há trechos em resultados de busca. |
| Protheus GPE (TOTVS) | TXT de largura fixa **configurável pelo cliente** (GPEA200 → GPEA210) | **Sem leiaute fixo** | Nós propomos um leiaute, e o cliente o configura no Protheus. |

---

## 1. Domínio Sistemas (Thomson Reuters) — Domínio Folha

### Fontes oficiais
- Leiaute: "Leiaute: Importação Arquivo Texto | De Lançamentos", solução 8813: https://suporte.dominioatendimento.com/central/faces/solucao.html?codigo=8813
- Como importar e tipos de processo, solução 3373: https://suporte.dominioatendimento.com/central/faces/solucao.html?codigo=3373 (versão Onvio: `solucao-onvio.html?codigo=3373`)
- PDF de exemplos (Thomson Reuters, 13/10/2023): https://suporte.dominioatendimento.com/ctsfiles/leiautearquivotexto.pdf?id=9427396&ds=centralSolucao&force=true

### Tipo de arquivo
- TXT de largura fixa, sem separador, campos numéricos completados com zeros à esquerda. **[OFICIAL]**
- Uma linha por registro. Cada linha começa pelo identificador do registro (2 dígitos). **[OFICIAL]**
- Codificação e fim de linha: **[DESCONHECIDO]**. Como o arquivo só tem dígitos, a codificação não importa. Usar ASCII com CRLF (o Domínio roda no Windows). **[INFERIDO]**
- Extensão `.txt` (o exemplo oficial chama `exemplo_leiaute_importacao.txt`). **[OFICIAL]**

### Registro 10: Lançamentos (o principal) **[OFICIAL]**

| Posição | Tam | Campo | Formato |
|---|---|---|---|
| 001-002 | 2 | Fixo `10` | `10` |
| 003-012 | 10 | Código do empregado | `0000000000` |
| 013-018 | 6 | Competência | `AAAAMM` |
| 019-027 | 9 | Código da rubrica | `000000000` |
| 028-029 | 2 | Tipo do processo | `00` |
| 030-038 | 9 | Valor | `000000000` |
| 039-048 | 10 | Empresa (código da empresa no Domínio) | `0000000000` |

Total: 48 caracteres.

### Registro 11: Dias de falta / reembolso de falta **[OFICIAL]**

| Posição | Tam | Campo | Formato |
|---|---|---|---|
| 001-002 | 2 | Fixo `11` | |
| 003-010 | 8 | Data da falta ou do reembolso | `AAAAMMDD` |
| 011-011 | 1 | Tipo da falta: `1` = Normal, `2` = DSR | |

- O exemplo oficial de faltas parciais usa "Tipo de Falta 4" (valor fora do leiaute escrito). **[OFICIAL-EX]** Há mais tipos do que 1 e 2, mas não estão documentados.
- **Ordem:** os registros 11 e 12 vêm **logo depois** do registro 10 da rubrica de falta a que se referem. Exemplo oficial: `10` (rubrica 8069, falta) → `11` (data 20230912, tipo 4) → `12` (horas 00110). **[OFICIAL-EX]**

### Registro 12: Faltas parciais (horas) **[OFICIAL]**

| Posição | Tam | Campo | Formato |
|---|---|---|---|
| 001-002 | 2 | Fixo `12` | |
| 003-007 | 5 | Quantidade de horas de faltas parciais | o leiaute diz `"0000000"`, mas o tamanho é 5. O exemplo usa `00110` (5 dígitos) **[OFICIAL-EX]** |

- No exemplo oficial, o registro 10 da mesma falta tem Valor `000000110` e o registro 12 tem `00110`. Ou seja, é a mesma quantidade (1,10) com 2 casas implícitas. **[OFICIAL-EX]**
- Há duas variações oficiais: "faltas parciais **com** data de falta" (10 → 11 → 12) e "**sem** data de falta" (10 → 12). **[OFICIAL-EX]**

### Outros registros (não usamos)
20/25 (plano de saúde), 30 (pensão alimentícia), 40 (lançamento por serviço). **[OFICIAL]**

### Formatos
- **Competência:** `AAAAMM` (ex.: `202611`). **[OFICIAL]**
- **Valor:** 9 dígitos com **2 casas decimais implícitas**, sem vírgula. Ex.: 33,33 → `000003333`; 1,10 → `000000110`. **[OFICIAL-EX]** (o PDF mostra valores "33,33", "166,78", e o exemplo 2 mostra `000000110`).
- **Horas:** usam o mesmo campo Valor, e a interpretação depende de um **parâmetro do Domínio**:
  - Na tela de importação há a opção "Horas decimais" ou "Horas Minutos". **[OFICIAL]** (solução 3373)
  - O padrão da empresa fica em Controle > Parâmetros > aba Geral > Cálculo > "Lançamento de horas". **[OFICIAL]**
  - Então 1h30 vira `000000150` em "Horas decimais" ou `000000130` em "Horas Minutos". **[INFERIDO]** (é o comportamento coerente com as 2 casas implícitas, mas o texto oficial não traz esse exemplo). O gerador deve **perguntar** qual dos dois o contador usa.
- **Dias** (ex.: faltas em dias, DSR): Valor com 2 casas (1 dia = `000000100`). **[INFERIDO]**
- **Tipo do processo** (campo 5) **[OFICIAL]**: `11` Mensal · `41` Adiantamento · `42` Complementar · `51` Adiantamento 13º · `52` 13º Salário · `70` Participação nos lucros. Para o Ponto: sempre `11`.
- **Empregado:** "Código do empregado" **interno do Domínio** (não é CPF nem PIS). **[OFICIAL]** Precisamos de um campo "código do empregado na folha" em cada funcionário.
- **Empresa:** código da empresa **no Domínio** (número do cadastro do escritório contábil). **[OFICIAL]**
- **Rubrica:** código numérico da rubrica no Domínio (cada escritório tem os seus; ex. oficial: 150 = Horas Extras, 20 = Gratificações; 8069 aparece como falta no exemplo). **[OFICIAL-EX]** Os códigos são definidos pelo contador.

### Caminho no sistema (para o contador)
**Domínio Folha: Utilitários > Importação > de Arquivo Texto > de Lançamentos.** Escolher "Horas decimais/Horas Minutos" e, em "Lançamentos existentes", "Não importar" ou "Sobrescrever". **[OFICIAL]** A Thomson Reuters recomenda excluir os lançamentos antes de reimportar o mesmo arquivo. **[OFICIAL]**

### Linha de exemplo (gerada a partir do leiaute)
Empregado 88, competência 11/2026, rubrica 150 (HE 50%), 10h30 em "Horas Minutos", empresa 11:
```
100000000088202611000000150110000010300000000011
```
Conferência: `10`|`0000000088`|`202611`|`000000150`|`11`|`000001030`|`0000000011` = 48 caracteres.

### Observação
Um fornecedor (Consisa/Biojob) usa um "Layout vertical" que põe várias rubricas numa linha pelo configurador de exportação do próprio Domínio. Não é o leiaute padrão de importação, e não devemos seguir. **[INFERIDO]** (https://consisanet.movidesk.com/kb/pt-br/article/526098)

---

## 2. Alterdata — Departamento Pessoal (WDP / "DP")

### Fontes oficiais
- "Como importar arquivo de movimento em Lançamento por funcionários": https://ajuda.alterdata.com.br/dpbase/como-importar-arquivo-de-movimento-em-lancamento-por-funcionarios-105422961.html
- WDP "Lançamento por funcionário" (mesmo leiaute, 13 campos): https://ajuda.alterdata.com.br/wdp/lancamento-por-funcionario-57399450.html
- Integração do Ponto Alterdata com o DP (arquivo `MOV.DP`, horas em minutos): https://ajuda.alterdata.com.br/ponbase/integracao-do-sistema-ponto-com-o-departamento-pessoal-alterdata-e-outros-sistemas-de-departamento-pessoal

### Tipo de arquivo
- TXT de largura fixa, sem separador. **[OFICIAL]** Aceita "qualquer outro sistema" se tiver "a mesma estrutura do arquivo de movimentos do sistema Alterdata". **[OFICIAL]**
- Nome padrão do arquivo: `MOV.DP` (é o que o Ponto Alterdata gera e o DP procura). **[OFICIAL]** (página de integração do Ponto). A tela de importação deixa o usuário trocar o nome. **[OFICIAL]**
- Codificação e fim de linha: **[DESCONHECIDO]**. Usar ASCII + CRLF. **[INFERIDO]**

### Leiaute (uma linha por evento por funcionário) **[OFICIAL]**

| # | Campo | De | Até | Tam | Tipo | Notas |
|---|---|---|---|---|---|---|
| 1 | Sequencial | 1 | 6 | 6 | N | número da linha (1, 2, 3…) **[INFERIDO]** |
| 2 | Código da empresa | 7 | 11 | 5 | N | código no Alterdata |
| 3 | Referência 1 (DDMMAA) | 12 | 17 | 6 | N | início do período **[INFERIDO]** |
| 4 | Referência 2 (DDMMAA) | 18 | 23 | 6 | N | fim do período **[INFERIDO]** |
| 5 | Faltas (em minutos) | 24 | 29 | 6 | N | obrigatório, 0 se não houver |
| 6 | Horas trabalhadas | 30 | 35 | 6 | N | obrigatório, 0 se não houver (unidade não documentada; provavelmente minutos **[INFERIDO]**) |
| 7 | Dias úteis | 36 | 37 | 2 | N | obrigatório, 0 se não houver |
| 8 | Código do evento | 38 | 40 | 3 | N | código do evento no DP |
| 9 | Valor do evento | 41 | 54 | 14 | N (14;2) | 2 casas decimais implícitas **[INFERIDO]** pela notação "(14;2)" |
| 10 | Código do funcionário | 55 | 60 | 6 | N | código no DP |
| 11 | (Tipo de) Processo | 61 | 61 | 1 | A | valores **[DESCONHECIDO]** |
| 12 | CNPJ ou CPF da empresa | 62 | 75 | 14 | N | **opcional**; se vier, é lido primeiro |
| 13 | PIS do funcionário | 76 | 86 | 11 | N | **opcional**; 0 se não houver; se vier, é lido primeiro |
| 14–22 | Plano de saúde (departamento, operadora, plano, beneficiário, CPF, forma de apuração S/N) | 87 | 128 | | | só na versão "dpbase"; não usamos (ver nota) |

- **Identificação** **[OFICIAL]**: se houver CNPJ/CPF da empresa e PIS do funcionário, o sistema usa "a primeira empresa ativa que possuir o CNPJ/CPF" e o PIS. Se não achar, cai para os códigos de empresa e funcionário. **Recomendação: preencher sempre CNPJ e PIS**, além dos códigos, porque assim o contador nem precisa mapear códigos.
- A página "dpbase" lista os campos 14–22 (posições 87–128) como "obrigatórios". A página WDP para no campo 13 (posição 86). **[OFICIAL]**, mas contraditório. Para o Ponto: gerar até a posição 86. Se o DP reclamar, completar 87–128 com zeros e `N`. **[INFERIDO]**
- **Horas no Valor do evento:** a página do Ponto Alterdata diz que, para exportar para o DP Alterdata, é preciso marcar "visualizar em **MINUTOS**". **[OFICIAL]** Então eventos de hora provavelmente vão em minutos (1h30 = 90 → `00000000009000` com 2 casas implícitas?). Não está claro se as casas implícitas se aplicam aos minutos. **[INFERIDO / testar]**
- **Processo (pos. 61):** a tela de importação pede para "marcar os tipos de processos". As letras não estão publicadas. **[DESCONHECIDO]** Suposição a testar: `F` (folha mensal). Perguntar ao contador ou gerar com o Ponto Alterdata de teste.

### Caminho no sistema
**WDP/DP: aba Folha > Lançamento por Funcionário > Importar** → selecionar o arquivo (a pasta e o nome vêm sugeridos). Informar empresa e período. Se já houver movimentos iguais, o sistema pergunta se sobrepõe. **[OFICIAL]**

---

## 3. Questor — Folha de Pagamento (Questor Gestão Contábil / Cloud)

### Fontes
- Blog oficial (03/04/2023, arquivado): https://blog.questor.com.br/novidade-na-folha-de-pagamento-importacao-de-dados-de-eventos-variaveis-por-arquivo-csv/ **[OFICIAL]**
- Documento oficial citado pelo blog: `docs.questor.com.br/docs/folha-de-pagamento/eventos--variaveis-importacao-de-dados-por-arquivo` e a versão Cloud `docs-consulta.questor.com.br/docs/questor-cloud/eventos-variaveis-importacao-de-dados-por-arquivo-cloud`. **Os dois saíram do ar** (404 / domínio inexistente em 09/11/2026) e o Internet Archive estava fora. Os dados abaixo vêm de **trechos desses documentos nos resultados de busca**. **[OFICIAL via trecho; não li a página inteira]**

### Tipo de arquivo
- CSV ou TXT, "com colunas variáveis". Disponível a partir da versão **23.3.0.0**. **[OFICIAL]**
- Separador `;` (ponto e vírgula). **[OFICIAL via trecho]**
- Formato **matriz**: uma linha por empregado, uma coluna por evento. **[OFICIAL via trecho]**
  - Linha 1: a partir da coluna 3, o **código do evento + "letra informativa"** de cada coluna (a letra diz se o dado é valor, referência ou horas). **[OFICIAL via trecho]** As letras exatas são **[DESCONHECIDO]** (minha suposição: `V` valor, `R` referência, `H` horas **[INFERIDO]**).
  - Coluna 1: **código do empregado** (obrigatório). **[OFICIAL via trecho]**
  - Coluna 2: **não é avaliada** (pode ser o nome, para conferência). **[OFICIAL via trecho]**
  - Colunas 3 em diante: valores, referências ou horas. **[OFICIAL via trecho]**
  - A ordem das colunas de evento é livre, desde que código e letra estejam corretos. **[OFICIAL via trecho]**
- Qualquer inconsistência **aborta a importação inteira**. **[OFICIAL via trecho]**
- **Horas:** a documentação de eventos variáveis do Questor diz que eventos de horas são informados **em minutos** na referência (2 horas = `120`). **[OFICIAL via trecho]** Não está confirmado se a importação por planilha segue a mesma regra. **[INFERIDO]**
- Separador decimal: provavelmente vírgula (padrão pt-BR). **[INFERIDO]**
- Empresa e competência: escolhidas **na tela**, não vêm no arquivo. **[INFERIDO]** (o blog diz que "abrirá uma tela com todas as informações que você precisa preencher além do campo para importar").
- Codificação: **[DESCONHECIDO]**. Usar Windows-1252 se tiver acento (ou deixar a coluna 2 sem acento). **[INFERIDO]**

### Esboço de arquivo (a validar)
```
Empregado;Nome;150H;151H;200H;300R
12;JOAO SILVA;630;0;120;1
15;MARIA SOUZA;0;90;0;0
```
A sintaxe "150H" (código e letra juntos) é **[INFERIDO]**. Talvez a letra fique em outra linha ou com separador. **Precisa do modelo oficial.**

### Caminho no sistema
**Questor Gestão Contábil: Cálculo > Eventos Variáveis > Importação Planilha.** **[OFICIAL]** (blog). No Cloud: Operações / Cálculo / Eventos Variáveis. **[OFICIAL via trecho]**

### Ação recomendada
Pedir a um contador que use Questor o modelo da tela "Importação Planilha" (ou um arquivo exportado). O ioPoint (outro relógio de ponto) tem "Questor Layouts 1, 2 e 3", o que mostra que há mais de um formato em uso. **[INFERIDO]** (https://iopedia.iopoint.com.br/books/exportacoes/export/pdf)

---

## 4. Fortes Tecnologia — Fortes Pessoal (Fortes AC)

### Fontes oficiais
- "Importação dos Movimentos da Folha via Planilha" (07/11/2025): https://ajuda.fortestecnologia.com.br/kb/pt-br/article/563718/importacao-dos-movimentos-da-folha-via-planilha
- "Importação de Movimentos" (tela, abas, `.ps` e `.csv`): https://ajuda.fortestecnologia.com.br/kb/pt-br/article/563737/importacao-de-movimentos
- Leiaute do `.ps` ("Leiaute de Importação de Movimentos do Fortes Pessoal v6"): https://ajuda.fortestecnologia.com.br/kb/pt-br/article/422217 O documento **não é público**: vem instalado na pasta `C:\Fortes\AC`. **[OFICIAL]**
- Fortes Ponto → Fortes Pessoal: https://ajuda.fortestecnologia.com.br/kb/pt-br/article/292943

### Dois formatos
1. **`.ps`**: o arquivo do Fortes Ponto (leiaute v6, com registros "detalhe" como "Eventos por Data"). Leiaute **não publicado na internet**. **[DESCONHECIDO]** Para usar, seria preciso pedir ao contador o PDF que fica em `C:\Fortes\AC`.
2. **`.csv` (planilha)**: o caminho aberto para outros sistemas. **[OFICIAL]**

### Leiaute CSV ("Eventos_da_Folha_TC")
- Só `.csv`. **[OFICIAL]**
- **Linha 1 (cabeçalho):** "Constante `0`, Versão `1`, Sistema de Origem `TC`, Código da Empresa". **[OFICIAL]**
  - Na imagem oficial, a célula A1 mostra `01TC0006`, ou seja, tudo junto numa célula: `0` + `1` + `TC` + código da empresa com 4 dígitos (`0006`). **[OFICIAL-EX]** (não sei se a empresa tem sempre 4 dígitos **[INFERIDO]**).
- **Linhas de dados (da linha 2 em diante):** Código do Empregado ; Código do Evento ; Referência ; Valor do Evento. **[OFICIAL]**
  - Valor e referência com **vírgula decimal** (ex.: `10,50`). **[OFICIAL]**
  - A Referência é opcional. **[OFICIAL]**
  - A imagem oficial mostra o empregado como `000029` (6 dígitos) e o evento como `030` (3 dígitos), em texto com zeros à esquerda. **[OFICIAL-EX]**
  - A imagem também mostra uma linha de títulos ("Cód.Empregado; Cód. Evento; Referência; Valor do Evento") entre o cabeçalho e os dados. O texto oficial diz que os dados começam na linha 2, então essa linha deve ser só ilustração. **[DESCONHECIDO]**: conferir no modelo baixado.
- **Última linha (rodapé):** Constante `Z` ; Total de movimentos ; Total de referência ; Total do valor dos eventos. **[OFICIAL]** Exemplo da imagem: `Z;1;;300,6`. **[OFICIAL-EX]**
- Separador: `;`. **[INFERIDO]** É o padrão do Excel pt-BR; a imagem é de planilha, então o separador não aparece.
- Campo obrigatório vazio ou com formato errado **rejeita o arquivo** (vai para a aba "com Erros"). **[OFICIAL]**
- **Horas:** a "Referência" do evento. Formato **[DESCONHECIDO]** (decimal ou hh,mm). No Fortes, a referência de eventos de horas costuma ser em horas decimais. **[INFERIDO]**
- Competência e tipo de folha: escolhidos **na tela** ("Mês/Ano, Seq. ou Ano"), não no arquivo. **[OFICIAL]**
- Codificação: **[DESCONHECIDO]**. Usar Windows-1252/ASCII; o arquivo não tem texto livre.

### Esboço de arquivo
```
01TC0006
000029;030;10,50;0
000029;031;2,00;0
Z;2;12,50;0
```
(Fica a dúvida se o valor pode ser 0 quando só a referência é informada. Testar.)

### Caminho no sistema
**Fortes Pessoal: Movimentos > Importação** (tela "Importação dos Movimentos da Folha") → "Procurar" (escolher o `.csv`) → marcar → "Importar Marcadas" → marcar "Eventos da Folha", escolher a movimentação e o Mês/Ano → OK. O modelo oficial sai em **Movimentos > Importação > Download da Planilha**. A pasta de entrada automática fica em Utilitários > Configuração > Importação. **[OFICIAL]**

---

## 5. Senior — Rubi / Vetorh (HCM clássico) e Senior X

### Fontes oficiais
- Suporte Senior, "HCM – Gerador Importação/Exportação – Como importar eventos variáveis": https://suporte.senior.com.br/hc/pt-br/articles/20179020260628
- Senior X Gestão do Ponto X, "Exportação de eventos para Folha" (campos disponíveis): https://documentacao.senior.com.br/seniorxplatform/manual-do-usuario/hcm/gestao-do-ponto-x/calculo-de-eventos/exportacao-de-eventos-folha
- Senior X Folha X, "Importação de eventos variáveis" (CSV, modelo baixado na tela): https://documentacao.senior.com.br/seniorxplatform/manual-do-usuario/hcm/folha-x/lancamentos/importacao-variaveis
- Senior X Gestão de DP, "Importar arquivo de lançamentos" (CSV): https://documentacao.senior.com.br/en/seniorxplatform/manual-do-usuario/hcm/gestao-de-departamento-pessoal/lancamentos-financeiros/importar-arquivo-de-lancamentos.htm

### A) Rubi/Vetorh (desktop): Gerador de Importação, "Modelo 35 – Lançamentos"
- O cliente cria o modelo de importação. O artigo oficial mostra o leiaute **sugerido**, de largura fixa. Usam-se **dois arquivos** separados: um para VALEVE e outro para REFEVE. **[OFICIAL]**

| # | Campo | Pos | Tam | Descrição | Exemplo |
|---|---|---|---|---|---|
| 1 | CODREG | 1 | 2 | código do registro (fixo) | `01` |
| 2 | NUMEMP | 3 | 4 | código da empresa no Senior | `0001` |
| 3 | TIPCOL | 7 | 1 | tipo de colaborador (1 = empregado) | `1` |
| 4 | NUMCAD | 8 | 9 | cadastro (matrícula) | `000000006` |
| 5 | CODCAL | 17 | 5 | **código do cálculo** (identifica competência e tipo de folha no Senior) | `00404` |
| 6 | TABEVE | 22 | 3 | tabela de eventos | `941` |
| 7 | CODEVE | 25 | 4 | código do evento | `0092` |
| 8 | CODRAT | 29 | 9 | rateio (zeros se não usar) | `000000000` |
| 9 | SEQEVE | 38 | 2 | sequência | `01` |
| 10 | REFEVE | 40 | 11 | referência (horas, %); zeros se só valor | `00000000230` |
| 11 | VALEVE | 51 | 11 | valor; zeros se só referência | `00000058923` |
| 12 | ORIMOV | 62 | 1 | origem do movimento | `I` |

- **Valor:** 2 casas implícitas (R$ 589,23 → `00000058923`). **[OFICIAL]**
- **Horas:** "2 horas e 30 minutos" → `00000000230`, ou seja, **HH com MM nas 2 últimas casas** (sexagesimal). **[OFICIAL-EX]** A regra não está escrita. O Ponto X da Senior confirma a ideia: a "Referência parte decimal" é em minutos (34:45 → `34` e `45`). **[OFICIAL]**
- **Empregado:** NUMEMP + TIPCOL + NUMCAD. **[OFICIAL]**
- **Competência:** não há campo. Ela vem pelo **CODCAL**, um número interno do Senior que o contador precisa nos informar a cada mês. **[OFICIAL]** (campo existe; o fato de mudar a cada mês é **[INFERIDO]**). Na prática, é melhor deixar o CODCAL como parâmetro na hora de gerar.
- **TABEVE** depende do cliente (ex.: 941). **[OFICIAL-EX]**
- Caminho no sistema: **Recursos > Implementações > Gerador Importação/Exportação > Definir** (criar o modelo 35 – Lançamentos) e depois executar a importação. **[OFICIAL]**
- Codificação: **[DESCONHECIDO]**. ASCII + CRLF.

### B) Senior X (nuvem)
- O Gestão do Ponto X exporta CSV ou TXT com campos escolhidos pelo usuário: Empresa, Tipo do colaborador (fixo 1), Colaborador, Código de cálculo, Competência, Tabela evento, Evento, Sequência (fixo 1), Origem (`G` gerado / `D` digitado), Referência, Referência parte inteira, Referência parte decimal (minutos), Ano e Mês da competência. Em TXT, cada campo tem um "Tamanho" com zeros à esquerda. Em CSV, o cabeçalho é opcional. **[OFICIAL]**
- O Folha X e o Gestão de DP importam **CSV** com um modelo baixado na própria tela. As colunas **não estão publicadas**. **[DESCONHECIDO]**
- Caminho: Senior X > Gestão de Pessoas | HCM > Folha X > Lançamentos > Importação de lançamentos variáveis. **[OFICIAL]**

---

## 6. TOTVS

### 6a. RM Labore / TOTVS Folha de Pagamento (Linha RM)

#### Fonte oficial
TDN, "Importação de Movimento" (TOTVS Folha de Pagamento > Folha Mensal, atualizado em 29/06/2023): https://tdn.totvs.com/pages/viewpage.action?pageId=452719187

#### Tipo de arquivo
TXT de largura fixa. Na tela, o usuário escolhe o leiaute **"1 – RM LABORE"** ou **"2 – RM FOLHA"**. **[OFICIAL]**

#### Leiaute "RM LABORE" (recomendado) **[OFICIAL]**

| Coluna (início) | Tam | Tipo | Campo |
|---|---|---|---|
| 01 | 16 | String | Chapa do funcionário |
| 17 | 8 | String | Data de pagamento `DDMMAAAA` |
| 25 | 4 | Alfanumérico | Código do evento |
| 29 | 6 | String | Hora `HHH:MM` |
| 35 | 15 | Real | Referência `999999999999.99` |
| 50 | 15 | Real | Valor `999999999999.99` |
| 65 | 15 | Real | Valor original `999999999999.99` |
| 80 | 1 | Caractere | Dados alterados manualmente (`S`/`N`) |
| 81 | 1 | Caractere | Dados de férias (`S`/`N`) |

Total: 81 caracteres.

#### Leiaute "RM FOLHA / RM PONTO" **[OFICIAL]**
A **primeira linha é ignorada**. Da segunda em diante: Chapa (01, 16) · Mês-caixa (17, 9, inteiro) · Código do evento (26, 4) · Valor do evento (30, 15, real) · Número de horas (45, 15, real) · Alterado manualmente S/N (60, 1).

#### Formatos
- **Empregado:** **chapa** (matrícula RM), 16 posições; o fórum usa zeros à esquerda (`0000000000010430`). **[OFICIAL]** (zeros: **[INFERIDO]**). "É imprescindível que a exportação … seja através de chapa." **[OFICIAL]**
- **Empresa (coligada):** **não vai no arquivo**. A importação roda dentro da coligada aberta. **[INFERIDO]**
- **Competência:** pela **data de pagamento** do arquivo (leiaute RM LABORE), a não ser que o usuário informe outra data na tela. **[OFICIAL]**
- **Hora:** `HHH:MM` com 6 caracteres (ex.: `010:30`). **[OFICIAL]** Um exemplo de fórum mostra `01700` (5 caracteres, sem ":"), o que é inconsistente. **[INFERIDO]**: seguir o TDN e testar.
- **Reais:** 15 caracteres com **ponto** decimal, zeros à esquerda (`000000000010.50`). A máscara oficial usa ponto. Um usuário de fórum insiste em "ponto e não vírgula", mas o exemplo dele usa vírgula. **[OFICIAL]** máscara / **[INFERIDO]** prática.
- Para eventos de horas: preencher **Hora** (e talvez a Referência em horas decimais). Qual dos dois o RM usa depende do cadastro do evento. **[INFERIDO]** O mais seguro é preencher ambos de forma coerente.
- **Ações na tela:** Substituir / Ignorar / Somar, quando o evento já existe no envelope. **[OFICIAL]**
- Codificação: **[DESCONHECIDO]**. ASCII + CRLF.

#### Caminho no sistema
**TOTVS Folha de Pagamento (RM Labore): Folha Mensal | Importação | Importação do Movimento** → escolher o arquivo, o leiaute (RM LABORE), a ação (Substituir) e a data de pagamento. **[OFICIAL]** (TDN + Central TOTVS "RH - RM - FOP - Importação do Movimento").

#### Exemplo (a partir do leiaute)
Chapa 10430, pagamento 05/12/2026, evento 0195 (HE 50%), 10h30:
```
0000000000010430051220260195010:30000000000010.50000000000000.00000000000000.00NN
```
Campos: `0000000000010430` | `05122026` | `0195` | `010:30` | `000000000010.50` | `000000000000.00` | `000000000000.00` | `N` | `N` = 81 caracteres (conferido). O gerador deve montar campo a campo e conferir o tamanho da linha.

### 6b. Protheus — SIGAGPE (Gestão de Pessoal)

#### Fontes oficiais
- TDN PSIGAGPE0064, "GPEA210 – Procedimento para importação de variáveis": https://tdn.totvs.com/pages/releaseview.action?pageId=223914413
- TDN GPE01312, "Importação de Variáveis" (P12 usa a tabela RGB): https://tdn.totvs.com/pages/releaseview.action?pageId=235585788
- Central TOTVS "Importação de variáveis tabela RGB" (403 para robôs; conteúdo visto por trecho): https://centraldeatendimento.totvs.com/hc/pt-br/articles/360058289233

#### Como funciona **[OFICIAL]**
- **Não existe leiaute fixo.** O cliente monta o leiaute em **Atualizações > Definições de Cálculo > Layout de Importação (GPEA200, P12)**, ou em "Parâmetros – 02 Importação Variáveis" (GPEA150, P11). Lá ele diz em que colunas do TXT está cada campo da tabela de destino.
- Tabelas de destino no P12: **RGB** (lançamentos mensais), SRK (valores futuros), RHO (coparticipação).
- Importação em **Miscelânea > Importação > Lanctos. Mensais / Importação Variáveis (GPEA210)**. Pede o caminho do arquivo e o leiaute. A "Data de Referência" deve ser a competência a pagar. Tudo é gravado com origem `G`.
- Só TXT (Excel não). **Cada linha termina com ENTER, inclusive a última.** **[OFICIAL via trecho]** da Central TOTVS.
- O exemplo oficial lê `RGB_MAT` nas colunas 01–06 e o valor nas colunas 08–15. A filial pode vir fixa ou por fórmula a partir da matrícula. **[OFICIAL via trecho]**

#### Leiaute proposto pela Kiban (para o contador configurar no GPEA200) **[INFERIDO, proposta nossa]**

| Pos | Tam | Campo RGB | Conteúdo |
|---|---|---|---|
| 01-02 | 2 | RGB_FILIAL | filial (ex.: `01`) |
| 03-08 | 6 | RGB_MAT | matrícula (zeros à esquerda) |
| 09-11 | 3 | RGB_PD | código da verba |
| 12-20 | 9 | RGB_HORAS | quantidade, `999999.99` com ponto |
| 21-32 | 12 | RGB_VALOR | valor, `999999999.99` com ponto |

- Tamanhos-padrão no Protheus: filial com 2 a 8 caracteres conforme o grupo de empresas, matrícula com 6 e verba com 3. **[INFERIDO]** O gerador deve deixar o tamanho da filial configurável.
- **Horas:** o ioPoint tem um leiaute "Protheus – **Centesimal**", o que sugere que o Protheus costuma receber horas centesimais (1h30 = 1.50). **[INFERIDO]**
- Empresa: a importação roda na empresa logada, e a filial vai no arquivo. **[OFICIAL via trecho]** / **[INFERIDO]**
- Competência: escolhida na tela ("Data de Referência" ou período). **[OFICIAL via trecho]**

---

## 7. Participação de mercado (sistemas de folha e contábeis dos escritórios)

- **Pesquisa do Jornal Contábil (maio/2017, 348 contadores)**, a única com números que encontrei: **Domínio 20%**, **Alterdata 11,6%**, Sage 7,6%, **Fortes 6,4%**, Prosoft 5,2% (a Prosoft hoje é da Alterdata, desde a fusão de 2021), Contmatic 5,2%; 14% usam outros. Questor e SCI foram citados sem percentual. https://jornalcontabil.com.br/noticia/pesquisa-nacional-com-empresas-contabeis-do-jornal-contabil/ **[fonte jornalística, dado antigo]**
- O Domínio aparece em todas as fontes como líder (blogs falam em mais de 35 mil clientes e cerca de 40% dos escritórios médios e grandes, sem metodologia). **[INFERIDO]**
- Senior, TOTVS (Protheus/RM) e Metadados são **folha interna de médias e grandes empresas**, não de escritório contábil. O público da Kiban (pequenas empresas de equipe de campo) costuma ter a folha feita **pelo contador**: Domínio, Alterdata/Prosoft, Fortes (forte no Nordeste), Questor (forte no Sul), SCI, Contmatic. **[INFERIDO]**
- Lista de sistemas que um concorrente (ioPoint) já atende, como sinal de demanda: Athenas, CIGAM, Consisa, Contabit, Contmatic Phoenix, Domínio (2 leiautes), Fortes, IoB, Metadados (+ centesimal), Prosoft, Protheus – Centesimal, Questor (3 leiautes), SAGE, SCI Único (4 leiautes), Senior (9 leiautes), Totvs, VetorRh e outros. https://iopedia.iopoint.com.br/books/exportacoes/export/pdf **[terceiro]**
- O Pontomais (VR) diz que integra um leiaute novo em até 60 dias úteis a partir do PDF do leiaute, e que o usuário escolhe horas "sexagesimal ou centesimal" por leiaute. https://materiais.vr.com.br/central-de-ajuda/exportacao-para-folha-de-pagamento/ **[terceiro]**

**Ordem sugerida de implementação:** 1) Domínio · 2) Alterdata · 3) Fortes (CSV) · 4) Questor · 5) SCI/Contmatic (não pesquisados aqui) · depois RM Labore, Senior e Protheus (clientes maiores), além de um **leiaute genérico configurável**.

---

## 8. Recomendações para o gerador (comuns a todos)

1. **Configuração por empresa cliente** (tela "Exportar para a folha"):
   - sistema de folha (preset);
   - **código da empresa na folha** (Domínio 10 dígitos, Alterdata 5, Senior NUMEMP 4, Fortes 4…);
   - **código de cada evento** (rubrica/verba/evento) para cada tipo de evento do Ponto: HE 50%, HE 100%, adicional noturno (horas), faltas em dias, faltas em horas, atrasos, DSR descontado, banco de horas (crédito e débito, se for pago ou descontado);
   - **formato das horas:** centesimal (1,50) / sexagesimal (1,30) / minutos (90);
   - campos extras quando o sistema precisar (Senior: TABEVE e CODCAL; Domínio: tipo do processo, padrão 11; RM: data de pagamento; Protheus: filial).
2. **Por funcionário:** o "código do funcionário na folha" (matrícula do contador). O Domínio, o Questor, o Fortes, o Senior (NUMCAD), o RM (chapa) e o Protheus (matrícula) exigem o código interno; só o Alterdata aceita **PIS + CNPJ** no lugar. O CPF não é usado por nenhum desses leiautes. Guardar também o PIS/NIS.
3. **Conversão de horas** (o Ponto guarda minutos inteiros):
   - centesimal: `round(min / 60, 2)`, então 90 → 1,50;
   - sexagesimal ("HH,MM"): `intdiv(min, 60) + (min % 60) / 100`, então 90 → 1,30;
   - minutos: 90.
   - Depois aplicar as casas implícitas do leiaute (×100 e zeros à esquerda).
4. **Arredondamento:** fazer em inteiros (centésimos), nunca com float.
5. **Arquivo:** ASCII/Windows-1252, **CRLF**, com CRLF também na última linha (exigência do Protheus; não atrapalha os outros). Sem BOM.
6. **Validação antes de baixar:** conferir o tamanho de cada linha nos leiautes fixos, que não haja código de rubrica vazio, e funcionários sem código na folha (listar para o usuário).
7. **Eventos com quantidade zero:** não gerar a linha (os leiautes não exigem).
8. **Teste real:** antes de dizer "compatível com X", validar com um contador que use o sistema X. Hoje só Domínio e RM Labore têm leiaute e exemplo oficiais suficientes para confiar sem teste.

## 9. Perguntas em aberto (para um contador de cada sistema)
- **Domínio:** o escritório usa "Horas decimais" ou "Horas Minutos"? Quais códigos de rubrica (HE 50%, HE 100%, noturno, faltas, atrasos, DSR)? Como mandar as faltas em dias: registro 10 com rubrica de faltas + registro 11 com as datas?
- **Alterdata:** quais letras vão no campo "Processo" (pos. 61)? As horas vão em minutos no "Valor do evento", e com ou sem as 2 casas?
- **Questor:** baixar o modelo da tela "Importação Planilha" e as letras informativas.
- **Fortes:** baixar o "Eventos_da_Folha_TC" e confirmar o cabeçalho, o separador e a unidade das horas na referência.
- **Senior:** o CODCAL do mês e a TABEVE; o modelo 35 já existe no cliente?
- **RM:** `HHH:MM` com dois-pontos? Ponto ou vírgula nos reais?
- **Protheus:** o leiaute GPEA200 do cliente, ou a configuração da nossa proposta.
