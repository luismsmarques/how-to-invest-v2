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
- [x] ✅ CTA de encerramento do bloco educativo aponta para conteúdo educativo, nunca corretora (o módulo de parceria pós-resultado é uma secção separada e rotulada — gates próprios na secção "Corretoras & afiliados" abaixo)

## 5. Conta e RGPD (gate duro)
> **Validação ponta-a-ponta:** correr `docs/QA_RGPD_Checklist.md` em staging
> (exportar, apagar, cancelar, cascade real, consentimento) antes do lançamento.

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
  — com o **Consent Mode v2** ligado (Definições → Analytics, desligado por omissão)
  o gtag carrega para todos mas com todos os sinais de armazenamento negados: o que
  se verifica passa a ser **nenhum cookie `_ga` antes do aceite**, e não a ausência
  de pedidos. Os sinais de publicidade ficam negados nos dois modos.
- [x] ✅ Logs do motor sem PII
- [x] ✅ Banner recusa não-essenciais por omissão (privacy-first)
- [ ] ⚠️ Política de privacidade e termos **publicados e ligados** — **rascunhos
  substantivos** EN/PT no seeder (`legal_privacy`/`legal_terms`, baseados nas práticas
  reais), com pontos `[●]` a preencher; ainda exigem **revisão jurídica** + menção ao GA.

## 6. SEO e conteúdo
- [ ] 🔧 Schema válido por tipo de página — *testar no Rich Results Test* (DefinedTerm/Article do plugin + RankMath)
- [ ] 🔧 Sitemap XML gerado e **submetido ao Search Console** (RankMath)
- [x] ✅ Meta título/descrição editáveis por página (RankMath) — *confirmar config*
- [ ] 🔧 301s dos URLs Base44 respondem **301** — *verificar cada um* (8 URLs, `class-redirects`)
- [x] ✅ Questionário/resultado/conta com `noindex` (`wp_robots`); staging com password+noindex 🔧
- [x] ✅ CTA inline para o questionário inserível pelo editor (pattern `cta-questionnaire`)
- [x] ✅ **5–10 artigos seed publicados** — 8 artigos educativos (EN+PT) no seeder, com CTA e links ao glossário. *(L-B fechada)*
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

## 8-bis. Jogos `/games/` (`hti-games`)

> A suite do plugin (2.310 asserções) cobre o **contrato**: a aritmética dos dois
> motores nos dois portos, os payloads que a API não pode emitir antes da decisão,
> o portão dos casos, a paridade EN/PT, a voz, o contraste e o orçamento de assets.
> Não cobre nada que precise de WordPress: a harness é PHP puro, sem base de dados,
> sem Polylang, sem cookies e sem correio. **Tudo o que está aqui só se prova em
> staging**, e o detalhe RGPD está na secção 8 do `QA_RGPD_Checklist.md`.

**Instalação (por esta ordem, tudo pelo admin — sem SSH):**
- [ ] 🔧 Plugin ativado, e o `hti-engine` também (o `hti-games` depende dele para as
      permissões REST, o rate limiter, o mailer e as métricas; sem ele há aviso no admin)
- [ ] 🔧 Uma página de admin carregada → as duas tabelas nascem no `init`. Confirmar no
      painel de prontidão (Definições → HTI Games)
- [ ] 🔧 *Seed / sync* → confirmar **dez** páginas, não cinco: `/games/` e `/pt/jogos/`
      e as quatro filhas de cada. Sem isto são dez 404 com shortcodes a funcionar por trás
- [ ] 🔧 Polylang: tradução ativa nos dois CPTs novos e ligações EN↔PT confirmadas nas dez páginas
- [ ] 🔧 Biblioteca de cenários instalada (lotes de ~100, repetir até dizer feito)
- [ ] 🔧 Biblioteca de casos do The Reveal instalada
- [ ] 🔧 Brevo configurado — sem isso o magic link não envia e a §8.3 do RGPD não corre de todo

**Jogar a sério:**
- [ ] 🔧 Um dia completo de cada jogo, telemóvel e desktop, EN e PT: decidir, arriscar,
      replay, resultado, morrer, recomeçar, partilhar
- [ ] 🔧 Segunda jogada no mesmo dia → 409 com o resultado que já existe (é o `UNIQUE` da
      base de dados a decidir, e nunca foi exercido contra MySQL a sério)
- [ ] 🔧 Viragem do dia às 00:00 IST com uma sessão aberta
- [ ] 🔧 Cada interruptor de desligar fecha **a API** e não só a página (503)

**O que a suite não vê:**
- [ ] ⚠️ Código-fonte e aba de rede durante `/today`: nenhuma vela de desfecho, nenhum nome
      de empresa, nenhum retorno — antes da decisão
- [ ] ⚠️ `grep` ao HTML **renderizado** das dez páginas por `/go/` e pelos slugs de corretora
      (o teste varre o código-fonte; isto varre o que o visitante recebe)
- [ ] ⚠️ Leitor de ecrã e teclado, EN e PT — os oito itens no fim de `tests/test-a11y.php`
- [ ] ⚠️ JSON-LD das páginas de jogo no Rich Results Test
- [ ] ⚠️ `docs/QA_RGPD_Checklist.md` §8 completa, com assinatura e data

**Conteúdo (⚠️ revisão humana, não é código):**
- [ ] ⚠️ **Os 34 dossiês do The Reveal nomeiam empresas reais com números que são
      reconstruções ilustrativas declaradas.** Nunca tiveram revisão editorial. É a
      superfície que a skill `financial-analyst` existe para cobrir, e a decisão de
      proveniência está no invariante 2 do `CLAUDE.md`
- [ ] ⚠️ Revisão editorial da copy EN+PT dos dois jogos
- [ ] ⚠️ Enquadramento legal da secção e da hipótese de lhe comprar tráfego —
      `docs/Dossie_Juridico_Jogos.md`, dentro do âmbito da L-D

## 9. Gate de lançamento (bloqueadores absolutos)
- [x] ✅ Secção 1 (motor) + Secção 2 (LLM/fallback) verdes
- [x] ✅ Secção 5 — **export e delete funcionam**
- [x] ✅ Disclaimers em questionário e resultado
- [ ] 🔧 301s no lugar e a responder (Secção 6)
- [ ] 🔧 Backups testados (Secção 8)
- [ ] 🔧 HTTPS forçado (Secção 8)
- [ ] ⚠️ Decisões em aberto: Gemini ✅ · **validação dos intervalos/pesos (Q2)** — revisão de negócio · **enquadramento legal (Q3)** — decisão do cliente/jurista
- [ ] 🔧 Secção 8-bis (`/games/`) verde, se a secção for para o ar neste lançamento

---

## Corretoras & afiliados (gate próprio — antes de ativar a secção em produção)

> A camada de afiliação tem revisão jurídica **obrigatória** (estende a L-D:
> disclosure CMVM 13/03/2025, publicidade a serviços de investimento, ESMA/CFD)
> e validação direta dos dados "NÃO CONFIRMADO" do estudo de corretoras.

- [ ] Disclosure de afiliação visível em **cada** página com links de corretora (comparador, categorias, reviews, guias, módulo pós-resultado), a linkar "Como ganhamos dinheiro"
- [ ] Rótulo "Parceria · Publicidade" em todos os cards/módulos com deal ativo
- [ ] Aviso de risco CFD com % em toda a menção a corretora que ofereça CFDs
- [ ] Links de saída só via `/go/{slug}` (302, `noindex`, `Disallow: /go/`), `rel="sponsored nofollow noopener"` com deal ativo, `rel="nofollow noopener"` sem deal
- [ ] Motor limpo: `test-explainer`/`test-llm` verdes (corretoras no blocklist do validator); resultado, PDF e email sem qualquer corretora
- [ ] Módulo "Passar à prática" aparece depois das ações educativas, visualmente separado e rotulado
- [ ] Páginas EN+PT ligadas (hreflang) e no sitemap; `/go/` fora do sitemap
- [ ] Dados por corretora com `verified` atual; números "não confirmado" não publicados
- [ ] Revisão jurídica da camada de afiliação concluída (L-D estendida)

## Lacunas conhecidas no código (a decidir antes do lançamento)

| # | Lacuna | Impacto | Estado |
|---|---|---|---|
| L-A | **Página de resultado por `profile_id`/`session_token`** (recarregar/partilhar) | §4 — médio | ✅ **fechada** (`GET /result` + `?profile=` no URL + links no dashboard) |
| L-B | **5–10 artigos educativos seed** | §6 — médio (SEO) | ✅ **fechada** (8 artigos EN+PT no seeder) |
| L-C | **Link "Esqueci-me da password"** no formulário | §5 — baixo | ✅ **fechada** (`wp_lostpassword_url()`) |
| L-D | **Revisão jurídica** de privacidade/termos/disclaimers (+ menção ao GA) | §5/§9 — **bloqueador legal** | ⚠️ aberta (jurista) |

> **Todas as lacunas de código fechadas.** Resta a L-D (revisão jurídica — ação tua) e os itens operacionais 🔧.

## Ensaio de lançamento (fazer em staging, fim-a-fim)
1. Visitante → questionário (desktop + mobile) → resultado (normal + cada trava)
2. Exportar PDF
3. Criar conta (email **e** Google) → confirmar email → perfil ligado
4. Dashboard → **exportar dados** → **apagar conta** (confirmar cascata, sem resíduo)
5. Aceitar/recusar consentimento → confirmar que o GA só carrega após aceitar
6. Verificar os 8 redirects 301 e o `noindex` no questionário/resultado/staging
