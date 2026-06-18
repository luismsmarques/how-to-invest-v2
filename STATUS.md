# STATUS — HowToInvest (handoff)

_Última atualização: 18 jun 2026. Lê isto primeiro ao retomar/numa sessão nova._

## Onde está o projeto
**LIVE em produção** (`howtoinvest.pro`) e funcional de ponta a ponta:
questionário → resultado (gráfico + disclaimer) → guardar perfil → dashboard;
homepage com artigos; glossário; páginas. **167 testes** verdes.

WordPress 7.0 instalado em `/home/howtoinvest/howtoinvest.pro/`. Tema **HowToInvest**
e plugin **HTI Engine** ativos. Conteúdo criado pelo seeder (glossário + 7 páginas + 8 artigos).

## Arquitetura (resumo)
- **Tema** `wp-content/themes/howtoinvest` (FSE, tokens em `theme.json`, disclaimer no rodapé).
- **Plugin** `wp-content/plugins/hti-engine` (o produto). Regra de ouro: **as regras decidem, o LLM só explica**.
  - Motor determinístico (`class-engine`) + config curada (`class-config`) → arquétipo + alocação por classe (soma 100), 3 travas.
  - LLM: `class-llm` (transporte: **WP 7.0 AI Client / Connectors** → fallback `class-gemini`) · `class-prompt` · `class-validator` · `class-fallback` · `class-explainer`.
  - REST `htinvest/v1`: recommend, result, register, login, claim-profile, my-profiles, export, account.
  - Frontend (JS vanilla): `questionnaire.js`, `result.js` (donut SVG), `account.js`, `consent.js`, `analytics.js`.
  - Segurança/RGPD: rate limit (`class-rate-limit`), verificação email double opt-in via **Brevo** (`class-verification`+`class-mailer`), consentimento (`class-consent`), GA gated, cron de limpeza (`class-cron`), login Google (`class-google`).
  - Admin: `class-settings` (Definições → HowToInvest). PDF: `class-pdf` (Dompdf, fallback HTML).
- Detalhe por ficheiro: `wp-content/plugins/hti-engine/README.md`.

## Chaves a definir (no `wp-config.php` de `howtoinvest.pro/`)
```php
define( 'HTI_GEMINI_API_KEY', '...' );   // ou Definições → Connectors (WP AI Client)
define( 'HTI_BREVO_API_KEY',  '...' );   // emails de verificação de conta (P0 p/ contas)
define( 'HTI_GOOGLE_CLIENT_ID',     '...' ); // login Google (opcional)
define( 'HTI_GOOGLE_CLIENT_SECRET', '...' );
```
- Sem Gemini/Connectors → resultado usa **fallback curado** (funciona).
- Sem Brevo → `wp_mail` (pode não entregar em shared hosting) → registo de contas não confirma.
- Google: registar o **Redirect URI** (Definições → HowToInvest) no Google Cloud Console.
- GA4 já ativo (`G-QWST7PZNBT`), só carrega após aceitar o banner de cookies.

## Deploy
- Branches: **`main`** = produção · **`develop`** = staging/integração · feature → PR para `develop` → release `develop → main`. Ver `CONTRIBUTING.md`.
- cPanel Git: `Manage → Pull or Deploy → Update from Remote → Deploy HEAD Commit`. O `.cpanel.yml` (simples; destino fixo `howtoinvest.pro/wp-content`) copia tema+plugin.
- **Se o deploy do cPanel falhar/pendurar:** ver `DEPLOY.md §5.1` (deploy manual / File Manager copy a partir de `repositories/how-to-invest-v2/wp-content/...`).
- Testes: `for t in engine settings explainer prompt ratelimit cron mailer google llm; do php wp-content/plugins/hti-engine/tests/test-$t.php; done`

## O que falta para o GO-LIVE público (checklist completa: `docs/QA_Gate_Lancamento.md`)
**Código:** ✅ tudo (lacunas L-A/L-B/L-C fechadas).

**Operacional (teu, no servidor):**
- [ ] HTTPS forçado (redirect http→https em todo o site)
- [ ] Verificar os 8 redirects 301 do Base44 (ex.: `/About` → `/about/`)
- [ ] Backups externos automáticos **e restauro testado**
- [ ] Cache (LiteSpeed/WP) + CDN (Cloudflare) + Core Web Vitals
- [ ] **RankMath**: instalar/ativar → sitemap inclui `glossary`/`news` → submeter ao Search Console
- [ ] Configurar **Brevo** (senão o registo de contas não confirma)
- [ ] Acessibilidade: contraste AA + teste com leitor de ecrã

**Legal (⚠️ bloqueador antes de divulgar):**
- [ ] **L-D — Revisão jurídica** dos disclaimers + páginas privacidade/termos (são placeholders; mencionar o GA na política de privacidade).

## Próximos passos sugeridos
1. Configurar RankMath (sitemap + schema + Search Console).
2. Configurar Brevo e testar o fluxo de registo/verificação.
3. Verificar 301s + HTTPS.
4. Enviar textos legais ao jurista (L-D).
