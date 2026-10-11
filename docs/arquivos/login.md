# Login (pelo Kiban Core)

**O que é:** a casca não tem senhas. A pessoa entra pelo Core, que já cuida da senha, das duas etapas e dos termos. Depois ela volta para a casca já identificada.

## O caminho
1. A pessoa abre `padaria.localhost:8090` e é levada para o Core (`/auth/login`).
2. Entra no Core (senha e, se configurado, código de duas etapas).
3. O Core devolve a pessoa para o **endereço central** (`localhost:8090/auth/callback`). Esse endereço é um só para todas as empresas, e é ele que fica cadastrado no Core.
4. A casca troca o código do Core pelo "crachá" da pessoa e pergunta quem ela é e de quais empresas faz parte.
5. Um **bilhete de uso único** (vale 1 minuto) leva a pessoa para o endereço da empresa, já logada.

## Segurança
- **PKCE:** só quem começou o login consegue terminá-lo.
- O login precisa terminar **no mesmo navegador** que começou. Assim ninguém consegue fazer outra pessoa entrar com a conta dele.
- Links de login e bilhetes valem **uma vez só**.
- Os "crachás" do Core ficam guardados embaralhados na sessão.
- **A cada 10 minutos**, a casca pergunta ao Core se a pessoa ainda faz parte da empresa. Quem foi removido da equipe no Core perde o acesso aqui também. Se a função mudou, ela é atualizada.
- **Se o Core estiver fora do ar**, quem já está dentro continua trabalhando. Só um login novo precisa do Core.
- O idioma segue o da pessoa no Core. A troca de idioma e de senha é feita em "Minha conta Kiban" (menu do usuário).

## Arquivos
- `app/Http/Controllers/Auth/SingleSignOnController.php`: entrada, retorno e bilhete.
- `app/Auth/CoreSignIn.php`: cria ou atualiza a pessoa e a função dela na empresa.
- `app/Http/Middleware/RevalidateMembership.php`: a conferência a cada 10 minutos.
- `app/Http/Responses/LogoutResponse.php`: página "Você saiu".
- `app/Http/Middleware/SetLocale.php`: idioma da pessoa (vem do Core), depois o da empresa.
- `routes/web.php`: os endereços do login e da página central.
- Telas: `resources/views/auth/` (sem acesso, você saiu, não foi possível entrar).
- Testes: `tests/Feature/SingleSignOnTest.php`.
