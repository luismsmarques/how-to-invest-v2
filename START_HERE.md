# START HERE — Arranque do desenvolvimento

> **Para o Claude Code:** este é o teu ponto de partida. Lê este ficheiro primeiro, depois `CLAUDE.md`, depois `/docs` conforme necessário. Não comeces a escrever código antes de leres `CLAUDE.md` e o documento de spec relevante para a tarefa.

## O que é este repositório

Especificação completa + esqueleto do **HowToInvest v2**: reconstrução em WordPress (full-WP) de uma plataforma educativa de perfis de investimento. O objetivo de negócio é **SEO/conteúdo**. Toda a visão, requisitos e regras estão em `/docs` (7 documentos). O mapa está no `README.md`. As regras de ouro estão no `CLAUDE.md`.

## Estado atual

- ✅ Especificação completa (7 documentos em `/docs`)
- ✅ Skills de desenvolvimento (`.claude/skills/`)
- ✅ Estrutura de pastas (tema + plugin, ainda vazios)
- ⬜ Código: **nada construído ainda** — começamos pela Fase 1

## Ordem de leitura (primeira sessão)

1. `CLAUDE.md` — regras de ouro, stack, invariantes, convenções. **Obrigatório.**
2. `README.md` — mapa do projeto.
3. `docs/PRD_HowToInvest_WordPress_MVP.md` — visão e requisitos (lê pelo menos §0, §6, §9, §11).
4. O documento de spec específico da tarefa que vais fazer.

## Plano de Fase 1 (Fundação SEO) — começa aqui

A Fase 1 **não depende do motor** e já gera valor (indexação). Tarefas por ordem:

### 1.1 — Ambiente
- [ ] Confirmar no cPanel: PHP 8.4 (mín 8.3), MySQL 8.0+/MariaDB 10.6+, HTTPS (AutoSSL).
- [ ] Instalar WordPress 7.0+ no domínio.
- [ ] Criar staging (subdomínio, noindex, password).
- [ ] Ajustar php.ini conforme `docs/Stack_Concreta… §1`.
→ skill: `wordpress-backend`

### 1.2 — Tema
- [ ] Criar child block theme `howtoinvest` a partir de tema base oficial atual.
- [ ] Definir `theme.json` com os tokens de design (cor/tipografia/espaçamento).
- [ ] Templates FSE: home, single (artigo), archive, page, glossário (termo), notícia.
- [ ] Footer com disclaimer completo (Textos Finais §1.3, EN+PT).
→ skills: `wordpress-theme`, `ux-ui-design`

### 1.3 — Estrutura de conteúdo
- [ ] CPT `glossary` (público, indexável) e CPT `news` (público, indexável).
- [ ] Instalar e configurar plugin SEO (RankMath OU Yoast — escolher um).
- [ ] Schema por tipo de página; sitemap; metas editáveis.
- [ ] Bloco/pattern "CTA para o questionário" inserível em artigos.
→ skills: `wordpress-backend`, `seo-wordpress`

### 1.4 — Migração SEO
- [ ] Mapear e implementar 301s dos URLs antigos do Base44 (`/Questionnaire`, `/EducationalResources`, `/FinancialNews`, etc.).
- [ ] Verificar cada um responde 301.
→ skill: `seo-wordpress`

### 1.5 — Conteúdo seed
- [ ] 5–10 artigos educativos.
- [ ] Termos de glossário iniciais (reutilizar as notas por classe de `Textos Finais §2`).
→ skill: `seo-wordpress`

**DoD da Fase 1:** site live com conteúdo indexável, 301s no lugar, sitemap submetido ao Search Console, tema responsivo e acessível. Ver `docs/Criterios_Pronto_QA… §6`.

## Fases seguintes (resumo — detalhe no PRD §9)

- **Fase 2 — Core interativo:** plugin `hti-engine` (motor determinístico + Gemini + questionário + resultado). Skills: `hti-engine-spec`, `wordpress-backend`, `frontend-vanilla`, `php-standards`.
- **Fase 3 — Conta + RGPD + PDF:** registo/login/Google, claim-profile, export/delete, export PDF, consentimento. Skills: `gdpr-data`, `wordpress-backend`.
- **Fase 4 — Lançamento:** Search Console, analítica, monitorização, gate de lançamento. Skill: `seo-wordpress` + `docs/Criterios_Pronto_QA… §9`.

## Regras que nunca se quebram (resumo — ver CLAUDE.md)

1. Regras decidem, LLM só explica.
2. Output só por classes de ativos, nunca instrumentos.
3. Disclaimer em todos os resultados.
4. Sem conta → só dados anónimos.
5. Export/delete RGPD são P0.
6. Chave Gemini nunca no cliente.
7. Toda string em EN + PT.

## Primeiro pedido sugerido ao Claude Code

> "Lê o CLAUDE.md e o START_HERE.md. Vamos começar a Fase 1, tarefa 1.2: cria o child block theme `howtoinvest` com o theme.json inicial baseado nos tokens de design, e os templates FSE base. Segue a skill wordpress-theme."
