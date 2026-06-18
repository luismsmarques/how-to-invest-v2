# Checklist de QA — Gate de Lançamento (estado real)

Mapeada ao `Criterios_Pronto_QA_HowToInvest_MVP.md`. Revê item a item **em staging**
antes de apontar tráfego para produção.

**Legenda:**
`✅ Código` pronto e testado (verificar no ambiente) · `🔧 Operação` ação tua no
servidor/admin · `⚠️ Legal/conteúdo` revisão/decisão · `⬜ Lacuna` ainda por fazer no código.

> Suite automatizada (corre antes de cada release):
> ```
> for t in engine settings explainer prompt ratelimit cron mailer google; do \
>   php wp-content/plugins/hti-engine/tests/test-$t.php; done   # 162 checks
> ```

---

## 1. Motor de recomendação
- [x] ✅ Determinismo (mesmas respostas → mesmo arquétipo) — `test-engine`
- [x] ✅ Pontuação correta nos limites (0,5/6,11/12,17/18,23/24,27) — `test-engine`
- [x] ✅ Alocação dentro dos intervalos curados — `test-engine` (invariante garantida)
- [x] ✅ Alocação soma 100% — `test-engine`
- [x] ✅ Só classes de ativos, nunca instrumentos — `class-validator`
- [x] ✅ Crypto só com P8=sim **e** arquétipo ≥3 **e** sem trava 1; no extremo inferior
- [x] ✅ Travas 1/2/3 (sem fundo / horizonte / crypto bloqueada)
- [x] ✅ `engine_version` + `disclaimer_version` gravados em cada resultado
- [x] ✅ Matriz ≥12 cenários como suite repetível — `test-engine` (85 checks)

## 2. LLM (Gemini) e validação
- [x] ✅ Resposta válida passa schema e é gravada
- [x] ✅ Instrumento nomeado / percentagem inventada / idioma errado → rejeitado → fallback — `test-explainer`
- [x] ✅ `class_notes` incoerente / `safety_message` em falta com trava → rejeitado
- [x] ✅ Timeout (8s) / quota / 5xx do Gemini → fallback (1 retry)
- [x] ✅ Alocação numérica sai na mesma quando o LLM falha
- [ ] 🔧 Chave do Gemini **nunca** no HTML/JS do cliente — *verificar*: View Source + Network na página de resultado (não deve aparecer a chave)

## 3. Questionário
- [ ] 🔧 Completa-se de início a fim em **desktop e mobile** — *testar manual*
- [x] ✅ Estado parcial persiste ao recarregar (sessionStorage)
- [x] ✅ Barra de progresso correta por passo
- [x] ✅ Validação impede avançar sem responder
- [x] ✅ Micro-explicações (EN+PT) em cada pergunta
- [x] ✅ Mini-explicadores ESG/crypto na opção "não sei"
- [x] ✅ Submissão grava perfil e mostra o resultado *(renderizado na mesma página, não por redirect)*

## 4. Resultado e exportação
- [x] ✅ Gráfico reflete exatamente os números (SVG + lista)
- [x] ✅ Disclaimer contextual não-dispensável
- [x] ✅ "Porquê este arquétipo" (LLM ou fallback)
- [x] ✅ Notas por classe presentes
- [x] ✅ **Resultado guardado é o mesmo ao recarregar** — `GET /result` + o URL
  passa a `?profile=…&token=…` (history.replaceState); recarregar/partilhar reabre
  o resultado guardado. Dashboard liga cada perfil ao seu resultado. *(L-A fechada)*
- [x] ✅ Export PDF contém alocação, justificações, gráfico e disclaimer — `class-pdf` (Dompdf)
- [x] ✅ CTA de encerramento aponta para conteúdo educativo, nunca corretora

## 5. Conta e RGPD (gate duro)
- [x] ✅ Registo + login (email+password) com **verificação por email** (double opt-in)
- [x] ✅ Login Google (OAuth) — *requer config do OAuth Client em produção*
- [x] ✅ **Recuperação de password** — link "Esqueceste-te da password?" no
  formulário, a apontar para `wp_lostpassword_url()` (fluxo nativo do WP). *(L-C fechada)*
- [x] ✅ `claim-profile` associa o perfil anónimo à conta
- [x] ✅ Sem conta → nenhum dado identificado retido (só sessão anónima)
- [x] ✅ Área pessoal lista os perfis do utilizador (`[hti_account]`)
- [x] ✅ **Exportar dados** devolve tudo (`GET /export`)
- [x] ✅ **Apagar conta** remove conta + perfis + resultados em cascata (`DELETE /account`)
- [x] ✅ Consentimento registado **antes** de qualquer analítica (GA só após opt-in)
- [x] ✅ Logs do motor sem PII
- [x] ✅ Banner recusa não-essenciais por omissão (privacy-first)
- [ ] ⚠️ Política de privacidade e termos **publicados e ligados** — páginas existem
  (seeder) mas são **placeholders**; exigem **revisão jurídica** e mencionar o GA.

## 6. SEO e conteúdo
- [ ] 🔧 Schema válido por tipo de página — *testar no Rich Results Test* (DefinedTerm/Article do plugin + RankMath)
- [ ] 🔧 Sitemap XML gerado e **submetido ao Search Console** (RankMath)
- [x] ✅ Meta título/descrição editáveis por página (RankMath) — *confirmar config*
- [ ] 🔧 301s dos URLs Base44 respondem **301** — *verificar cada um* (8 URLs, `class-redirects`)
- [x] ✅ Questionário/resultado/conta com `noindex` (`wp_robots`); staging com password+noindex 🔧
- [x] ✅ CTA inline para o questionário inserível pelo editor (pattern `cta-questionnaire`)
- [ ] ⬜ **5–10 artigos seed publicados** — *lacuna*: o seeder cria glossário (5) + páginas, mas **não artigos educativos**. → ver "Lacunas".
- [x] ✅ Glossário com termos-semente (5 notas por classe) — seeder

## 7. Acessibilidade (WCAG 2.1 AA)
- [x] ✅ Questionário navegável só por teclado (fieldset/legend, foco gerido) — *confirmar manual*
- [x] ✅ Foco visível (`:focus-visible`)
- [x] ✅ Labels/ARIA nos campos (`role=alert`, `aria-live`, `progressbar`)
- [ ] 🔧 Contraste de cor cumpre AA — *verificar* (tokens calmos; testar com ferramenta)
- [ ] 🔧 Testado com **leitor de ecrã** (percurso questionário→resultado)
- [x] ✅ Gráfico com alternativa textual (alocação em lista)
- [x] ✅ Sem dependência exclusiva de cor (lista tem label + %)

## 8. Performance e segurança
**Performance**
- [ ] 🔧 Core Web Vitals em verde (home, artigo, questionário, resultado)
- [ ] 🔧 Cache de página ativa (LiteSpeed/WP)
- [ ] 🔧 CDN (Cloudflare) à frente do site
- [x] ✅/🔧 Imagens lazy-load (WP nativo); otimização WebP ao carregar conteúdo
- [x] ✅ Tempo até resultado <8s p95 (timeout Gemini 8s + fallback) — *medir em prod*

**Segurança**
- [ ] 🔧 HTTPS forçado em todo o site (AutoSSL + redirect)
- [x] ✅ Endpoints REST com nonce; ações sensíveis exigem autenticação
- [x] ✅ Login throttling — rate limiting próprio (M1) `class-rate-limit`; (opcional) plugin de hardening adicional
- [ ] 🔧 Backups automáticos para destino **externo**, **restauro testado**
- [ ] 🔧 WP/PHP/plugins atualizados; PHP **8.3** ✅; sem plugins EOL
- [x] ✅ Inputs do questionário validados e sanitizados server-side

## 9. Gate de lançamento (bloqueadores absolutos)
- [x] ✅ Secção 1 (motor) + Secção 2 (LLM/fallback) verdes
- [x] ✅ Secção 5 — **export e delete funcionam**
- [x] ✅ Disclaimers em questionário e resultado
- [ ] 🔧 301s no lugar e a responder (Secção 6)
- [ ] 🔧 Backups testados (Secção 8)
- [ ] 🔧 HTTPS forçado (Secção 8)
- [ ] ⚠️ Decisões em aberto: Gemini ✅ · **validação dos intervalos/pesos (Q2)** — revisão de negócio · **enquadramento legal (Q3)** — decisão do cliente/jurista

---

## Lacunas conhecidas no código (a decidir antes do lançamento)

| # | Lacuna | Impacto | Estado |
|---|---|---|---|
| L-A | **Página de resultado por `profile_id`/`session_token`** (recarregar/partilhar) | §4 — médio | ✅ **fechada** (`GET /result` + `?profile=` no URL + links no dashboard) |
| L-B | **5–10 artigos educativos seed** | §6 — médio (SEO) | ⬜ aberta (conteúdo) |
| L-C | **Link "Esqueci-me da password"** no formulário | §5 — baixo | ✅ **fechada** (`wp_lostpassword_url()`) |
| L-D | **Revisão jurídica** de privacidade/termos/disclaimers (+ menção ao GA) | §5/§9 — **bloqueador legal** | ⚠️ aberta (jurista) |

> Resta a L-B (artigos seed — conteúdo) e a L-D (revisão jurídica — ação tua).

## Ensaio de lançamento (fazer em staging, fim-a-fim)
1. Visitante → questionário (desktop + mobile) → resultado (normal + cada trava)
2. Exportar PDF
3. Criar conta (email **e** Google) → confirmar email → perfil ligado
4. Dashboard → **exportar dados** → **apagar conta** (confirmar cascata, sem resíduo)
5. Aceitar/recusar consentimento → confirmar que o GA só carrega após aceitar
6. Verificar os 8 redirects 301 e o `noindex` no questionário/resultado/staging
