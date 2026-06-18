# Critérios de "Pronto" + Plano de QA — HowToInvest MVP

**Companion do:** PRD (v3) + Modelo de Dados + Prompt LLM + Textos + Stack
**Data:** 18 de junho de 2026
**Estado:** checklist de aceitação e lançamento (fecha a via completa)

> Este documento consolida critérios já dispersos pelos outros artefactos e
> acrescenta o que faltava (acessibilidade, performance, segurança). É o contrato
> de "pode ir para produção".

---

## 0. Definition of Done — três níveis

**DoD por funcionalidade:** código revisto, testado em staging, critérios de aceitação do PRD verdes, sem regressões, strings EN+PT presentes.

**DoD por fase:** todas as funcionalidades da fase em DoD + QA da fase passado + demo em staging aprovada.

**DoD para lançar:** todas as fases em DoD + secção 9 (gate de lançamento) 100% verde.

---

## 1. Motor de recomendação (o núcleo defensável)

- [ ] Mesma combinação de respostas → sempre o mesmo arquétipo (determinismo).
- [ ] Pontuação P1–P5 calculada corretamente em todos os limites (0, 5/6, 11/12, 17/18, 23/24, 27).
- [ ] Alocação devolvida está **dentro** dos intervalos curados do arquétipo.
- [ ] Alocação soma sempre exatamente 100%.
- [ ] Output é por **classes de ativos**, nunca instrumentos nomeados.
- [ ] Crypto só aparece se P8=sim **e** arquétipo ≥3 **e** sem trava 1; sempre no extremo inferior.
- [ ] Trava 1 (sem fundo de emergência) → mensagem educativa prioritária, carteira enquadrada como "para depois".
- [ ] Trava 2 (horizonte ≤3 anos com pontuação alta) → limita a arquétipo 1–2 com explicação.
- [ ] Trava 3 (crypto bloqueada) → educa em vez de incluir.
- [ ] `engine_version` e `disclaimer_version` gravados em cada resultado.

**Casos de teste mínimos (matriz):** um perfil por arquétipo (5) + um por cada trava (3) + crypto pedida e concedida + crypto pedida e bloqueada + ESG pedido. Mínimo 12 cenários documentados com input→output esperado.

---

## 2. LLM (Gemini) e validação

- [ ] Resposta válida do LLM passa o schema e é gravada.
- [ ] Resposta com instrumento nomeado → **rejeitada** → fallback.
- [ ] Resposta com percentagem inventada (≠ alocação fixa) → rejeitada → fallback.
- [ ] Resposta no idioma errado → rejeitada → fallback.
- [ ] `class_notes` com chaves que não batem com a alocação → rejeitada.
- [ ] Trava disparada mas `safety_message` null → rejeitada.
- [ ] Timeout do Gemini → fallback dentro do alvo de tempo, sem erro cru.
- [ ] Quota/erro 5xx do Gemini → fallback.
- [ ] A alocação numérica sai **na mesma** quando o LLM falha (decisão é independente do LLM).
- [ ] Chave do Gemini nunca presente no HTML/JS do cliente (inspecionar fonte e network).

---

## 3. Questionário

- [ ] Completa-se de início a fim em desktop e mobile.
- [ ] Estado parcial persiste ao recarregar a meio.
- [ ] Barra de progresso correta em cada passo.
- [ ] Validação impede avançar sem responder.
- [ ] Micro-explicações (EN+PT) presentes em cada pergunta.
- [ ] Mini-explicadores de ESG e crypto aparecem na opção "não sei".
- [ ] Submissão grava perfil e redireciona ao resultado.

---

## 4. Resultado e exportação

- [ ] Gráfico reflete exatamente os números da alocação.
- [ ] Disclaimer contextual presente e não-dispensável em todos os resultados.
- [ ] Texto "porquê este arquétipo" presente (LLM ou fallback).
- [ ] Notas por classe presentes para cada classe da alocação.
- [ ] Resultado guardado é o mesmo ao recarregar (não recalcula).
- [ ] Export PDF contém alocação, justificações, gráfico e disclaimer.
- [ ] CTA de encerramento aponta para conteúdo educativo, nunca para execução/corretora.

---

## 5. Conta de utilizador e RGPD (requisito legal — gate duro)

- [ ] Registo e login (email+password) funcionam.
- [ ] Login Google funciona.
- [ ] Recuperação de password funciona.
- [ ] `claim-profile` associa corretamente o perfil anónimo à conta criada.
- [ ] Sem criar conta, nenhum dado identificado é retido (só sessão anónima).
- [ ] Área pessoal mostra os perfis do utilizador autenticado.
- [ ] **Exportar dados** devolve todos os dados do utilizador.
- [ ] **Apagar conta** remove conta + perfis + resultados em cascata, sem resíduo identificável.
- [ ] Consentimento registado antes de qualquer analítica não-essencial.
- [ ] Logs do motor não contêm dados pessoais identificáveis.
- [ ] Banner de consentimento recusa não-essenciais por omissão.
- [ ] Política de privacidade e termos publicados e ligados.

---

## 6. SEO e conteúdo

- [ ] Cada tipo de página emite schema válido (testar no Rich Results Test).
- [ ] Sitemap XML gerado e submetido ao Search Console.
- [ ] Meta título/descrição editáveis por página, sem código.
- [ ] URLs antigos do Base44 respondem 301 para os novos (sem perder equity).
- [ ] Questionário, resultado e staging com `noindex`.
- [ ] CTA inline para o questionário inserível pelo editor em qualquer artigo.
- [ ] 5–10 artigos seed publicados antes do lançamento.
- [ ] Glossário com termos-semente publicados (reutilizar notas por classe).

---

## 7. Acessibilidade (WCAG 2.1 AA como alvo)

- [ ] Questionário 100% navegável só por teclado.
- [ ] Foco visível em todos os elementos interativos.
- [ ] Labels e ARIA corretos em todos os campos.
- [ ] Contraste de cor cumpre AA.
- [ ] Testado com um leitor de ecrã (percurso questionário→resultado).
- [ ] Gráfico do resultado tem alternativa textual (a alocação em lista).
- [ ] Sem dependência exclusiva de cor para transmitir informação.

---

## 8. Performance e segurança

**Performance:**
- [ ] Core Web Vitals em verde nas páginas-chave (home, artigo, questionário, resultado).
- [ ] Cache de página ativa e a funcionar.
- [ ] CDN (Cloudflare) à frente do site.
- [ ] Imagens otimizadas (WebP, lazy-load).
- [ ] Tempo até resultado <8s (p95), incluindo chamada ao Gemini.

**Segurança:**
- [ ] HTTPS forçado em todo o site.
- [ ] Endpoints REST protegidos por nonce; ações sensíveis exigem autenticação.
- [ ] Plugin de hardening ativo (login throttling).
- [ ] Backups automáticos para destino externo, testados (restauro verificado).
- [ ] WP/PHP/plugins atualizados; nenhum plugin EOL/abandonado.
- [ ] Inputs do questionário validados e sanitizados server-side.

---

## 9. Gate de lançamento (tudo verde antes de tráfego real)

Bloqueadores absolutos — não se lança sem:
- [ ] Secção 1 (motor) e secção 2 (LLM/fallback) verdes.
- [ ] Secção 5 (conta + RGPD) verde — **export e delete funcionam**.
- [ ] Disclaimers presentes em questionário e resultado (secção 4).
- [ ] 301s no lugar (secção 6) — não perder SEO existente.
- [ ] Backups testados (secção 8).
- [ ] HTTPS forçado (secção 8).
- [ ] Decisões em aberto resolvidas: provider LLM ✅ (Gemini); validação dos intervalos/pesos (Q2); enquadramento legal (Q3, decisão do cliente).

> **Nota:** os artefactos cobrem o produto e o RGPD técnico. A adequação regulatória
> final (CMVM/ESMA) é uma decisão de negócio/jurídica fora do âmbito do QA técnico —
> registada aqui apenas como item de consciência, não como tarefa de engenharia.

---

## 10. Processo de QA sugerido

1. **Teste por funcionalidade** em staging à medida que é construída (DoD por funcionalidade).
2. **Teste de fase** ao fechar cada fase do PRD (1→4).
3. **Matriz do motor** (secção 1) corrida como suite repetível — idealmente automatizada (os 12+ cenários input→output).
4. **Passagem de acessibilidade** dedicada antes do lançamento (secção 7).
5. **Ensaio de lançamento** em staging: percurso completo visitante→questionário→resultado→criar conta→exportar→apagar.
6. **Gate de lançamento** (secção 9) revisto item a item antes de apontar tráfego.

---

## 11. Estado da via completa

Fechados: ✅ PRD · ✅ Wireframes/Fluxo · ✅ Modelo de Dados+API · ✅ Prompt LLM+Schema · ✅ Textos finais · ✅ Stack concreta · ✅ Critérios de Pronto+QA.

**A via completa está fechada.** O pacote de especificação está pronto para arrancar desenvolvimento (Fase 1 do PRD).

Detalhes menores a decidir na implementação (não bloqueiam o arranque): RankMath vs Yoast · Polylang vs multi-idioma nativo · gestão da chave Gemini (env var vs settings).
