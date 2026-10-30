# Kiban Campo (em inglês, Kiban Field)

Ordens de serviço para empresas com equipe na rua: provedores de internet, ar-condicionado, segurança eletrônica, manutenção e assistência técnica. O código do produto no Core é `campo`, e o limite do plano é o número de **técnicos ativos**.

A ordem das partes (aprovada em 30/10/2026):

| Parte | O quê | Situação |
|---|---|---|
| O1 | Clientes, endereços com ponto no mapa, planilha | ✔ pronta (30/10/2026) |
| O2 | Tipos de serviço, checklists e modelos prontos por ramo | próxima |
| O2b | Orçamentos aprovados pelo cliente por link (viram OS) | |
| O3 | Abertura e distribuição das OS: agenda e mapa do dia | |
| O4 | Execução pelo celular, também **sem internet**: check-in com local, checklist, fotos, material, assinatura e km do veículo | |
| O5 | Fechamento: relatório em PDF por link e WhatsApp, com a marca da empresa | |
| Depois | O6 painel e avisos, O7 ligação completa com Ponto e Frota, PMOC | |

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
