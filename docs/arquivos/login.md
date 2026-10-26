# Login (pelo Kiban Core)

**O que é:** a casca não tem senhas. A pessoa entra pelo Core, que já cuida da senha, das duas etapas e dos termos. Depois ela volta para a casca já identificada.

## O caminho
1. A pessoa abre `localhost:8090/padaria` e é levada para o Core (`/padaria/auth/login`). Ou abre só `localhost:8090` e toca em **"Entrar com a minha conta Kiban"**, sem precisar saber o nome da empresa (`/entrar/conta`).
2. Entra no Core (senha e, se configurado, código de duas etapas).
3. O Core devolve a pessoa para `localhost:8090/auth/callback`, um endereço só, que é o que fica cadastrado no Core.
4. A casca troca o código do Core pelo "crachá" da pessoa e pergunta quem ela é e de quais empresas faz parte. A pessoa já sai conectada (como há um endereço só, não é mais preciso o "bilhete").
5. Vai para a empresa. Pela página central: uma empresa abre direto; várias mostram a escolha.
- **Um login vale para todas as empresas da pessoa.** Ao abrir outra empresa, a casca confere com o Core (pelo login da empresa, sem pedir senha de novo se o Core já estiver conectado).

## Segurança
- **PKCE:** só quem começou o login consegue terminá-lo.
- O login precisa terminar **no mesmo navegador** que começou. Assim ninguém consegue fazer outra pessoa entrar com a conta dele.
- Links de login valem **uma vez só**.
- Os "crachás" do Core ficam guardados embaralhados na sessão.
- **A cada 10 minutos**, a casca pergunta ao Core (com o acesso do próprio sistema) se a pessoa ainda faz parte de cada empresa que ela abre. Quem foi removido no Core ou na tela Pessoas perde o acesso àquela empresa (e continua nas outras). Se a função mudou, ela é atualizada.
- A pessoa entra com **celular ou e-mail** e **fica conectada por 30 dias** no aparelho ("lembrar de mim"). A casca não guarda os "crachás" do Core das pessoas.
- **Se o Core estiver fora do ar**, quem já está dentro continua trabalhando. Só um login novo precisa do Core.
- **Idioma:** no menu do usuário (a bolinha com as iniciais) há Português, English e Español. Nas páginas simples, os idiomas ficam no topo. A escolha fica guardada para a pessoa e vale mais que o idioma da conta Kiban. Sem escolha, vale o idioma da conta Kiban, depois o da empresa e depois o do navegador.
- A troca de senha e das duas etapas é feita em "Minha conta Kiban" (menu do usuário).

## Arquivos
- `app/Http/Controllers/Auth/SingleSignOnController.php`: entrada (pela empresa ou pela página central) e retorno.
- `app/Auth/CoreSignIn.php`: cria ou atualiza a pessoa e a função dela na empresa.
- `app/Http/Middleware/RevalidateMembership.php`: a conferência a cada 10 minutos.
- `app/Http/Responses/LogoutResponse.php`: página "Você saiu".
- `app/Http/Middleware/SetLocale.php` e `app/Http/Controllers/LocaleController.php` (`/idioma/{idioma}`): escolha e troca de idioma. Campo `users.app_locale` (`database/migrations/2026_10_12_110000_...`).
- `routes/web.php`: os endereços do login e da página central.
- Telas: `resources/views/auth/` (sem acesso, você saiu, não foi possível entrar).
- Testes: `tests/Feature/SingleSignOnTest.php`.
