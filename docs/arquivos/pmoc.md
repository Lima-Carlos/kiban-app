# Kiban Core PMOC (manutenção preventiva)

Primeira versão (03/11/2026). Complemento do Kiban Core Campo para quem faz manutenção de ar-condicionado (ou de outros equipamentos com manutenção preventiva). No Brasil, a Lei 13.589/2018 obriga prédios de uso coletivo climatizados a ter o PMOC, e órgãos públicos pedem o documento nos editais: quem emite é a empresa que faz a manutenção. Código do produto no Core: `pmoc`. Preço aprovado pelo dono (07/11/2026): R$ 99 com 100 equipamentos.

## Como funciona
- **Plano de manutenção** (PMOC → Planos): um por endereço do cliente do Campo. Tem o responsável técnico e o registro (CREA, CFT...), a área climatizada, os ocupantes, de quanto em quanto tempo são as visitas (todo mês, a cada 2, 3, 6 ou 12 meses), a data da primeira visita e quantos dias antes a ordem de serviço deve abrir.
- **Equipamentos**: etiqueta (ex.: EV-01), tipo (split, cassete, piso-teto, janela, dutado, VRF, chiller, fan coil, outro), local, marca, modelo, número de série, capacidade (BTU/h), gás e data de instalação.
- **Visitas**: todo dia de manhã o `kiban:pmoc` abre no Campo a ordem de serviço das visitas que estão perto (os "dias antes" do plano). A ordem já vem com as tarefas da visita: as mensais sempre, as trimestrais a cada 3 meses, as semestrais a cada 6 e as anuais uma vez por ano (a visita anual faz todas). A lista de equipamentos vai nas observações internas. O técnico faz tudo pelo celular, como qualquer ordem do Campo (sem internet também).
- **Abrir a próxima visita**: botão na lista para abrir agora, sem esperar a data.
- **Documento PMOC em PDF** (botão "Baixar PMOC"): identificação do local, empresa e responsável técnico, carga térmica, equipamentos, atividades com a periodicidade e o histórico das visitas, com espaço para as assinaturas. Usa a Identidade da Empresa.
- Um plano atrasado abre no máximo 3 visitas por vez, para não lotar a agenda.

## Quem usa
Precisa do Campo na licença. Quem é gestor ou atendimento no Campo gerencia os planos; quem só consulta o Campo só consulta aqui; técnicos não veem (eles recebem as ordens).

## Limite do plano
Equipamentos ativos (`equipment`). Salvar um plano que passe do limite é bloqueado com o motivo.

## Arquivos
- `app/Modules/Pmoc/PmocModule.php`, `Models/` (`Plan`, `Equipment`, `Visit`), `Policies/PlanPolicy.php`.
- `Services/PmocActivities.php` (tarefas e nível de cada visita), `PmocService.php` (abrir visitas e o PDF), `PmocAccess.php`.
- `Filament/Plans/` (lista, novo, editar; trava do limite em `ChecksEquipmentLimit`).
- `resources/views/pmoc/document-pdf.blade.php` e `visits.blade.php`.
- `app/Console/Commands/RunPmocTasks.php` (`kiban:pmoc`, todo dia às 06:15).
- Migração `2026_11_13_100000_create_pmoc_tables.php`; textos em `lang/*/pmoc.php`; testes em `tests/Feature/PmocTest.php`.

## Para o advogado/engenheiro conferir
A lista de tarefas segue a rotina usual (Portaria 3.523/1998 e ABNT NBR 13971), mas o responsável técnico é quem confere e assina o plano. O documento diz isso no rodapé.

## Etiquetas com QR (03/11/2026)
Na lista de planos, **"Etiquetas"** abre uma folha para imprimir (A4, 3 × 7 etiquetas adesivas de 63,5 × 38,1 mm) com uma etiqueta por equipamento ativo: QR, nome da empresa, etiqueta (ex.: EV-01), local, marca/modelo e número de série. O QR abre a **página pública do equipamento** (veja abaixo); de lá, quem é da equipe abre a **ficha do equipamento** no sistema (`EquipmentPage`, precisa de login): dados, plano, próxima visita e as últimas visitas. Os técnicos do Campo também abrem. Peças: `App\Http\Controllers\LabelController`, `resources/views/labels/sheet.blade.php`, `App\Support\QrCode`.

## Página do QR sem login e ART (03/11/2026, pela pesquisa de concorrentes)
- O QR da etiqueta abre `/pmoc/{código}` (código longo e aleatório de cada equipamento), **sem login**, com a identidade da empresa: o equipamento, "Manutenção em dia" ou "atrasada", a última manutenção feita, a próxima, a frequência, o responsável técnico e a **ART/TRT**.
- Não mostra nada privado: nem preço, nem endereço, nem telefone do cliente, nem número de série.
- **Pedir atendimento**: quem está na frente do aparelho escreve o problema, o nome e o telefone. Vira uma **OS de prioridade alta no Campo** (origem `qr`) e avisa o escritório no sino. Se já houver um pedido aberto do mesmo aparelho, o novo é somado a ele. Proteções: no máximo 5 pedidos a cada 10 minutos por endereço, campo escondido contra robôs e aviso de que o nome e o telefone só servem para o retorno (LGPD).
- **ART/TRT** no plano: número e arquivo (PDF ou foto). O número aparece no documento do PMOC e na página do QR.
- Peças: `App\Http\Controllers\Pmoc\EquipmentPublicController`, `resources/views/pmoc/public.blade.php`, migração `2026_11_15_110000_add_public_page_and_art_to_pmoc.php`, `tests/Feature/PmocPublicPageTest.php`.
