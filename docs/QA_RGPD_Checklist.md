# Checklist de QA — RGPD (Exportar & Apagar conta)

Guião **manual e repetível** para validar em **staging** o fluxo de direitos do
titular (RGPD, P0) antes de lançar. Complementa os testes unitários automáticos
(`wp-content/plugins/hti-engine/tests/test-account-gdpr.php`, que cobrem a lógica
do grace/token/log mas **não** a cascade real na base de dados).

> Correr sempre em **staging** (subdomínio noindex + password), nunca em produção.
> Usar uma conta de teste descartável. Alguns passos usam **WP-CLI** (`wp ...`) por
> SSH; onde não houver CLI, seguem-se alternativas manuais.

Referências de código: `includes/class-rest.php` (`/export` ~l.339, `/account`
DELETE ~l.350, `check_auth`), `includes/class-account.php`
(`schedule_deletion`, `run_due_deletions`, `hard_delete`, meta `hti_delete_at`,
hook `hti_account_deletions`), `includes/class-consent.php` (cookie `hti_consent`).

---

## 1. Preparação

- [ ] Abrir o site de staging e fazer o **questionário** até ao resultado.
- [ ] No resultado, **"Guardar o meu perfil"** → registar/entrar (isto liga o perfil
      anónimo à conta via `claim-profile`).
- [ ] Repetir o questionário e guardar um **2.º perfil** (para confirmar que o
      export/delete lida com vários).
- [ ] Confirmar que a página **`/my-account/`** (`[hti_account]`) lista os perfis.

## 2. Exportar os meus dados (`GET htinvest/v1/export`)

- [ ] Em **A minha conta**, clicar **"Exportar"**.
- [ ] Confirma que o **ficheiro descarrega** (cabeçalho `Content-Disposition: attachment`).
- [ ] Abrir o JSON e verificar que inclui: **conta** (email), **perfis** guardados
      (arquétipo + alocação), **preferências** e **progresso do Learn**.
- [ ] Verificar que **não** aparecem dados de **outro** utilizador nem PII de terceiros.
- [ ] Confirmar que dispara o evento de métricas `data_export` (opcional — painel
      *Definições → HTI Funnel*).

## 3. Apagar conta — agendamento (30 dias de tolerância)

- [ ] Em **A minha conta**, clicar **"Apagar conta"** e **confirmar**.
- [ ] Recebes um **email** a indicar a **data de eliminação (~30 dias)** e um **link
      de cancelamento**.
- [ ] Verificar a meta do utilizador: `wp user meta get <id> hti_delete_at`
      → um timestamp ~30 dias no futuro. (Sem CLI: confirmar pelo email.)
- [ ] **Durante a tolerância a conta continua a funcionar** — entrar, ver perfis,
      exportar ainda funciona.

## 4. Apagar conta — cancelar

- [ ] Abrir o **link de cancelamento** do email (`?hti_cancel_delete=…&u=<id>`).
- [ ] És redirecionado para `/my-account/?delete_cancelled=1`; o agendamento é removido.
- [ ] Confirmar: `wp user meta get <id> hti_delete_at` → **vazio**.
- [ ] **Segurança:** adulterar o token no link (mudar 1 caractere) → redireciona
      para `?delete_error=1` e **não** cancela nada.

## 5. Apagar conta — execução real (a cascade)

> Para não esperar 30 dias, forçar a data para o passado e correr o cron.

- [ ] Reagendar a eliminação (passo 3) e depois:
      `wp user meta update <id> hti_delete_at 1` (timestamp no passado).
- [ ] Correr o cron: `wp cron event run hti_account_deletions`
      (ou testar a cascade diretamente: `wp eval 'HTI\\Engine\\Account::hard_delete(<id>);'`).
- [ ] Verificar que **tudo** desapareceu:
  - [ ] Utilizador eliminado: `wp user get <id>` → erro (não existe).
  - [ ] Perfis apagados: `wp post list --post_type=htinvest_profile --author=<id>` → vazio.
  - [ ] Log de perguntas e NPS limpos (a opção `rssai`/`hti` do log já não contém o `uid`).
  - [ ] **Brevo** (se configurado): o contacto foi removido da lista/apagado.

## 6. Segurança & autorização

- [ ] Chamar `GET htinvest/v1/export` **sem sessão/nonce** → **rejeitado** (401/403).
- [ ] Chamar `DELETE htinvest/v1/account` **sem `confirm:true`** → **rejeitado**.
- [ ] **Export anónimo:** um resultado anónimo (sem conta) só é exportável com o
      **token de sessão** correto; um token errado é recusado.
- [ ] **Isolamento:** autenticado como utilizador A, tentar exportar/apagar dados
      do utilizador B → **negado**.

## 7. Consentimento & páginas legais

- [ ] Primeira visita: o **banner de consentimento** aparece; **rejeitar** não-essencial
      → o Google Analytics **não** carrega (cookie `hti_consent` sem analytics; sem
      pedidos a `google-analytics`/`gtag`).
- [ ] **Aceitar** → o GA passa a carregar; a escolha persiste no cookie `hti_consent`.
- [ ] **Se o Consent Mode v2 estiver ligado** (Definições → Analytics; desligado por
      omissão): o gtag carrega logo, por isso o que se verifica é outro — antes do
      aceite **não existe nenhum cookie `_ga`** e o `dataLayer` tem um
      `consent default` com `ad_storage`, `ad_user_data`, `ad_personalization` e
      `analytics_storage` a `denied`; depois do aceite chega um `consent update` que
      concede **apenas** `analytics_storage`. Requisito prévio: a política de
      privacidade tem de nomear o Google Analytics.
- [ ] A **Política de Privacidade** está ligada **no banner** e **no footer**, e abre.
- [ ] Os **Termos** abrem a partir do footer.

---

## 8. Jogos (`hti-games`) — o que a suite estática **não** consegue verificar

`wp-content/plugins/hti-games/tests/test-security.php` lê o plugin como texto e
prova o que é provável sem WordPress: cada rota declara um `permission_callback`,
cada chave do rate limiter existe, nenhum `$wpdb` sem `prepare`, nenhum `echo`
por escapar no admin, o `uninstall.php` nomeia todas as tabelas e opções. O que
**só** se prova em staging, com base de dados e caixa de correio reais, é isto.

Referências: `includes/class-rest.php` (as nove rotas), `class-privacy.php`
(export/erase/prune), `class-auth.php` (magic link), `class-player.php` (cookie
`hti_gp`), `class-store.php` (tabelas `hti_games_players` / `hti_games_runs`).

### 8.1 Autorização real das rotas REST

- [ ] `POST htinvest/v1/games/session` **sem** cabeçalho `X-WP-Nonce` → **403**.
      Repetir para `/games/{game}/today`, `/decision`, `/leaderboard`, `/profile`,
      `/nickname`, `/link` e `DELETE /games/me`.
- [ ] `POST htinvest/v1/games/claim` **sem sessão iniciada** → **401**
      (é a única rota com `check_auth`).
- [ ] **Isolamento da erasure:** como jogador A, chamar `DELETE /games/me` com o
      cookie `hti_gp` de A mas com o cabeçalho `X-HTI-Player` de B → apaga **A**,
      nunca B. (O cookie ganha; o cabeçalho só é lido quando não há cookie.)
- [ ] Com o cookie de B e sem cookie próprio, A **não** consegue apagar B a não
      ser possuindo o uuid de B — que é o mesmo que ser B. Confirmar que não há
      parâmetro `player`/`id`/`uuid` aceite por esta rota.
- [ ] Passar dos limites por IP (ex.: 11 decisões em 10 min) → **429** com a
      mensagem da tabela `Strings`, e **nada** foi gravado.

### 8.2 A cascade real na base de dados

- [ ] Jogar os **dois** jogos com uma conta de teste (`/games/survive-the-charts/`
      e `/games/the-reveal/`), escolher um **nickname** e confirmar que aparece
      em `/games/leaderboard/`.
- [ ] Antes de apagar, contar as linhas:
      `wp db query "SELECT COUNT(*) FROM wp_hti_games_players WHERE user_id=<id>"`
      e `wp db query "SELECT COUNT(*) FROM wp_hti_games_runs r JOIN wp_hti_games_players p ON p.id=r.player_id WHERE p.user_id=<id>"`.
- [ ] Correr a eliminação da secção 5 (`Account::hard_delete(<id>)`) e repetir as
      duas contagens → **0 e 0**. É o `hti_account_hard_delete` a disparar; se
      ficar linha, o hook não correu.
- [ ] Confirmar que a **user meta do magic link** desapareceu:
      `wp user meta get <id> hti_games_link_token` → erro/vazio.
- [ ] O nickname **saiu do leaderboard** (esperar 60 s ou
      `wp transient delete --all`, porque o board tem cache de um minuto).
- [ ] **Duas linhas para uma conta** (o caso raro que `erase_user()` passou a
      cobrir): inserir à mão uma segunda linha com o mesmo `user_id`
      (`wp db query "INSERT INTO wp_hti_games_players (uuid,user_id,lang,created_at,last_seen) VALUES (UUID(),<id>,'en',NOW(),NOW())"`),
      correr a eliminação e confirmar que **ambas** desapareceram.
- [ ] **Export:** `GET htinvest/v1/export` autenticado traz a secção `games` com
      `player`, `acknowledgement` (`confirmed_at` + `text_version`) e **todas** as
      `runs` — o mesmo número que a contagem acima.

### 8.3 O magic link numa caixa de correio real

> Testar com **três** endereços em provedores diferentes: um Gmail, um Outlook/
> Microsoft 365 (Safe Links) e um domínio com Proofpoint ou Mimecast, se houver.
> É aqui que os links de sessão única costumam morrer, e não se reproduz local.

- [ ] Pedir o link em `/games/profile/`; o email chega com o botão **Entrar** e o
      URL alternativo em texto.
- [ ] **Não clicar durante 2 minutos.** Depois clicar: **entra**. Se der erro, o
      scanner do provedor gastou o token — registar qual provedor.
- [ ] Clicar **outra vez** no mesmo link → `?link_error=1`. O token é de uso único.
- [ ] Pedir um segundo link e clicar no **primeiro** → recusado; só o mais recente
      funciona.
- [ ] Esperar **>15 min** e clicar → recusado (expirou).
- [ ] Adulterar um caractere do token → `?link_error=1`, **sem** dizer se o email
      existe.
- [ ] **Prefetch:** `curl -I '<url do link>'` (HEAD) e
      `curl -H 'Sec-Purpose: prefetch' '<url do link>'` → nenhum dos dois entra
      **e o link continua a funcionar** a seguir, no browser.
- [ ] **Enumeração:** pedir link para um endereço **com** conta e para um **sem**
      conta → resposta HTTP idêntica (200, mesmo corpo). Anotar também os tempos:
      a primeira vez que um endereço novo é usado cria a conta e é mensuravelmente
      mais lenta — risco aceite e documentado em `class-auth.php`.
- [ ] Depois de entrar, o progresso anónimo **ficou ligado** à conta e o cookie
      `hti_gp` aponta para a linha sobrevivente.

### 8.4 Interruptores, retenção e o resto

- [ ] *Definições → HTI Games*: desligar **cada** interruptor e confirmar que a
      **API** também fecha, não só a página:
      `stc_enabled`/`reveal_enabled` → `/games/{game}/today` e `/decision` dão
      **503**; `leaderboard_enabled` → `/games/leaderboard` dá **503**;
      `email_link_enabled` → `/games/link` dá **503** e **nenhum** utilizador
      WordPress é criado; `newsletter_optin` → um `POST /games/link` com
      `newsletter:true` **não** cria contacto no Brevo.
- [ ] **Retenção:** pôr *Manter corridas durante* nos 30 dias, envelhecer uma
      linha anónima
      (`wp db query "UPDATE wp_hti_games_players SET last_seen='2020-01-01 00:00:00' WHERE user_id=0 LIMIT 1"`),
      correr `wp cron event run hti_prune_profiles` e confirmar que a linha e as
      suas runs desapareceram — e que **nenhuma** linha com `user_id > 0` foi
      tocada.
- [ ] **Sem conta:** jogar em anónimo, carregar em **"Esquecer-me"** no perfil →
      as duas tabelas ficam sem a linha, o cookie `hti_gp` é apagado, e recarregar
      a página começa do zero.
- [ ] **Nada de PII nas tabelas:** `wp db query "DESCRIBE wp_hti_games_players"` →
      nenhuma coluna de email, IP ou user agent.
- [ ] **Cookie:** nas ferramentas do browser, `hti_gp` tem `HttpOnly`, `Secure` e
      `SameSite=Lax`, e **só** é escrito depois do onboarding — carregar
      `/games/` sem completar o onboarding não deixa cookie nenhum.
- [ ] **Nickname no board:** gravar um nickname pelo limite (24 caracteres) e
      confirmar que a página do leaderboard e o cartão de partilha o mostram como
      texto, sem HTML. Tentar `<script>` pela API → **422**.
- [ ] **Board por dia:** `GET /games/leaderboard?day=1999-01-01` devolve o board
      de **hoje** (a janela é de 30 dias) e **não** cria transients novos —
      confirmar com `wp db query "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '_transient_hti_games_lb_%'"` antes e depois.

---

**Resultado:** todos os itens `[ ]` verificados = fluxo RGPD validado ponta-a-ponta
em staging. Registar data/versão e quem validou.
