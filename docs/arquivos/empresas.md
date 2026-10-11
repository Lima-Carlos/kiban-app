# Empresas (dados separados)

**O que é:** todas as empresas usam o mesmo banco de dados, mas cada uma só enxerga o que é dela. Esse é o ponto mais importante de segurança da casca.

## Como funciona
- A casca descobre a empresa pelo endereço: `padaria.localhost:8090` → "padaria" (`app/Tenancy/TenantResolver.php`, `app/Http/Middleware/IdentifyTenant.php`).
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
- Uma pessoa de outra empresa que tente abrir este endereço vê "Sem acesso a esta empresa".

## Arquivos
- `app/Tenancy/`: `TenantContext` (empresa atual), `TenantResolver`, `BelongsToTenant`, `MissingTenantException`.
- `app/Models/Tenant.php`, `app/Models/User.php`.
- `database/migrations/2026_10_11_100000_create_tenancy_tables.php`.
- `app/Http/Middleware/EnsureCentral.php` e `app/Http/Controllers/CentralController.php`: endereço central.
- Telas simples (`resources/views/`):
  - `components/layout.blade.php` (o visual das páginas simples);
  - `tenant/` (não encontrada, bloqueada, indisponível);
  - `central/home.blade.php` (escolher a empresa).
- Testes: `tests/Feature/TenancyTest.php`.
