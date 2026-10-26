# Empresas (dados separados)

**O que é:** todas as empresas usam o mesmo banco de dados, mas cada uma só enxerga o que é dela. Esse é o ponto mais importante de segurança da casca.

## Como funciona
- **Endereço único** (decisão do dono, 26/10/2026): todas as empresas usam o mesmo endereço, com o nome da empresa logo depois da barra: `localhost:8090/padaria/...` (na produção, `app.kibancore.com.br/padaria/...`).
  - Na entrada de toda visita (`app/Http/Kernel.php` → `app/Tenancy/TenantPath.php`), a casca separa o nome da empresa do resto do endereço. As telas enxergam `/ponto/apuracao`, e todo link que o sistema cria volta a levar `/padaria/` na frente.
  - Os arquivos (imagens, estilos) ficam na raiz, iguais para todas as empresas.
  - Nas tarefas automáticas (avisos, rotinas), os links também saem com a empresa (`TenantContext`).
  - Alguns nomes são do próprio sistema e nunca são de uma empresa (`entrar`, `auth`, `idioma`, `livewire`, `css`...): `TenantResolver::RESERVED`. O Core também bloqueia esses nomes.
- `app/Http/Middleware/IdentifyTenant.php` torna a empresa do endereço a empresa atual e lembra a última empresa usada no aparelho (cookie `kiban_empresa`).
- Na primeira visita de um endereço novo, a casca pergunta ao Core se a empresa existe e cria o registro dela (tabela `tenants`).
- Se o Core estiver fora do ar nessa primeira visita, a casca mostra "tente de novo em alguns minutos", e não "empresa não encontrada".
- Toda tabela de dados de empresa tem a coluna `tenant_id` e usa o `BelongsToTenant` (`app/Tenancy/BelongsToTenant.php`):
  - toda busca vem filtrada pela empresa atual;
  - todo cadastro novo é gravado na empresa atual;
  - alterar ou apagar um registro de outra empresa dá erro.
- **Sem empresa definida, dá erro** (`MissingTenantException`). O sistema nunca "adivinha" a empresa.
- **Proteção extra no próprio banco:** uma tarefa só pode apontar para uma lista da mesma empresa (chave composta). Mesmo um erro de programação não mistura empresas.

## Pessoas
- `users`: as pessoas, vindas do Core, sem senha aqui.
- `tenant_user`: de quais empresas cada pessoa faz parte e com qual função (dono, administrador, membro), igual ao Core.
- Um login vale para todas as empresas da pessoa. Ao abrir outra empresa, a casca confere com o Core se a pessoa faz parte dela; se não fizer, vê "Sem acesso a esta empresa" e continua conectada nas suas.

## Arquivos
- `app/Tenancy/`: `TenantContext` (empresa atual), `TenantResolver` (o nome da empresa no endereço), `TenantPath` (separa a empresa do endereço), `BelongsToTenant`, `MissingTenantException`.
- `app/Http/Kernel.php`: a entrada de toda visita.
- `app/Models/Tenant.php`, `app/Models/User.php`.
- `database/migrations/2026_10_11_100000_create_tenancy_tables.php`.
- `app/Http/Middleware/EnsureCentral.php` e `app/Http/Controllers/CentralController.php`: a página central (`/entrar`): leva direto à última empresa usada, ou à única, ou mostra a lista; quem não entrou faz o login sem precisar saber o nome da empresa.
- Telas simples (`resources/views/`):
  - `components/layout.blade.php` (o visual das páginas simples);
  - `tenant/` (não encontrada, bloqueada, indisponível);
  - `central/home.blade.php` (entrar e escolher a empresa).
- Testes: `tests/Feature/TenancyTest.php`.
