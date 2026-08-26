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
- [ ] A **Política de Privacidade** está ligada **no banner** e **no footer**, e abre.
- [ ] Os **Termos** abrem a partir do footer.

---

**Resultado:** todos os itens `[ ]` verificados = fluxo RGPD validado ponta-a-ponta
em staging. Registar data/versão e quem validou.
