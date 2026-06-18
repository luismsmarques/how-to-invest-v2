# PRD — HowToInvest · MVP em WordPress

**Produto:** HowToInvest — "AI-Powered Investing for Everyone"
**Versão do documento:** v3 (MVP — conta de utilizador e RGPD em P0)
**Data:** 18 de junho de 2026
**Stack alvo:** WordPress (full-WP), motor de recomendação via API LLM externa
**Migração desde:** Base44 (React) → WordPress
**Objetivo estratégico declarado:** SEO e conteúdo

---

## 0. Resumo executivo

O HowToInvest existe hoje como uma app React no Base44 (live em `howtoinvest.pro`) que, através de um questionário gamificado, devolve uma sugestão de portfólio (ações, ETFs, crypto) ajustada ao perfil do investidor. A reconstrução em WordPress tem um objetivo claro e único: **ganhar tráfego orgânico**. O WordPress é a melhor ferramenta do mercado para a camada de conteúdo/SEO e uma ferramenta apenas adequada para a camada de aplicação interativa.

Este PRD define um MVP "full WordPress" em que o CMS orquestra todo o produto, e o motor de recomendação corre como serviço externo (chamada server-side a uma API LLM) consumido por um plugin custom. Não se reconstrói o LLM em PHP; reconstrói-se a *experiência* em WordPress.

**Mudança de scope (v3):** por decisão explícita, a **conta de utilizador entrou no MVP** (req. 6.8). O utilizador pode, após receber a sugestão, criar uma conta (assente nos utilizadores nativos do WordPress) para guardar e gerir o seu perfil. Esta decisão alarga o âmbito e a data, e traz consigo os **direitos RGPD do titular** (ver/exportar/apagar) como requisito legal P0 (req. 6.9), por se passar a reter perfil financeiro identificado mediante ação consciente do utilizador.

**Enquadramento regulatório (não jurídico):** Uma ferramenta que sugere alocações de portfólio personalizadas com instrumentos concretos pode cair no âmbito de consultoria de investimento regulada (CMVM/ESMA). A resposta de design **não é remover** o motor — é desenhá-lo, by design, do lado da informação/ferramenta educativa genérica. Isto consegue-se através de sete alavancas (output por classes de ativos e não por instrumentos nomeados; linguagem condicional e ilustrativa; enquadramento por arquétipo de perfil e não por indivíduo; transparência do método; universo curado e fechado; ausência de execução/CTA transacional; disclaimers contextuais). O motor é construído sobre regras determinísticas e auditáveis que **tu controlas** (ver Secção 11), ficando o LLM com o papel restrito de *explicar*, nunca de decidir a alocação. Confirmar o enquadramento concreto com jurista antes de colocar tráfego real — este PRD foi desenhado para tornar essa validação rápida e barata.

---

## 1. Problem Statement

Investidores principiantes não sabem por onde começar: enfrentam excesso de opções, jargão e medo de errar, e por isso adiam ou nunca investem. O HowToInvest resolve isto traduzindo um perfil simples num ponto de partida concreto e explicado. O problema do *negócio* hoje é distinto: a app vive numa stack (Base44/React) com fraca descoberta orgânica, o que limita a aquisição de utilizadores ao tráfego pago ou direto. Sem um motor de conteúdo indexável, o custo de aquisição não desce e o produto não cresce de forma composta.

---

## 2. Goals

1. **Aquisição orgânica:** estabelecer uma base de conteúdo indexável (educativo + glossário + notícias) que gere tráfego de pesquisa, medido por sessões orgânicas crescentes mês a mês.
2. **Conversão tráfego→ferramenta:** levar o visitante de conteúdo a completar o questionário, medido pela taxa de início e de conclusão do questionário a partir de páginas de conteúdo.
3. **Paridade funcional do core:** o utilizador obtém uma recomendação de portfólio explicada equivalente à da app atual, dentro do WordPress.
4. **Custo e manutenção:** stack gerível por uma pessoa não-engenheira para o conteúdo, sem depender de deploys de código para publicar artigos.
5. **Fundações de conformidade:** disclaimers, consentimento e linguagem educativa implementados de raiz, não como remendo.

---

## 3. Non-Goals (fora do âmbito do MVP)

1. **Gamificação completa (badges, missões):** alto custo de construção, baixo impacto na hipótese central (SEO + conversão). Fica para v2.
2. **Dashboard de utilizador *rico* (histórico multi-simulação, comparações):** a conta de utilizador e a área pessoal básica **entraram no MVP** (ver 6.8) por decisão de scope — o utilizador pode criar conta e guardar/gerir o seu perfil. O que fica fora do MVP é o dashboard avançado: histórico de múltiplas simulações ao longo do tempo e comparações entre perfis.
3. **Dados de mercado em tempo real / preços live:** o motor usa universo de instrumentos curado e estático no MVP; integração com feeds de preço é v2.
4. **App móvel nativa:** o MVP é web responsivo. Sem build nativo.
5. **Reconstruir o LLM dentro do PHP:** o motor permanece como API externa. Não-negociável por qualidade.

---

## 4. Personas e User Stories

### Persona A — "Principiante curioso" (utilizador final, núcleo)
- Como principiante, quero ler um artigo claro sobre como começar a investir, para ganhar confiança antes de agir.
- Como principiante, quero responder a um questionário curto sobre os meus objetivos e tolerância ao risco, para receber uma sugestão adequada a mim.
- Como principiante, quero ver a sugestão de portfólio com um gráfico e uma explicação em linguagem simples, para perceber *porquê* aquela alocação.
- Como principiante, quero exportar o meu plano (PDF), para o guardar ou mostrar a alguém.
- Como principiante, quero perceber que isto é educativo e não conselho financeiro, para ter expectativas corretas.

### Persona B — "Editor de conteúdo" (operador interno)
- Como editor, quero publicar e editar artigos e definições de glossário sem tocar em código, para alimentar o motor de SEO.
- Como editor, quero que cada artigo tenha campos de SEO (meta título, descrição, dados estruturados), para rankear.
- Como editor, quero inserir uma chamada-à-ação para o questionário dentro de qualquer artigo, para converter leitores.

### Persona C — "Administrador" (operador interno)
- Como admin, quero configurar a chave da API LLM e os parâmetros do motor num único sítio seguro, para gerir o serviço sem editar ficheiros.
- Como admin, quero rever os perfis submetidos de forma agregada/anónima, para entender a procura.

### Edge cases / estados
- Questionário abandonado a meio (guardar estado parcial na sessão).
- Falha da API LLM (timeout, quota): mostrar fallback gracioso, não erro cru.
- Resposta do LLM mal-formada: validar schema antes de renderizar.
- Utilizador sem JavaScript / leitor de ecrã: questionário acessível e degradável.

---

## 5. Arquitetura proposta (full WordPress)

```
┌─────────────────────────────────────────────────────────────┐
│                         WORDPRESS                            │
│                                                              │
│  CAMADA DE CONTEÚDO (SEO)          CAMADA DE APLICAÇÃO        │
│  ───────────────────────          ──────────────────         │
│  • Posts: artigos educativos      • Plugin custom            │
│  • CPT: Glossário                   "hti-engine"             │
│  • CPT: Notícias                  • Questionário (multi-step)│
│  • Páginas/landing                • Guardar perfil (CPT)     │
│  • Plugin SEO (Yoast/RankMath)    • Página de resultado      │
│  • Schema markup                  • Export PDF               │
└───────────────────────────┬─────────────────────────────────┘
                            │ server-side (PHP, nonce + key segura)
                            ▼
              ┌──────────────────────────────┐
              │   API LLM externa            │
              │   (Anthropic / OpenAI)       │
              │   + universo de instrumentos │
              │     curado (JSON/tabela)     │
              └──────────────────────────────┘
```

**Princípios:**
- O WordPress nunca expõe a chave da API ao browser. A chamada ao LLM é sempre server-side (admin-ajax ou REST endpoint custom com nonce).
- O resultado do LLM é validado contra um schema JSON antes de renderizar.
- O universo de instrumentos elegíveis é curado e versionado (ficheiro/tabela), não inventado livremente pelo LLM — reduz risco regulatório e alucinação.
- Perfis guardados como Custom Post Type privado, preparando v2 (dashboard/histórico) sem refazer o schema.

---

## 6. Requisitos

### P0 — Must-Have (sem isto não há MVP)

**6.1 Motor de conteúdo / SEO**
- Posts standard para artigos educativos; CPT "Glossário" e CPT "Notícias".
- Plugin de SEO (RankMath ou Yoast) configurado: meta título/descrição por página, sitemap XML, controlo de indexação.
- Dados estruturados Schema.org (Article, FAQ, Definition) automáticos.
- URLs limpos e estáveis (mapear os atuais: `/Questionnaire`, `/EducationalResources`, `/FinancialNews`) com redireccionamentos 301 desde os caminhos antigos.
- *Acceptance:*
  - [ ] Editor publica um artigo com campos SEO sem código.
  - [ ] Cada tipo de página emite sitemap e schema válidos (testado no Rich Results Test).
  - [ ] URLs antigos respondem 301 para os novos.

**6.2 Questionário (multi-step)**
- Formulário multi-passo: objetivos, horizonte temporal, tolerância ao risco, preferências (ESG, crypto sim/não), montante.
- Estado parcial preservado na sessão; barra de progresso.
- Acessível (navegável por teclado, labels, ARIA) e funcional em mobile.
- *Acceptance (Given/When/Then):*
  - Given um visitante na página do questionário, When responde a todos os passos e submete, Then o perfil é gravado e é redirecionado ao resultado.
  - Given um visitante a meio do questionário, When recarrega a página, Then as respostas dadas persistem.
  - Given um utilizador só com teclado, When percorre o formulário, Then consegue completá-lo sem rato.

**6.3 Motor de recomendação (regras determinísticas + LLM explicador)**
- O "cérebro" é **determinístico e curado** (Secção 11): o questionário pontua, a pontuação mapeia num de cinco arquétipos, e cada arquétipo tem intervalos de alocação **por classes de ativos** (nunca instrumentos nomeados). As travas de segurança podem sobrepor-se à pontuação.
- O LLM tem papel **restrito**: recebe o arquétipo já decidido pelas regras e a indicação de qual trava disparou, e a sua única função é *escrever* a explicação em linguagem clara, personalizada na forma mas genérica no conteúdo. O LLM **não decide** alocações.
- Endpoint server-side monta o prompt com o arquétipo + contexto curado, chama o LLM, valida a resposta contra schema antes de renderizar.
- *Acceptance:*
  - [ ] Perfil submetido é classificado num arquétipo de forma determinística e reproduzível.
  - [ ] A alocação devolvida está dentro dos intervalos curados do arquétipo e soma 100%.
  - [ ] O output é por classes de ativos, não por instrumentos nomeados.
  - [ ] Cada bloco da alocação tem justificação textual educativa.
  - [ ] As travas de segurança (Secção 11) sobrepõem-se corretamente à pontuação quando aplicáveis.
  - [ ] Resposta mal-formada do LLM não quebra a página (fallback).
  - [ ] A chave da API nunca aparece no HTML/JS do cliente.

**6.4 Página de resultado + visualização**
- Gráfico circular da alocação + lista explicada.
- Disclaimer educativo visível e não-dispensável.
- *Acceptance:*
  - [ ] Gráfico reflete exatamente os números da alocação.
  - [ ] Disclaimer presente em todas as páginas de resultado.

**6.5 Exportação PDF**
- Exportar o plano como PDF (núcleo do "Professional Reports" atual; CSV/PNG ficam P1).
- *Acceptance:*
  - [ ] PDF contém alocação, justificações, gráfico e disclaimer.

**6.6 Conformidade e consentimento**
- Disclaimer "educativo, não é aconselhamento financeiro" em questionário e resultado.
- Banner de cookies/consentimento (RGPD) com recusa de não-essenciais por omissão.
- Política de privacidade e termos.
- *Acceptance:*
  - [ ] Nenhuma recomendação é mostrada sem disclaimer associado.
  - [ ] Consentimento registado antes de processamento não-essencial.

**6.7 Configuração administrativa**
- Página de definições do plugin: chave API, modelo, temperatura, intervalos de alocação por arquétipo e pesos de pontuação (configuração editável, ver Modelo de Dados §4).
- *Acceptance:*
  - [ ] Admin altera parâmetros do motor sem editar ficheiros.

**6.8 Conta de utilizador (entrou no MVP por decisão de scope)**
- Assenta no sistema de utilizadores **nativo do WordPress** (`wp_users`).
- Após receber a sugestão, o utilizador pode **opcionalmente** criar conta para guardar e gerir o seu perfil.
- Registo/login por email+password e/ou Google. Recuperação de password.
- Perfil anónimo (sessão) é "adotado" pela conta ao criá-la (endpoint `claim-profile`).
- *Acceptance:*
  - Given um visitante com resultado, When escolhe criar conta e regista-se, Then o perfil anónimo da sessão fica associado à sua conta.
  - Given um utilizador autenticado, When acede à área pessoal, Then vê os perfis que guardou.
  - [ ] Sem criar conta, nenhum dado identificado é retido (só sessão anónima).

**6.9 Direitos do titular dos dados — RGPD (requisito legal P0)**
- O utilizador pode **ver, exportar e apagar** a sua conta e todos os dados associados.
- Apagamento é em cascata (conta + perfis + resultados) e irreversível, com confirmação.
- *Acceptance:*
  - Given um utilizador autenticado, When pede exportar os seus dados, Then recebe todos os dados que lhe dizem respeito (portabilidade).
  - Given um utilizador autenticado, When confirma apagar a conta, Then conta, perfis e resultados são removidos sem resíduo identificável.
  - [ ] Logs do motor não contêm dados pessoais identificáveis.

### P1 — Nice-to-Have (fast-follow pós-lançamento)
- Exportação CSV e PNG (completar paridade de "Professional Reports").
- Feed de notícias semi-automático (curadoria assistida) em vez de manual.
- Projeções de crescimento por cenário (gráfico de projeção).
- A/B testing de CTAs conteúdo→questionário.
- *(O login social Google subiu para P0 como parte de 6.8.)*

### P2 — Future Considerations (desenhar para, não construir agora)
- Gamificação: badges e missões.
- Dashboard de utilizador **rico**: histórico de múltiplas simulações, comparação entre perfis ao longo do tempo. *(A conta e a área pessoal básica subiram para P0 — ver 6.8; o histórico avançado fica aqui.)*
- Dados de mercado em tempo real / rebalanceamento.
- hreflang e SEO internacional completo — o modelo já nasce bilingue (EN default + PT), mas a estratégia de SEO multi-idioma completa fica para depois.
- Newsletter / sequências de email a partir do perfil.

---

## 7. Success Metrics

### Leading (dias a semanas)
- **Páginas indexadas:** nº de URLs no índice do Google (Search Console). Alvo: todo o conteúdo seed indexado em 30 dias.
- **Taxa de início do questionário:** % de visitantes de páginas de conteúdo que clicam para começar. Alvo inicial: ≥8%; stretch: 15%.
- **Taxa de conclusão do questionário:** % dos que começam e chegam ao resultado. Alvo: ≥60%.
- **Taxa de sucesso do motor:** % de submissões que devolvem recomendação válida. Alvo: ≥98%.
- **Tempo até resultado:** da submissão ao render. Alvo: <8s (p95).

### Lagging (semanas a meses)
- **Sessões orgânicas/mês:** tendência crescente trimestre a trimestre (Search Console/Analytics).
- **Posições de palavras-chave:** keywords-alvo educativas no top 10.
- **Exportações de PDF:** proxy de valor percebido.
- **Retenção/retorno:** % de visitantes que voltam.

*Método:* Google Search Console + analítica respeitadora de privacidade (ex.: Plausible/Matomo, alinhado com RGPD). Avaliar a 1 semana, 1 mês e 1 trimestre.

---

## 8. Open Questions

| # | Questão | Responsável | Bloqueante? |
|---|---------|-------------|-------------|
| 1 | Que provider/modelo LLM e budget de tokens por recomendação? | admin/eng | Sim |
| 2 | Quem cura e valida os intervalos de alocação por classe de ativo e os pesos de pontuação do questionário (Secção 11)? | stakeholder/financeiro | Sim |
| 3 | A ferramenta precisa de registo CMVM ou enquadramento legal específico em PT/UE? | legal | Sim |
| 4 | Hosting: WP gerido (Kinsta/WP Engine) ou VPS? Afeta performance/SEO. | eng | Não |
| 5 | Migração de utilizadores/dados existentes do Base44? Há base instalada a preservar? | stakeholder | Não |
| 6 | MVP só PT, só EN, ou ambos? Decide arquitetura multi-idioma desde já. | stakeholder | Não (mas barato decidir cedo) |
| 7 | Tema: comprar premium, usar block theme nativo, ou custom? | design/eng | Não |

---

## 9. Timeline e fases sugeridas

Sem datas rígidas conhecidas. Faseamento sugerido para entregar valor cedo:

- **Fase 1 — Fundação SEO (semanas 1–2):** WP + tema + plugin SEO + estrutura de conteúdo (posts, CPTs) + 301s + 5–10 artigos seed. *Já gera valor de indexação sem o motor.*
- **Fase 2 — Core interativo (semanas 3–5):** plugin do motor, questionário, chamada LLM, página de resultado, disclaimer.
- **Fase 3 — Conta + output + conformidade (semanas 6–7):** conta de utilizador (registo/login/Google, claim-profile, área pessoal), direitos RGPD (export/delete), export PDF, banner de consentimento, políticas, QA de acessibilidade. *(A conta de utilizador alargou esta fase — ver mudança de scope v3.)*
- **Fase 4 — Lançamento e medição (semana 8):** Search Console, analítica, monitorização do motor, recolha de métricas leading.

**Dependências:** Fase 2 depende da decisão de provider LLM (Q1) e da validação dos intervalos de alocação e pesos de pontuação (Q2, Secção 11). Fase 3 (conta + RGPD) depende de Q3 (legal) — reter perfil financeiro identificado exige enquadramento jurídico claro. Fase 4 depende de Q3 estar resolvida antes de tráfego real.

---

## 10. Notas de migração desde o Base44

- A análise de funcionalidades baseou-se na ficha pública do produto e na app live; **a análise do código-fonte ficou pendente** (repositório não acessível por fetch — provavelmente privado). Para refinar requisitos do motor (prompts, schema de perfil, universo de instrumentos), fornecer acesso ao repo ou export dos ficheiros: páginas do questionário, lógica do motor, e schema de dados.
- Preservar e redireccionar (301) os URLs públicos atuais para não perder qualquer equity de SEO já existente.
- Mapeamento funcional Base44 → WordPress MVP:
  - Questionário gamificado → Questionário multi-step (gamificação desfasada p/ P2).
  - Motor LLM → Plugin + API LLM server-side (P0).
  - Visualizações → Gráfico de alocação (P0); projeções (P1).
  - Relatórios PDF/CSV/PNG → PDF (P0), CSV/PNG (P1).
  - Gamificação (badges/missões) → P2.
  - Recursos educativos → Posts/CPT (P0, núcleo do SEO).
  - Dashboard + login Google → Login social P1, dashboard de histórico P2.
  - Notícias financeiras → CPT manual (P0), semi-automático (P1).
```

---

## 11. Especificação do motor educativo

Esta secção é o coração defensável do produto. O motor separa **decisão** (determinística, curada, tua) de **explicação** (LLM, restrito). O LLM nunca decide alocações.

### 11.1 As sete alavancas de design (educativo vs. prescritivo)

1. **Unidade do output:** classes de ativos (defensável), nunca tickers/instrumentos nomeados (risco).
2. **Linguagem:** condicional e ilustrativa ("um perfil assim costuma considerar…"), nunca imperativa pessoal ("deves comprar").
3. **Enquadramento:** por **arquétipo de perfil** ("perfis do tipo Equilibrado tendem a…"), não pelo indivíduo ("para ti, X").
4. **Transparência do método:** mostrar sempre *porquê* aquele arquétipo, dado o que a pessoa respondeu.
5. **Universo curado e fechado:** o LLM trabalha sobre classes e regras fixas e versionadas; não inventa.
6. **Sem execução / sem CTA transacional:** nunca liga a corretora, nunca diz "compra agora", nunca cria urgência. Termina em educação (→ artigo).
7. **Disclaimers contextuais:** junto ao output, no momento que importa, não só no rodapé.

### 11.2 Classes de ativos do universo curado

Ações globais · Ações regionais · Obrigações · Liquidez/equivalentes · Imobiliário/REITs · Matérias-primas · Crypto (como categoria). Sem instrumentos nomeados no output do motor. Exemplos de produtos, se existirem, vivem como conteúdo editorial estático claramente rotulado "exemplo ilustrativo — não é recomendação".

### 11.3 Os cinco arquétipos e intervalos de alocação

Intervalos (não pontos fixos) reforçam o caráter ilustrativo. Números a validar por Q2/jurista.

| Arquétipo | Perfil | Ações globais | Obrigações | Liquidez | Alternativos/REITs | Crypto |
|---|---|---|---|---|---|---|
| **1 — Preservação** | Conservador, curto prazo (≤3 anos) | 15–25% | 55–70% | 10–20% | 0–5% | 0% |
| **2 — Rendimento Equilibrado** | Mod.-conservador, ~3–7 anos | 35–45% | 40–50% | 5–10% | 0–10% | 0–2% |
| **3 — Equilibrado** | Moderado, ~5–10 anos | 50–65% | 25–35% | 0–5% | 5–10% | 0–3% |
| **4 — Crescimento** | Mod.-agressivo, 10+ anos | 65–80% | 10–20% | 0–5% | 5–10% | 0–5% |
| **5 — Crescimento Agressivo** | Agressivo, 15+ anos | 75–90% | 0–10% | 0–5% | 0–10% | 0–10% |

Regra de validação: o output final tem de somar 100%; crypto sempre no extremo inferior do intervalo.

### 11.4 Questionário (com camada educativa por pergunta)

Cada pergunta inclui uma micro-explicação do *porquê* (momento de aprendizagem + conteúdo reutilizável). P1–P5 pontuam; P6 é trava; P7–P8 são lentes de preferência (não pontuam).

**P1 — Quando vais provavelmente precisar da maior parte deste dinheiro?**
*O tempo é o maior aliado de quem investe — quanto mais longe o objetivo, mais oscilações se toleram.*
≤3 anos = 0 · 3–7 anos = 2 · 7–15 anos = 4 · >15 anos = 6

**P2 — Qual é o objetivo principal deste dinheiro?**
*Um fundo de emergência e a reforma pedem estratégias muito diferentes.*
Proteger quantia para breve = 0 · Crescer com equilíbrio = 2 · Acumular a longo prazo = 4 · Maximizar crescimento = 6

**P3 — Se a tua carteira cair 20% em poucas semanas, o que fazes?**
*O melhor plano é o que consegues manter quando o mercado assusta — vender no fundo é o erro mais caro.*
Vendo tudo = 0 · Vendo parte = 2 · Não faço nada = 4 · Invisto mais = 6

**P4 — Que parte do rendimento mensal consegues investir sem afetar o dia-a-dia?**
*A capacidade de assumir risco depende de teres uma base financeira estável.*
Quase nada = 0 · Pequena parte = 2 · Parte confortável = 4 · Parte significativa = 6

**P5 — Como descreverias a tua experiência com investimentos?**
*A familiaridade ajuda a manter a calma, mas não substitui um plano adequado ao prazo.*
Nunca investi = 0 · Pouca prática = 1 · Já invisto há algum tempo = 2 · Invisto com confiança = 3

**P6 — Já tens um fundo de emergência (3–6 meses de despesas)?**
*Investir antes de ter um colchão de segurança é arriscado — esta pergunta protege-te.*
Não = **Trava 1** · Sim = segue

**P7 — Queres incluir critérios de sustentabilidade (ESG)?** Sim / Não / Não sei *(→ mini-explicador)* — lente, não pontua.
**P8 — Curiosidade em incluir pequena exposição a crypto?** Sim / Não / Não sei *(→ mini-explicador)* — lente, não pontua.

### 11.5 Mapeamento pontuação → arquétipo

Soma de P1–P5 (máx. 27):

| Pontos | Arquétipo |
|---|---|
| 0–5 | 1 — Preservação |
| 6–11 | 2 — Rendimento Equilibrado |
| 12–17 | 3 — Equilibrado |
| 18–23 | 4 — Crescimento |
| 24–27 | 5 — Crescimento Agressivo |

### 11.6 Travas de segurança (sobrepõem-se à pontuação)

Demonstram que a ferramenta protege o utilizador — caráter educativo e prudente perante um regulador.

- **Trava 1 — Sem fundo de emergência (P6=Não):** independentemente da pontuação, o resultado educa primeiro sobre construir o fundo de emergência; o exemplo de carteira é enquadrado como "para quando tiveres essa base". Nunca empurra alguém sem rede para o mercado.
- **Trava 2 — Horizonte manda sobre apetite:** se P1=≤3 anos mas pontuação alta, limita a arquétipo 1–2 e explica porquê ("mesmo com apetite por risco, 3 anos é pouco para recuperar de uma queda").
- **Trava 3 — Crypto só com base sólida:** preferência por crypto (P8) só ativa exposição se arquétipo ≥3 *e* Trava 1 satisfeita; caso contrário, educa sobre porque é prematuro. Crypto entra sempre no extremo inferior do intervalo.

### 11.7 Papel do LLM (restrito)

Input ao LLM: arquétipo já decidido + qual trava disparou + respostas relevantes. Output do LLM: **apenas texto explicativo** (porque caiu no arquétipo, o que cada classe significa, o que cada trava implica), validado contra schema. Temperatura baixa. O LLM **não** produz nem altera percentagens. Falha/timeout/schema inválido → fallback gracioso com texto pré-escrito por arquétipo.

### 11.8 Sinergia com o SEO

Cada micro-explicação, justificação de arquétipo e trava é também conteúdo educativo reutilizável: "porque é que 3 anos é pouco tempo" → artigo; explicadores ESG/crypto → glossário. O motor e o conteúdo alimentam-se mutuamente, servindo o objetivo estratégico (SEO).

