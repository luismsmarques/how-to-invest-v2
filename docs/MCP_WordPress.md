# MCP WordPress — plano e estado (handoff)

_Objetivo: ligar o [WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter)
para o Claude poder criar/editar conteúdo via comandos, melhorando a qualidade do
trabalho. Este ficheiro existe para retomar sem perder contexto numa sessão nova._

## O que é o mcp-adapter
Biblioteca PHP que expõe as **Abilities** do WordPress (Abilities API, WP 6.8+)
como um **servidor MCP** sobre a REST API do site (`/wp-json/...`, JSON-RPC).
Um cliente MCP (o Claude) chama *tools* (criar post, editar, etc.). Corre **dentro
de uma instalação WordPress**; autenticação por *application password*/OAuth.

## ⚠️ Regra do projeto a respeitar (CLAUDE.md)
A estrutura/conteúdo educativo é criada pelo **seeder** (código, versionado) —
"versionamos só o nosso código". Escrever posts via MCP vai à **base de dados
(não versionada)** → risco de *drift* e de saltar invariantes (disclaimer, sem
instrumentos nomeados, RGPD). Notícias já têm o pipeline RSS-AI com humano no meio.
**Conclusão:** MCP de escrita só para **operações de conteúdo pontuais**, nunca para
a estrutura que o seeder controla.

## Estado da rede (testado nesta sessão)
Do contentor do Claude Code na web:

| Destino | Resultado |
|---|---|
| `github.com` | ✅ 200 (alcançável) |
| `repo.packagist.org` (Composer) | ✅ alcançável |
| `www.googleapis.com` | ✅ (allowlisted) |
| `wordpress.org` / `downloads.wordpress.org` | ❌ 403 (egress) |
| `howtoinvest.pro` / `www.howtoinvest.pro` | ❌ egress proxy: "Host not in allowlist" |

**Dois muros** entre o contentor e o site:
1. **Egress allowlist** (nosso proxy) — fixada na **criação do ambiente**. Adicionar
   os hosts na network policy **só faz efeito num ambiente NOVO** (a sessão a correr
   mantém a política antiga). Hosts a adicionar (sem `https://`): `howtoinvest.pro`,
   `www.howtoinvest.pro`.
2. **WAF do site** (cPanel/Cloudflare) — bloqueou WebFetch com **403** no trabalho de
   SEO. Mesmo com egress aberto, pode bloquear pedidos não-browser ao `/wp-json/`.
   Exige exceção no WAF para a rota `/wp-json/` (por IP/User-Agent) — passo do utilizador.

## Plano (retomar aqui)
1. **Sessão nova** com egress já a incluir os dois hosts. Pedir ao utilizador "aberto".
2. **Teste de alcance (read-only):** `curl https://howtoinvest.pro/wp-json/`.
   - Responde JSON do REST → seguir para 3.
   - 403 → é o WAF; utilizador abre exceção `/wp-json/` no cPanel/Cloudflare e repete.
3. **Instalar o mcp-adapter** no site (plugin) — **alteração outward-facing: pedir
   confirmação explícita** antes. Confirmar versão do WP (Abilities API / 6.8+).
4. **Application password** para o Claude (o utilizador gera em Utilizadores → Perfil →
   Application Passwords). **Nunca gravar em ficheiro nem commit** — só em memória na
   sessão (mesma regra da chave PageSpeed).
5. **Configurar o MCP** (entrada em `.mcp.json` apontando ao endpoint MCP do site +
   header de auth) e testar uma *tool* read-only antes de qualquer escrita.

## Plano B (sem depender da rede para o site live)
Montar **WordPress local no contentor**: PHP 8.4 ✓, Composer ✓ (packagist alcançável),
Node ✓, GitHub alcançável ✓ — dá para obter core + WP-CLI do GitHub, base **SQLite**
(`sqlite-database-integration`), montar o nosso tema+plugins, **correr o seeder**,
renderizar/screenshot e até correr o **mcp-adapter localmente** (MCP por stdio em
`127.0.0.1`). Sem risco para produção. Falta no contentor: `wp-cli`, MySQL, core do WP
(tudo obtível via GitHub, já que `wordpress.org` está bloqueado).

## Contexto útil
- Tema `howtoinvest` (v0.8.24) + plugin `hti-engine` (v0.8.20) + `hti-rss-ai`.
- Branch de desenvolvimento desta linha de trabalho: `claude/sharp-brahmagupta-n6yyzo`
  (ff-merge para `main`, deploy por cPanel). Bump de VERSION ao mexer em CSS/JS.
