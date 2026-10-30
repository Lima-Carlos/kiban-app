# Kiban Campo (em inglês, Kiban Field)

Ordens de serviço para empresas com equipe na rua: provedores de internet, ar-condicionado, segurança eletrônica, manutenção e assistência técnica. O código do produto no Core é `campo`, e o limite do plano é o número de **técnicos ativos**.

A ordem das partes (aprovada em 30/10/2026):

| Parte | O quê | Situação |
|---|---|---|
| O1 | Clientes, endereços com ponto no mapa, planilha | ✔ pronta (30/10/2026) |
| O2 | Tipos de serviço, roteiro do técnico, materiais e modelos prontos por ramo | ✔ pronta (30/10/2026) |
| O3 | Ordens de serviço, agenda do dia por técnico e mapa | ✔ pronta (30/10/2026, antes da O2b) |
| O2b | Orçamentos aprovados pelo cliente por link (viram OS) | ✔ pronta (30/10/2026) |
| O4 | Execução pelo celular, também **sem internet**: check-in com local, checklist, fotos, material, assinatura e km do veículo | ✔ pronta (30/10/2026) |
| O5 | Fechamento: relatório em PDF por link e WhatsApp, com a marca da empresa | ✔ pronta (30/10/2026) |
| O6 | Painel do gestor: técnicos agora, resultado do mês, avisos em "Atenção", técnico mais perto | ✔ pronta (30/10/2026) |
| Depois | O7 ligação completa com Ponto e Frota, PMOC, estoque no carro | |

## Quem faz o quê (função no Campo)
Ela é escolhida na tela **Pessoas** (ou por um perfil de acesso):
- **Gestor:** tudo. Dono e administradores da empresa são sempre gestores.
- **Atendimento:** cadastra clientes e, a partir da O3, abre e agenda as OS.
- **Técnico:** faz as OS pelo celular (a partir da O4). Conta no limite do plano.
- **Consulta:** só vê.

Novos perfis sugeridos: **"Técnico de campo"** (técnico no Campo e funcionário no Ponto) e **"Atendimento"**. O perfil "Consulta" agora também vê o Campo.

## O1: Clientes (Campo → Clientes)
- O cliente é uma **empresa** (razão social e nome fantasia) ou uma **pessoa**.
- **Documento:**
  - no Brasil, CPF ou CNPJ, conferido e sem repetir na empresa (opcional);
  - nos outros países, qualquer documento, com o tipo (RUT, RFC, DNI...).
- **Código no seu sistema:** o código do cliente no sistema que a empresa já usa (por exemplo, o sistema do provedor). A planilha atualiza o cliente por ele, sem duplicar.
- **Celular/WhatsApp** com o código do país, como nas Pessoas. Na tela, ele aparece formatado, por exemplo "+55 (31) 98888-1234", e tocar nele abre o WhatsApp.
- **Vários endereços de atendimento** por cliente (matriz, filial, casa...):
  - sempre um "principal";
  - ponto de referência ("portão azul") e com quem falar;
  - um endereço deixado em branco não é salvo.
- **CEP (Brasil):** a lupa ao lado do CEP preenche rua, bairro, cidade e estado. Usa o serviço gratuito ViaCEP.
- **Ponto no mapa de cada endereço:** de onde ele veio aparece na tela.
  - "Marcado": digitado, ou "Usar minha localização" no local;
  - "Da planilha": latitude e longitude vieram na importação;
  - "Aproximado": achado pela busca no mapa;
  - "Procurando...": esperando a busca;
  - "Não encontrado": o mapa não achou o endereço. A tela explica o que fazer;
  - "Confirmado na visita": vem na O4, quando o técnico confirma no local.
- **Busca no mapa (sem custo):**
  - um endereço sem ponto é procurado sozinho no OpenStreetMap a cada minuto, no máximo 1 busca por segundo (regra do serviço gratuito);
  - a busca tenta, nesta ordem: rua com número, só a rua e, por fim, o bairro;
  - um ponto marcado por uma pessoa nunca é trocado pela busca;
  - se o endereço mudar, a busca é feita de novo.
- **Lista:**
  - procura por nome, documento, código, celular e e-mail;
  - filtros: ativos, tipo, cidade e "sem ponto / não encontrado".
  - No celular, cada cliente vira um cartão.
- **Histórico:** as OS do cliente aparecem ali a partir da O3.
- **Excluir ou desligar:** em vez de excluir, o cliente pode ser desligado (o histórico fica). A partir da O3, um cliente com OS só poderá ser desligado.

### Planilha (botão "Planilha")
- **"Baixar o modelo"** gera um Excel com as colunas no idioma da pessoa e uma linha de exemplo.
- **"Importar planilha"** aceita Excel (.xlsx) ou .csv:
  - o .csv pode usar ";" (padrão do Excel no Brasil) ou ",", e textos antigos (sem UTF-8) também são lidos;
  - a primeira linha tem os nomes das colunas, em qualquer dos 3 idiomas ou com nomes comuns ("CPF", "Logradouro", "UF", "lat"...), em qualquer ordem;
  - só o **nome** é obrigatório. Colunas desconhecidas são ignoradas.
- Um cliente que já existe (mesmo **código** ou mesmo **documento**) é **atualizado**, sem duplicar:
  - células vazias não apagam nada;
  - o mesmo endereço (CEP ou rua, mais o número) não é cadastrado de novo.
- Uma linha **sem código e sem documento** sempre cria um cliente novo, porque não há como saber com segurança que é o mesmo.
- Uma linha com erro (por exemplo, CPF inválido) fica de fora; as outras entram. O aviso diz quantos clientes foram criados e atualizados e quais linhas ficaram de fora, com o motivo.
- Limite: 5.000 linhas por arquivo.

## Arquivos (parte O1)
- `app/Modules/Field/FieldModule.php`: o módulo (passos, telas, funções, campos na tela Pessoas).
- `app/Modules/Field/Enums/FieldRole.php`: as funções (gestor, atendimento, técnico, consulta).
- `app/Modules/Field/Models/Customer.php` e `Site.php`: o cliente e os endereços (de onde veio o ponto no mapa e a busca).
- `app/Modules/Field/Services/CustomerRules.php`: as regras do cliente (documento, código, telefones), as mesmas no formulário, na planilha e, depois, na loja/API.
- `app/Modules/Field/Services/AddressLocator.php`: o CEP (ViaCEP) e a busca no mapa (OpenStreetMap).
- `app/Modules/Field/Services/CustomerImporter.php`: a planilha (importar e o modelo).
- `app/Modules/Field/Services/FieldAccess.php` e `FieldPeople.php`: quem é quem no Campo e a parte do Campo na tela Pessoas.
- `app/Modules/Field/Policies/`: quem vê e quem altera.
- `app/Modules/Field/Filament/Customers/`: as telas (lista com planilha, cadastro, edição e ficha do cliente).
- `app/Console/Commands/RunFieldTasks.php`: `kiban:field`, a busca no mapa a cada minuto (no agendador).
- `database/migrations/2026_10_30_200000_create_field_customer_tables.php`: as tabelas `fd_customers` e `fd_sites`.
- `lang/*/field.php`: os textos nos 3 idiomas.
- `tests/Feature/FieldCustomersTest.php`: os testes (CPF/CNPJ, CEP, busca no mapa, planilha, quem vê, separação entre empresas).
- Configuração (`config/kiban.php` → `field`): `KIBAN_FIELD_LOCATOR` ("openstreetmap" ou "off"), `KIBAN_FIELD_LOCATOR_CONTACT` (e-mail que o OpenStreetMap pede) e a pausa entre buscas.
- Peça compartilhada nova: `App\Support\Country::formatPhone()` mostra qualquer celular guardado como as pessoas leem.

## O2: Configurações, tipos de serviço e materiais

### Configurações do Campo (só o gestor)
- **O seu ramo:** Provedor de internet, Ar-condicionado e refrigeração, Segurança eletrônica, Manutenção e assistência técnica, ou "Outro ramo (começar do zero)". Ao salvar, o sistema **cria os tipos de serviço mais comuns do ramo**, cada um com o roteiro do técnico, **e os materiais**.
  - O modelo é criado **no idioma de quem escolhe** e passa a ser da empresa, que pode renomear, mudar ou desligar qualquer coisa.
  - Escolher outro ramo depois **só acrescenta o que falta**, sem repetir nomes.
- **Moeda dos preços** (vem do país da empresa) e **"Cliente assina ao final"**, o padrão para os novos tipos.
- É o primeiro passo do assistente: sem ele, ainda não dá para criar tipos de serviço (a tela explica).

### Tipos de serviço
- Cada tipo tem:
  - nome e cor (para a agenda da O3);
  - descrição;
  - **tempo previsto** (a agenda usa para não marcar serviços em cima um do outro);
  - **preço** (opcional, sugerido nos orçamentos);
  - se o cliente assina e se a foto é obrigatória ao final.
- **Roteiro do técnico** (checklist): as perguntas que o técnico responde no celular, em ordem (arrastar muda a ordem). Cada pergunta pode ser:
  - marcar como feito;
  - sim ou não;
  - número com unidade (dBm, Mbps, °C...);
  - texto;
  - uma opção de uma lista;
  - foto.

  Cada pergunta pode ser obrigatória.
- Cada pergunta ganha um **código fixo**, assim as respostas continuam ligadas à pergunta certa mesmo se ela for renomeada.
  - A partir da O3, a ordem de serviço copia o roteiro na abertura: mudar o tipo não muda as ordens já abertas.
- **Duplicar** cria uma cópia para ajustar (ex.: "Instalação empresarial").
- Na lista, o gestor arrasta para mudar a ordem. O atendimento e a consulta só veem; o técnico não vê esta tela.

### Materiais
- Nome, código no estoque da empresa, unidade (unidade, metro, quilo, litro, hora, caixa, peça) e preço por unidade.
- O técnico anota o que usou em cada OS (O4), e os orçamentos (O2b) pegam os preços daqui.

### O que vem em cada modelo
- **Provedor:**
  - Instalação: potência do sinal em dBm, serial da ONU, foto da CTO, teste de velocidade, Wi-Fi explicado, local limpo;
  - Sem sinal / lentidão: com a causa numa lista;
  - Mudança de endereço;
  - Retirada de equipamento;
  - Visita técnica;
  - materiais: cabo drop, conector, ONU, roteador, esticador, cordão óptico.
- **Ar-condicionado:**
  - Instalação de split: BTU, tubulação, vácuo, pressão, temperatura, dreno, fotos;
  - Limpeza e higienização;
  - Manutenção corretiva;
  - Carga de gás: tipo de gás e quantidade;
  - materiais: tubo de cobre, gases, suporte, dreno, isolamento.
- **Segurança eletrônica:**
  - Instalação de câmeras: aplicativo configurado, fotos;
  - Instalação de alarme: zonas, teste;
  - Manutenção;
  - Visita técnica;
  - materiais: câmera, gravador, HD, cabo de rede, fonte, sensor, sirene.
- **Manutenção e assistência técnica:** Visita técnica, Manutenção preventiva, Manutenção corretiva, Instalação; materiais genéricos.

## Arquivos (parte O2)
- `app/Modules/Field/Models/FieldSetting.php`, `ServiceType.php` (com a limpeza do roteiro: só perguntas válidas, código fixo e único) e `Material.php`.
- `app/Modules/Field/Services/FieldTemplates.php`: os modelos prontos por ramo (perguntas, opções, tempos e materiais; os textos ficam em `lang/*/field.php` → `templates`).
- `app/Modules/Field/Services/FieldFormat.php`: dinheiro na moeda da empresa e tempos ("1 h 30 min").
- `app/Modules/Field/Filament/FieldSettingsPage.php`, `ServiceTypes/`, `Materials/` e `FieldFields.php` (preço digitado em reais e guardado em centavos).
- `app/Modules/Field/Policies/ServiceTypePolicy.php` e `MaterialPolicy.php`.
- `database/migrations/2026_10_30_210000_create_field_setup_tables.php`: `fd_settings`, `fd_service_types`, `fd_materials`.
- `tests/Feature/FieldSetupTest.php`.
- Peça compartilhada nova: `App\Support\Country::currency()` (moeda de cada país).

## O3: Ordens de serviço e agenda (feita antes da O2b)
A O3 veio antes dos orçamentos porque o orçamento aprovado vira uma ordem de serviço.

### Ordens de serviço (Campo → Ordens de serviço)
- **Abrir:**
  - escolha o cliente (procura por nome, documento, código ou celular, em qualquer formato);
  - o endereço (o principal já vem marcado);
  - o tipo de serviço, a prioridade (normal, alta, urgente), o que o cliente pediu e as anotações internas (o cliente nunca vê).
  - Na ficha do cliente, o botão **"Abrir ordem de serviço"** já traz o cliente escolhido.
- **Número próprio** em cada empresa, em sequência (OS 000001, 000002...), sem repetir nem pular, mesmo com duas aberturas no mesmo segundo.
- Ao abrir, a OS **copia o roteiro do técnico**, o tempo e o preço do tipo de serviço. Mudar o tipo depois não mexe nas OS já abertas.
- **Agendar:** técnico, dia e hora e tempo previsto. Pode ser na abertura ou depois.
  - Se o técnico já tiver outro serviço no horário, aparece um **aviso** com o serviço que bate, mas não é proibido (às vezes cabe um serviço rápido).
  - Só pessoas com a função "Técnico" no Campo recebem OS.
- **Reagendar**, **Tirar da agenda** (volta para "Sem agenda"), **Cancelar** (o motivo é obrigatório) e **Anotação**.
  - Uma OS concluída ou cancelada **não muda mais**.
  - Uma OS **nunca é excluída**.
- **Editar** muda só o endereço, a prioridade, o que foi pedido e as anotações. O cliente e o tipo ficam.
- **Histórico** de cada OS, com quem fez e quando: aberta, agendada para fulano em tal hora, reagendada (com a hora anterior), tirada da agenda, cancelada (com o motivo), anotações e edições.
- **Situações:**
  - Sem agenda, Agendada e Cancelada (desta parte);
  - Em execução, Pendente e Concluída (pelo técnico, na O4).
- A lista mostra as OS em aberto (filtros por situação, técnico, tipo e "só de hoje"). O número ao lado do menu conta as OS sem agenda.
- **Na ficha do cliente**, o "Histórico" agora lista as OS dele.
  - Um cliente com OS não pode ser excluído, só desligado.

### Agenda (botão "Agenda" na lista de OS)
- **Um dia por vez**, com setas para o dia anterior e o próximo, e o botão "Hoje".
- **Uma coluna por técnico**, com as OS do dia em ordem de horário: hora de início e fim, cliente, tipo, número e bairro. A borda tem a cor do tipo de serviço.
- **"Para agendar":** as OS sem agenda, as urgentes primeiro.
- **Mapa do dia:** um ponto por OS com endereço no mapa, na cor do técnico. Tocar no ponto abre a OS.
  - Usa o mapa gratuito do OpenStreetMap (Leaflet). Para muito uso, dá para trocar por um serviço próprio de mapas no futuro.
- No celular, as colunas ficam uma embaixo da outra.

### Limite do plano: técnicos ativos
Na tela Pessoas, dar a função "Técnico" a mais gente do que o plano permite é recusado com uma explicação. As outras funções não contam.

### Quem faz o quê
- Gestor e atendimento: abrem, agendam e cancelam.
- Consulta: só vê, inclusive a agenda.
- Técnico: não vê estas telas. Ele terá a tela dele no celular na O4.

## Arquivos (parte O3)
- `app/Modules/Field/Models/WorkOrder.php` (a OS) e `OrderEvent.php` (uma linha do histórico, que nunca muda).
- `app/Modules/Field/Enums/OrderStatus.php` e `OrderPriority.php`.
- `app/Modules/Field/Services/WorkOrderService.php`: abrir (número em sequência, cópia do roteiro), agendar, reagendar, tirar da agenda, cancelar, anotação e conferir conflitos. Toda mudança de OS passa por aqui.
- `app/Modules/Field/Services/FieldException.php`: as recusas com a explicação.
- `app/Modules/Field/Policies/WorkOrderPolicy.php` (OS nunca é excluída) e `CustomerPolicy.php` (cliente com OS não é excluído).
- `app/Modules/Field/Filament/Orders/`: as telas (lista, abrir, editar, ficha com as ações e `Pages/Agenda.php`).
- `resources/views/field/agenda.blade.php`: a agenda do dia e o mapa.
- `database/migrations/2026_10_30_220000_create_field_order_tables.php`: `fd_orders`, `fd_order_events` e o último número usado em `fd_settings`.
- `tests/Feature/FieldOrdersTest.php`.

## O2b: Orçamentos (Campo → Orçamentos)
- **Escrever:**
  - escolha o cliente (e o endereço) e a validade (padrão: 15 dias);
  - adicione os itens: "Do catálogo" escolhe um tipo de serviço ou um material e preenche a descrição, a unidade e o preço, que ainda podem ser mudados. Também dá para escrever uma linha à mão;
  - quantidade com casas decimais (ex.: 35,5 m de cabo);
  - o desconto nunca passa do subtotal;
  - observações para o cliente (prazo, pagamento, garantia) e anotações internas.
- Número próprio em sequência ("Orçamento 000001"), separado do das OS.
- Na ficha do cliente, o botão **"Novo orçamento"** já traz o cliente.
- **"Enviar ao cliente"** prepara o link e mostra o botão **"Enviar pelo WhatsApp"**, que abre o WhatsApp com a mensagem pronta (nome do cliente, empresa, número, total e link), sem integração paga.
  - Há também "Ver como o cliente vê".
- **A página do cliente** (sem login, link longo e aleatório, no endereço da empresa, nos 3 idiomas): itens, total, observações e validade.
  - **Aprovar:** ele escreve o nome e marca "Li e aprovo este orçamento de R$ X". Fica guardado quem, quando, o IP e o aparelho, como prova do combinado.
  - **Recusar:** com o motivo, opcional.
- **O aprovado vira OS sozinho**, do primeiro serviço do orçamento. A OS vem "Sem agenda", com os itens no "O que o cliente pediu" e ligada ao orçamento.
  - Se o orçamento só tiver materiais, o botão "Abrir ordem de serviço" pede o tipo.
- **Aviso no sino** do gestor e do atendimento quando o cliente aprova ou recusa.
- **Vencido:** depois da validade, a página mostra "venceu, fale com a empresa" e não aceita mais a aprovação.
- **Proteções:**
  - um orçamento respondido não muda e não é excluído;
  - um rascunho não abre pelo link;
  - o link de uma empresa não abre no endereço de outra.

## Arquivos (parte O2b)
- `app/Modules/Field/Models/Quote.php` e `QuoteItem.php` (o total da linha é sempre quantidade × preço).
- `app/Modules/Field/Enums/QuoteStatus.php`.
- `app/Modules/Field/Services/QuoteService.php`: criar, alterar, enviar, link e WhatsApp, aprovar, recusar, virar OS e o aviso no sino.
- `app/Modules/Field/Policies/QuotePolicy.php`.
- `app/Modules/Field/Filament/Quotes/`: as telas (lista, novo, editar, ficha com Enviar / WhatsApp / Ver como o cliente vê / Abrir OS).
- `app/Http/Controllers/Field/QuoteLinkController.php` e `resources/views/field/quote-public.blade.php`: a página do cliente (`/<empresa>/orcamento/<link>`, no máximo 10 respostas por minuto).
- `database/migrations/2026_10_30_230000_create_field_quote_tables.php`: `fd_quotes`, `fd_quote_items`, `quote_id` na OS, numeração e validade padrão em `fd_settings`.
- `app/Modules/Field/Services/FieldFormat.php`: agora também a quantidade ("35 m", "2,5 kg").
- `tests/Feature/FieldQuotesTest.php`.

## O4: O técnico no celular, também sem internet
### Minhas OS (Campo → Minhas OS, só para técnicos)
- **Hoje**, em ordem de horário, **Pendentes** e **Próximos dias**.
- Cada OS mostra: cliente, tipo, endereço, **"Como chegar"** (abre o mapa do celular), **WhatsApp** do cliente e **"Começar" / "Continuar"**.
- No **Início**, o técnico vê "Meus serviços de hoje" com o botão para a lista.
- **Abrir "Minhas OS" com sinal guarda a lista e cada OS no celular.** Depois, sem sinal, o app abre direto nelas, como o "Bater ponto".

### Na casa do cliente (a tela da OS)
1. **Chegada:** o toque em "Cheguei" guarda a hora, o local (e a distância até o ponto do cliente) e, se o técnico tiver veículo na Frota, o **km do veículo**, que **vai também para a Frota** (origem "Ordem de serviço (Campo)").
   - Um cliente sem ponto no mapa **ganha o ponto do técnico** ("Confirmado na visita").
   - Um km que a Frota recusa (ex.: menor que o último) fica na OS, com o motivo no histórico.
2. **Roteiro:** cada pergunta do tipo de serviço (marcar como feito, sim/não, número com unidade, texto, lista, foto). O asterisco (*) mostra as obrigatórias. Há também "Outras fotos do serviço".
   - As fotos são reduzidas no próprio celular antes de enviar.
3. **Materiais usados** (do catálogo, com a quantidade; dá para tirar) e **"O que foi feito"**.
4. **Assinatura do cliente:** o nome e a assinatura com o dedo, na tela.
5. **"Concluir serviço"** confere, ainda no celular, o que falta (perguntas obrigatórias, foto obrigatória, assinatura) e diz o quê.
   - **"Deixar pendente"** pede o motivo (ex.: falta peça). O escritório vê o motivo, e **"Retomar"** continua depois.
- **Sem internet:**
  - tudo vai primeiro para a **fila do celular** e é enviado sozinho, na ordem, quando o sinal volta;
  - a tela mostra "⏳ N esperando sinal" ou "✓ Tudo enviado";
  - cada informação vale pela **hora em que foi feita**, até 7 dias. Uma resposta antiga que chega depois não apaga uma mais nova;
  - a mesma informação enviada duas vezes conta uma vez só.
- **Testado num Chrome de verdade, com a internet cortada:** chegada, roteiro, foto, material, assinatura e concluir, tudo sem sinal. Ao voltar a internet, as 9 informações foram enviadas, e a OS ficou "Concluída" no escritório.

### No escritório
- A ficha da OS ganhou **"O que o técnico fez"**:
  - chegada (hora, distância do cliente e km, com link do mapa);
  - conclusão ou o motivo da pendência;
  - cada pergunta com a resposta ("Potência do sinal na ONU: -19.5 dBm");
  - os materiais, "o que foi feito", as fotos e a assinatura.
- O histórico mostra a chegada, as pendências e a conclusão.

## Arquivos (parte O4)
- `app/Modules/Field/Services/OrderExecutionService.php`: aplica cada informação vinda do celular (chegada, resposta, foto, material, nota, assinatura, pendência, conclusão), uma vez só, e diz o que falta para concluir.
- `app/Http/Controllers/Field/OrderSyncController.php`: recebe as informações (`POST /<empresa>/campo/os/<número>/enviar`).
- `app/Modules/Field/Models/OrderAnswer.php`, `OrderPhoto.php`, `OrderMaterial.php` e `Services/FieldPhotoStore.php` (fotos e assinaturas na pasta privada da empresa).
- `app/Modules/Field/Filament/MyOrdersPage.php` e `ExecuteOrderPage.php`, com as telas `resources/views/field/my-orders.blade.php`, `execute.blade.php` e `home/technician.blade.php`.
- `public/js/field-queue.js`: a fila do celular do técnico. `public/sw.js` agora guarda também "Minhas OS" e cada OS.
- `database/migrations/2026_10_31_100000_create_field_execution_tables.php`.
- Frota: nova origem do km, `OdometerSource::WorkOrder`.
- `tests/Feature/FieldExecutionTest.php`. Teste num Chrome de verdade, sem internet: `docker compose run --rm studio node scripts/testar-campo-offline.mjs`, no projeto `kiban-videos`.

## O5: Relatório da OS, com a cara da empresa
**Com a O5, a primeira versão vendável do Kiban Campo (O1 a O5, com O2b) está completa.**

### Identidade da empresa (Empresa → Identidade da empresa)
- Tela nova, comum a todos os sistemas, só para o dono e os administradores.
- Guarda: logo (PNG ou JPG), cor principal, nome da empresa, CNPJ ou documento, telefone, e-mail, site, endereço e o **rodapé dos relatórios** (ex.: "Garantia de 90 dias").
- Sem nada preenchido, os relatórios usam o nome da empresa e o azul da Kiban.

### O relatório
- Só existe para uma OS **concluída**.
- **Topo na cor da empresa**, com o logo. Depois:
  - cliente, endereço, técnico e quando (chegada e conclusão);
  - o que o cliente pediu e o que foi feito;
  - o roteiro com as respostas;
  - os materiais e as fotos (até 12; as demais ficam no sistema);
  - a assinatura do cliente, com o nome e a hora;
  - o rodapé da empresa e um **código de verificação** (feito com a chave secreta do sistema: uma cópia alterada não confere).
- **Página do cliente:** link assinado (sem login; um link alterado não abre), no endereço da empresa, com o botão **"Baixar em PDF"** (A4).
- **Enviar:**
  - na ficha da OS, os botões "Enviar relatório pelo WhatsApp" (mensagem pronta com o link), "Baixar PDF" e "Ver relatório";
  - na tela do técnico, ao concluir, aparece "Enviar relatório pelo WhatsApp". Mesmo sem sinal, o link já sai pronto e funciona assim que o celular enviar tudo.

## Arquivos (parte O5)
- `app/Models/CompanyProfile.php`, `app/Filament/Pages/CompanyProfilePage.php` e `database/migrations/2026_10_31_110000_create_company_profiles_table.php`: a identidade da empresa (compartilhada).
- `app/Modules/Field/Services/OrderReport.php`: os dados, o link assinado, o PDF, o WhatsApp e o código de verificação.
- `app/Http/Controllers/Field/OrderReportController.php`: `/<empresa>/os/<id>/relatorio` e `/pdf`.
- `resources/views/field/report.blade.php` (página) e `report-pdf.blade.php` (PDF).
- `tests/Feature/FieldReportTest.php`.

## Dia do técnico (30/10/2026)
Feito para o técnico não se atrasar e para o escritório saber na hora quando algo sai do plano. Não usa mapa pago: o tempo de deslocamento é uma **estimativa** pela distância (linha reta × fator das ruas ÷ velocidade média), arredondada para cima de 5 em 5 minutos.

### Na tela do técnico (a OS no celular)
- **Antes de chegar:**
  - **"Estou a caminho"**: registra a saída e prepara a mensagem para o cliente no WhatsApp ("Estou a caminho, devo chegar por volta das 10:40"). Por enquanto a mensagem sai do WhatsApp do técnico; pelo WhatsApp oficial da empresa só depois do CNPJ (janeiro de 2027).
  - **"Vou atrasar"**: 15, 30 ou 60 minutos, com motivo opcional. O escritório recebe o aviso no sino na hora.
  - **"Passar para outro técnico"**: com o motivo. A OS volta para "Em aberto", sem técnico, e o escritório é avisado. Na Agenda, ela aparece em "Para agendar" com o motivo.
- **No serviço:** o cartão **"Próximo serviço"** mostra a hora e o cliente do próximo, o tempo de deslocamento e **"Termine aqui até 13:55"**. Na hora de sair, o cartão fica amarelo e o celular vibra. Também há "Vou atrasar para o próximo".
- **Avisos no celular:** o botão "Ativar avisos no celular" faz o aviso da hora de sair chegar **mesmo com o app fechado** (notificação do próprio navegador, de graça, sem conta em serviço externo).
- **Fotos com carimbo:** toda foto sai com data, hora, local (latitude e longitude) e o número da OS escritos na própria imagem.

### Avisos automáticos (a cada minuto, comando `kiban:field`)
Cada um sai só uma vez:
- para o técnico no serviço: o **alerta para sair** (padrão: 15 minutos antes da hora de sair);
- para o escritório (gestor e atendimento):
  - **risco de atraso**, quando o técnico ainda está no serviço depois da hora de sair;
  - **técnico não chegou**, 15 minutos depois do horário (ou depois do atraso avisado).

### Na Agenda
- **"Sugerir ordem"** em cada técnico com 2 ou mais serviços no dia: monta o caminho mais curto (sempre o mais perto em seguida, saindo da base da empresa) com os horários um depois do outro. Mostra a lista antes; só muda a agenda ao clicar em "Aplicar na agenda".

### Nota do cliente e retorno
- **Nota do cliente:** na página do relatório, o cliente dá de 1 a 5 estrelas e um comentário (uma vez só). Nota 3 ou menor avisa o escritório. A nota aparece na ficha da OS e na lista.
- **Retorno:** uma OS nova do mesmo cliente e endereço, até 30 dias depois de um serviço feito, é marcada como **retorno** (com o link da OS anterior). Ajuda a ver serviço mal feito.

### Configurações do Campo → "Dia do técnico" (só o gestor)
Velocidade média na cidade (25 km/h), fator das ruas (1,4), minutos do alerta para sair (15), dias para contar como retorno (30) e a base da empresa (latitude e longitude de onde os técnicos saem).

## Arquivos (dia do técnico)
- `database/migrations/2026_10_31_120000_create_field_day_tables.php`: os campos novos das configurações e da OS (atraso, a caminho, devolvida, retorno, nota) e a tabela `push_subscriptions` (os celulares que recebem avisos).
- `app/Modules/Field/Services/DayPlanner.php`: tempo de deslocamento, próximo serviço, "termine até", hora do alerta e a ordem sugerida do dia.
- `app/Modules/Field/Services/DayWatch.php`: os avisos automáticos do dia (cada um uma vez).
- `app/Modules/Field/Services/FieldNotices.php`: manda um aviso ao escritório (gestor e atendimento) com o link da OS.
- `app/Modules/Field/Services/OrderExecutionService.php`: as peças novas `on_the_way`, `delay` e `handoff` (também sem internet).
- `app/Modules/Field/Services/WorkOrderService.php`: `returnOf()` (retorno) e `rate()` (nota do cliente).
- `app/Support/PhoneAlerts.php`, `app/Models/PushSubscription.php`, `app/Http/Controllers/PhoneAlertController.php` (`POST /<empresa>/avisos/celular`) e `app/Console/Commands/MakePushKeys.php` (`kiban:push-keys`): os avisos no celular. As duas chaves ficam no `.env` (`KIBAN_PUSH_PUBLIC_KEY` e `KIBAN_PUSH_PRIVATE_KEY`), nunca no Git; na VPS, gerar uma vez com `php artisan kiban:push-keys` e manter. Sem elas, os avisos no celular ficam desligados (o resto funciona).
- `public/sw.js`: mostra o aviso e abre a tela certa ao tocar.
- `public/js/field-queue.js`: o carimbo nas fotos.
- `resources/views/field/execute.blade.php`: os botões e o cartão do próximo serviço.
- `resources/views/field/agenda-suggest.blade.php`: a lista da ordem sugerida.
- `tests/Feature/FieldDayTest.php`.
- Conferido no Chrome do estúdio: `C:\Projetos\kiban-videos\scripts\testar-campo-dia.mjs`.

## O6: Painel do gestor (30/10/2026)
Para o gestor, o atendimento e quem só acompanha. O técnico não vê.

### Campo → Painel (e o Início)
- **Hoje, agora:** cada técnico com a situação na hora: **Atrasado** (não chegou 15 min depois do horário, contando o atraso avisado), **Em atendimento** (desde que horas), **A caminho**, **Livre** (com o próximo serviço) ou **Sem mais serviços**, e "2 de 4 feitos". Atualiza sozinho a cada minuto. Embaixo, quantas OS estão sem técnico, com o link da Agenda.
- **Resultado do mês** (escolha o mês no topo): OS feitas e o valor, tempo médio no cliente (da chegada ao fim), pontualidade (% que chegou até 15 min depois do horário), retornos, nota média dos clientes e orçamentos aprovados / enviados.
- **Por técnico:** a mesma conta para cada técnico. Pontualidade abaixo de 80%, retornos e nota 3 ou menor ficam destacados. O nome abre as OS feitas por ele.

### Quadro "Atenção" do Início (gestor e atendimento)
Some sozinho quando resolvido: técnico que ainda não chegou, OS devolvidas por técnicos esperando outro técnico e OS urgentes ou de prioridade alta sem técnico.

### Técnico mais perto
Ao **Agendar** uma OS (na ficha dela), a lista de técnicos vem com os **mais perto primeiro e os livres antes dos ocupados** ("Ana Souza · livre · a uns 10 min"). A posição é o último lugar onde o técnico chegou hoje ou, se ainda não saiu, a base da empresa (Configurações do Campo). Ocupado = em atendimento agora ou com outro serviço no mesmo horário. Serve principalmente para a OS que um técnico devolveu.

### Avisar o cliente do atraso
Depois de "Vou atrasar", o técnico tem o botão **"Avisar o cliente do atraso pelo WhatsApp"**, com a mensagem pronta e a nova hora ("vou atrasar uns 15 min. Devo chegar por volta das 10:45"). Vale também para o atraso do próximo serviço.

## Arquivos (O6)
- `app/Modules/Field/Services/FieldReports.php`: as contas do painel (hoje, mês, por técnico), o quadro "Atenção" e os técnicos mais perto.
- `app/Modules/Field/Filament/Dashboard/FieldDashboard.php`: a página Campo → Painel (`/campo/painel`).
- `app/Modules/Field/Filament/Dashboard/FieldNowWidget.php`, `FieldMonthWidget.php`, `FieldTechniciansWidget.php` e `ReadsFieldFilters.php`: os blocos "Hoje, agora", "Resultado do mês" e "Por técnico" (os dois primeiros também no Início).
- `resources/views/field/dashboard/now.blade.php` e `technicians.blade.php`: as telas desses blocos.
- `tests/Feature/FieldDashboardTest.php`.
- Conferido no Chrome do estúdio: `C:\Projetos\kiban-videos\scripts\testar-campo-painel.mjs`.
