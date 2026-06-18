# Wireframes e Fluxo de Ecrãs — HowToInvest MVP

**Companion do:** PRD HowToInvest WordPress MVP (v2)
**Data:** 18 de junho de 2026
**Estado:** wireframes de baixa fidelidade (esqueleto acordado, não design final)
**Próximo passo de design:** refinar visual no Claude Design

> Estes wireframes definem *que ecrãs existem, o que contêm e como se navega entre eles*. São o contrato para desenvolvimento. O visual polido (cores, tipografia, espaçamento) é trabalho posterior.

---

## 1. Mapa de fluxo (navegação ponta-a-ponta)

```
                         ┌──────────────────┐
                         │   HOMEPAGE (SEO) │
                         │  landing + CTA   │
                         └────────┬─────────┘
                                  │
          ┌───────────────────────┼───────────────────────┐
          │                       │                       │
          ▼                       ▼                       ▼
┌──────────────────┐   ┌──────────────────┐   ┌──────────────────┐
│  CONTEÚDO (SEO)  │   │   QUESTIONÁRIO   │   │   GLOSSÁRIO /    │
│ artigos, guias   │   │   (multi-step)   │   │   NOTÍCIAS       │
│ + CTA inline ────┼──▶│                  │   │  + CTA inline    │
└──────────────────┘   └────────┬─────────┘   └──────────────────┘
                                  │ submit
                                  ▼
                         ┌──────────────────┐
                         │  PROCESSAMENTO   │
                         │ (loading + regra │
                         │  determinística) │
                         └────────┬─────────┘
                                  │
                    ┌─────────────┴─────────────┐
                    │   trava disparou?         │
                    ▼ sim                  não  ▼
          ┌──────────────────┐        ┌──────────────────┐
          │ RESULTADO c/      │        │  RESULTADO       │
          │ trava de segurança│        │  (arquétipo)     │
          │ (educa primeiro)  │        │                  │
          └────────┬──────────┘        └────────┬─────────┘
                    └─────────────┬─────────────┘
                                  ▼
                         ┌──────────────────┐
                         │  AÇÕES DO         │
                         │  RESULTADO        │
                         │ • Export PDF      │
                         │ • Ler mais (→SEO) │
                         │ • Refazer         │
                         └──────────────────┘
```

**Princípios de navegação:**
- Todo o conteúdo SEO tem um CTA inline para o questionário (ponto de conversão).
- O questionário nunca pede login no MVP (P0). Login social é P1.
- O resultado termina sempre a empurrar para *educação* (artigo), nunca para execução/corretora.
- "Refazer" reinicia o questionário preservando nada (privacidade por omissão).

---

## 2. Inventário de ecrãs

| # | Ecrã | Tipo WP | Prioridade | Indexável (SEO)? |
|---|------|---------|------------|------------------|
| E1 | Homepage | Página | P0 | Sim |
| E2 | Artigo educativo | Post | P0 | Sim |
| E3 | Glossário (lista + termo) | CPT | P0 | Sim |
| E4 | Notícias (lista + item) | CPT | P0 | Sim |
| E5 | Questionário (multi-step) | Plugin | P0 | Não (noindex) |
| E6 | Processamento/loading | Plugin | P0 | Não |
| E7 | Resultado | Plugin | P0 | Não (noindex) |
| E8 | Consentimento (cookies/RGPD) | Plugin/overlay | P0 | n/a |
| E9 | Políticas (privacidade, termos) | Página | P0 | Sim |

---

## 3. Wireframes por ecrã

### E1 — Homepage

```
┌──────────────────────────────────────────────────────────┐
│ [logo HowToInvest]          Aprender  Glossário  Notícias │  ← nav
├──────────────────────────────────────────────────────────┤
│                                                            │
│        Descobre que tipo de investidor és.                 │  ← headline
│        Uma ferramenta educativa, gratuita, em minutos.     │  ← subhead
│                                                            │
│              [  Começar o questionário  ]                  │  ← CTA primário
│                                                            │
│        (microcopy: educativo · não é aconselhamento)       │  ← disclaimer leve
├──────────────────────────────────────────────────────────┤
│  Como funciona (3 passos):                                 │
│  [1 Responde]   [2 Vê o teu perfil]   [3 Aprende]          │
├──────────────────────────────────────────────────────────┤
│  Artigos em destaque (SEO):                                │
│  [card artigo]  [card artigo]  [card artigo]               │
├──────────────────────────────────────────────────────────┤
│  Footer: políticas · disclaimer completo · contacto        │
└──────────────────────────────────────────────────────────┘
```

### E2 — Artigo educativo (motor de SEO + conversão)

```
┌──────────────────────────────────────────────────────────┐
│ nav                                                        │
├──────────────────────────────────────────────────────────┤
│  Título do artigo (H1)                                     │
│  meta: tempo de leitura · categoria                        │
│                                                            │
│  Corpo do artigo ............................              │
│  ...........................................              │
│                                                            │
│  ┌────────────────────────────────────────────┐           │
│  │  CTA inline: "Não sabes o teu perfil?        │  ← bloco  │
│  │  Faz o questionário →"   [ Começar ]         │   conversão│
│  └────────────────────────────────────────────┘           │
│  ...........................................              │
│                                                            │
│  Artigos relacionados: [card] [card]                       │
├──────────────────────────────────────────────────────────┤
│  footer                                                    │
└──────────────────────────────────────────────────────────┘
```
*Schema: Article. CTA inline inserível pelo editor em qualquer ponto (P0, req. 6.1).*

### E3 — Glossário

```
LISTA:                              TERMO INDIVIDUAL:
┌─────────────────────────┐         ┌─────────────────────────┐
│ Glossário               │         │ "ETF" (H1)              │
│ [A B C D E F ...] filtro │         │ Definição clara, simples│
│                         │         │                         │
│ • ETF                   │         │ Exemplo / analogia      │
│ • Obrigação             │         │                         │
│ • Volatilidade          │         │ [CTA: faz o questionário]│
│ • ESG                   │         │ Termos relacionados:    │
│ ...                     │         │ [Obrigação] [Ação]      │
└─────────────────────────┘         └─────────────────────────┘
```
*Schema: DefinedTerm / FAQ. Cada termo é uma página indexável — alimenta SEO de cauda longa.*

### E5 — Questionário (multi-step) — ecrã central

```
┌──────────────────────────────────────────────────────────┐
│  HowToInvest          [ X sair ]                           │
│  ████████░░░░░░░░  Passo 3 de 6                            │  ← barra progresso
├──────────────────────────────────────────────────────────┤
│                                                            │
│   Se a tua carteira cair 20% em poucas semanas,            │  ← pergunta (P3)
│   o que fazes?                                             │
│                                                            │
│   ┌────────────────────────────────────────────┐          │
│   │ ℹ  Porque perguntamos isto:                  │  ← micro- │
│   │ o melhor plano é o que consegues manter      │   educação│
│   │ quando o mercado assusta.                    │          │
│   └────────────────────────────────────────────┘          │
│                                                            │
│   ( ) Vendo tudo para não perder mais                      │  ← opções
│   ( ) Vendo uma parte, fico nervoso                        │   (radio)
│   ( ) Não faço nada, espero recuperar                      │
│   ( ) Aproveito para investir mais                         │
│                                                            │
│              [ ← Anterior ]    [ Continuar → ]             │
└──────────────────────────────────────────────────────────┘
```
*Estado parcial preservado na sessão. Acessível por teclado + ARIA (req. 6.2). noindex.*

### E6 — Processamento

```
┌──────────────────────────────────────────────────────────┐
│                                                            │
│            ( animação de loading )                         │
│                                                            │
│        A preparar o teu perfil educativo...                │
│        (texto rotativo: "a analisar o horizonte",          │
│         "a montar o exemplo de carteira")                  │
│                                                            │
└──────────────────────────────────────────────────────────┘
```
*Decisão determinística é instantânea; o tempo aqui é a chamada ao LLM explicador. Alvo <8s p95 (req. 6.3 / métricas).*

### E7 — Resultado (caminho normal)

```
┌──────────────────────────────────────────────────────────┐
│  O teu perfil: ARQUÉTIPO EQUILIBRADO                       │  ← arquétipo
│                                                            │
│  ┌────────────────────────────────────────────┐           │
│  │ ⚠ Ferramenta educativa. Isto é um exemplo    │  ← DISCLAIMER
│  │ ilustrativo do tipo de carteira que um       │   contextual
│  │ perfil como o teu poderia estudar. Não é     │   (não dispensável)
│  │ recomendação personalizada nem aconselha-    │           │
│  │ mento financeiro.                            │           │
│  └────────────────────────────────────────────┘           │
│                                                            │
│   ┌──────────────┐    Exemplo de estrutura por classe:     │
│   │   gráfico     │    • Ações globais ........ 55–65%      │  ← por CLASSES
│   │   circular    │    • Obrigações ........... 25–35%      │   (nunca tickers)
│   │  (alocação)   │    • Alternativos/REITs ... 5–10%       │
│   └──────────────┘    • Liquidez ............. 0–5%        │
│                                                            │
│   Porque caíste neste perfil:                              │  ← transparência
│   "Indicaste horizonte de 7–15 anos e que manténs a        │   do método
│    calma numa queda, por isso enquadras-te no Equilibrado."│   (texto do LLM)
│                                                            │
│   O que significa cada classe: [Ações ▸][Obrigações ▸]     │  ← educação
│                                                            │
│  [ Exportar PDF ]   [ Ler mais sobre isto → ]   [ Refazer ]│  ← ações
└──────────────────────────────────────────────────────────┘
```

### E7b — Resultado com trava de segurança

```
┌──────────────────────────────────────────────────────────┐
│  Antes de falarmos de carteiras...                         │
│                                                            │
│  ┌────────────────────────────────────────────┐           │
│  │ Indicaste que ainda não tens um fundo de     │  ← Trava 1│
│  │ emergência. O passo mais importante é         │   educa   │
│  │ construí-lo primeiro (3–6 meses de despesas). │   PRIMEIRO│
│  │ Eis porquê e como começar: [artigo →]         │           │
│  └────────────────────────────────────────────┘           │
│                                                            │
│  Quando tiveres essa base, um perfil como o teu            │  ← exemplo
│  poderia estudar esta estrutura:                           │   condicionado
│   [ gráfico + classes, igual a E7 mas enquadrado ]         │
│                                                            │
│  [ Exportar PDF ]   [ Como criar o fundo → ]   [ Refazer ] │
└──────────────────────────────────────────────────────────┘
```
*Travas 2 e 3 (horizonte manda; crypto só com base) seguem o mesmo padrão: educam antes de mostrar.*

### E8 — Consentimento (RGPD)

```
┌──────────────────────────────────────────────────────────┐
│ Usamos cookies essenciais. Os não-essenciais só com a tua  │
│ autorização.   [ Recusar não-essenciais ]  [ Aceitar ]    │
│                [ Personalizar ]   política de privacidade  │
└──────────────────────────────────────────────────────────┘
```
*Recusa de não-essenciais por omissão (privacy-first). Consentimento registado antes de processamento não-essencial (req. 6.6).*

---

## 4. Estados e edge cases por ecrã (checklist de QA)

- **E5 Questionário:** vazio (1º acesso) · parcial (recarregar mantém respostas) · sem JS (degradação) · só teclado.
- **E6 Processamento:** sucesso · timeout LLM · quota excedida · resposta mal-formada → todos levam a E7 com fallback pré-escrito, nunca a erro cru.
- **E7 Resultado:** caminho normal · cada uma das 3 travas · preferência crypto pedida mas bloqueada · ESG pedido.
- **Acessibilidade transversal:** contraste, foco visível, labels, navegação por teclado, leitor de ecrã.

---

## 5. O que isto NÃO define (deliberadamente)

- Cores, tipografia, espaçamento, ilustrações → trabalho de design visual (Claude Design).
- Componentes finais de UI → derivam do tema escolhido (Q7 em aberto).
- Gamificação, dashboard, login → fora do MVP (P1/P2 no PRD).

---

## 6. Próximos artefactos da via completa (ainda em falta)

1. Modelo de dados (perfil, resultado, CPTs e relações).
2. Contratos de API (JSON in/out do endpoint do motor, códigos de erro).
3. Prompt do LLM explicador + schema de validação.
4. Textos finais (disclaimers, mensagens por arquétipo, fallbacks, micro-explicações).
5. Stack concreta (tema, plugins, hosting, staging).
6. Critérios de "pronto" e plano de QA detalhado.
```
