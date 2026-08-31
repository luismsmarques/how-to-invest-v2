# Deploy via cPanel Git™ Version Control

Como o servidor de produção recebe automaticamente o **tema** (`howtoinvest`) e os
**cinco plugins** (`hti-engine`, `hti-rss-ai`, `hti-social`, `hti-forex`,
`hti-games`) a partir deste repositório. O core do WordPress, `wp-config.php` e
`uploads/` **não** estão no repo e nunca são tocados.

> A lista viva está na `.cpanel.yml` — um plugin que não tenha lá o par
> `rm -rf` + `cp -R` **não é enviado, e o deploy fica verde na mesma**.

> ⚠️ **Pré-requisito:** PHP **8.3+** ativo na conta (ver o ticket ao fornecedor).
> Em PHP 7.0 o plugin não arranca.

---

## 1. Estratégia de branches (recomendada)

| Branch | Para quê | Ambiente |
|---|---|---|
| feature/`claude/…` | trabalho do dia-a-dia | local |
| **`develop`** | integração / pré-produção | **staging** (subdomínio noindex+password) |
| **`main`** | só código pronto para produção (releases) | **produção** |

Fluxo: trabalha em branches → PR para `develop` (deploy a staging) → quando estiver
validado, merge `develop` → `main` (deploy a produção). Assim a produção só muda
em releases deliberados, não a cada commit.

> Cada ambiente do cPanel faz checkout da **sua** branch (staging→`develop`,
> produção→`main`). O `.cpanel.yml` é o mesmo nas duas; o destino é resolvido por
> ambiente (ver §3).

---

## 2. Configurar o repositório no cPanel (uma vez, por ambiente)

1. cPanel → **Git™ Version Control** → **Create**.
2. **Clone a Repository**: ON. **Clone URL:**
   `https://github.com/luismsmarques/how-to-invest-v2.git`
   (repo privado → usa um **GitHub Personal Access Token** no URL ou uma deploy key SSH).
3. **Repository Path:** ex. `~/repositories/how-to-invest-v2`.
4. Cria. Depois abre o repositório → **Manage** → separador **Pull or Deploy** →
   **Checked-Out Branch** = `main` (produção) ou `develop` (staging).

## 3. Definir o destino do deploy (por ambiente)

O destino está **dentro do `.cpanel.yml`**, na linha `export DEPLOYPATH=…`. Por
omissão (produção) aponta para `/home/howtoinvest/howtoinvest.pro/wp-content`.

Para um ambiente de **staging** (branch `develop`), edita essa **única linha** na
`.cpanel.yml` da branch `develop` para o `wp-content` do subdomínio de staging.
(Mantemos o ficheiro simples de propósito — o parser do cPanel é estrito e não
gosta de shell complexo.)

## 4. Primeiro deploy

No cPanel → Git → **Manage** → **Pull or Deploy** → **Update from Remote** e depois
**Deploy HEAD Commit**. Isto corre o `.cpanel.yml` (rsync do tema/plugin + `composer install` do Dompdf).

Depois, **uma vez**, no wp-admin: ativar o tema **HowToInvest** e o plugin
**HTI Engine**, configurar o **RankMath** e as chaves (**Gemini**, **Brevo**) em
*Definições → HowToInvest*, e correr **Ferramentas → Semear conteúdo**.

---

## 5. Tornar o deploy automático (on push)

O cPanel Git **não** faz pull sozinho a cada push — precisa de um gatilho. Opção
robusta e simples: um **cron job** (cPanel → Cron Jobs) que puxa e faz deploy.

A cada 5 minutos (produção, branch `main`):

```bash
cd $HOME/repositories/how-to-invest-v2 \
  && /usr/local/cpanel/3rdparty/bin/git pull origin main \
  && /usr/local/bin/uapi --user=$USER VersionControlDeployment create \
       repository_root=$HOME/repositories/how-to-invest-v2 >> $HOME/hti-deploy.log 2>&1
```

- `git pull` traz os novos commits; o `uapi … VersionControlDeployment create`
  dispara o `.cpanel.yml`.
- Se o `uapi` não estiver disponível na tua conta, substitui a 2ª/3ª linha por um
  rsync direto (mesmas tarefas do `.cpanel.yml`):

```bash
cd $HOME/repositories/how-to-invest-v2 && git pull origin main \
  && rsync -a --delete --exclude 'vendor/' wp-content/plugins/hti-engine/ $HOME/public_html/wp-content/plugins/hti-engine/ \
  && rsync -a --delete wp-content/themes/howtoinvest/ $HOME/public_html/wp-content/themes/howtoinvest/ \
  && cd $HOME/public_html/wp-content/plugins/hti-engine && composer install --no-dev --optimize-autoloader
```

> Alternativa "instantânea" (em vez de cron): um **webhook do GitHub** → endpoint
> que executa o pull. O cPanel não traz recetor de webhooks de origem, por isso
> isto exige um pequeno script PHP protegido por segredo. O cron de 5 min é mais
> simples e suficiente.

---

## 5.1 Troubleshooting — deploy "queued" eternamente

O cPanel serializa os deploys; um passo que não termina deixa tudo *queued*. O
suspeito habitual é o `composer install` (rede lenta/sem saída). O `.cpanel.yml`
já limita o composer com `timeout` e `--no-interaction`, mas se ficares preso:

**Destrava fazendo o deploy à mão no Terminal** (não passa pela fila):

```bash
cd ~/repositories/how-to-invest-v2 && git fetch origin && git reset --hard origin/main
WPCONTENT="$HOME/howtoinvest.pro/wp-content"   # ajusta ao teu docroot
mkdir -p "$WPCONTENT/plugins" "$WPCONTENT/themes"
rm -rf "$WPCONTENT/plugins/hti-engine"  && cp -a wp-content/plugins/hti-engine "$WPCONTENT/plugins/"
rm -rf "$WPCONTENT/themes/howtoinvest"  && cp -a wp-content/themes/howtoinvest "$WPCONTENT/themes/"
cd "$WPCONTENT/plugins/hti-engine" && composer install --no-dev --no-interaction --no-progress || true
```

- O Dompdf (PDF) é opcional — se o composer pendurar, `Ctrl+C`: o site funciona
  na mesma (PDF cai para HTML imprimível).
- Log dos deploys do cPanel: `~/.cpanel/logs/` (ou o painel mostra-o).

## 5.2 Tirar o WP-Cron dos pedidos dos visitantes — obrigatório

Por omissão o WordPress não tem cron nenhum: corre as tarefas agendadas **em cima
do pedido de um visitante**. Enquanto uma tarefa lenta decorre, cada visita nova
gera outro `wp-cron.php`; o bloqueio interno do WordPress expira aos 60 segundos,
por isso um trabalho mais demorado do que isso deixa entrar a corrida seguinte, e
a seguinte.

Num alojamento partilhado isto esgota o limite de processos em simultâneo da
conta. Foi o que aconteceu a 30 ago 2026: **1149 faltas de entry processes em 24
horas** com o tecto em 20, CPU limitado, e o Telegram a receber **508** do
webhook do bot — que é a página de limite de recursos do LiteSpeed. Cada `/start`
que apanha um 508 é um clique pago que não deu utilizador.

**1. No `wp-config.php` do servidor**, antes do `/* That's all, stop editing! */`:

```php
define( 'DISABLE_WP_CRON', true );
```

**2. Em cPanel → Cron Jobs**, de 5 em 5 minutos (`*/5 * * * *`):

```
cd /home/howtoinvest/howtoinvest.pro && /usr/local/bin/php wp-cron.php >/dev/null 2>&1
```

Passa a haver **um** processo, uma vez, em vez de um por visitante. As tarefas
agendadas continuam a correr na mesma — só deixam de competir com quem está a ler
o site.

**Como confirmar:** cPanel → Resource Usage no dia seguinte. Os picos de *entry
processes* têm de desaparecer. E em Definições → HTI Forex, o "Last delivery
error" do bot tem de deixar de acumular.

## 6. Notas

- **Nunca** versionar `wp-config.php` nem chaves — usa `define()` no `wp-config.php`
  do servidor (`HTI_GEMINI_API_KEY`, `HTI_BREVO_API_KEY`, `HTI_TELEGRAM_BOT_TOKEN`).
- **Bot de Telegram.** Depois do primeiro deploy que traz o bot: põe o token no
  `wp-config.php` e carrega em **Registar webhook** em Definições → HTI Forex.
  O Telegram só admite **um webhook por bot** — apontar o staging ao bot real
  rouba-lhe as mensagens sem avisar. Usa um segundo bot de teste no staging.
- `vendor/` (Dompdf) **não** está no repo; o `composer install` do deploy é que o
  cria. ⚠️ O `.cpanel.yml` faz `rm -rf` + `cp -R`, portanto **destrói o `vendor/`
  em cada deploy** e depende do `composer install` da última linha, que está
  protegida por `timeout 180` e `|| true`: se falhar, o deploy fica verde e o
  **export PDF degrada em silêncio para HTML**. Confirma o PDF depois de um deploy.
- Testa sempre em **staging** (`develop`) antes de promover para `main`.
- **Limpa a cache depois de cada deploy, por esta ordem.** A stack é WP Fastest
  Cache no servidor e Cloudflare à frente:
  1. **WP Fastest Cache → Delete Cache**, e usa **"Delete Cache and Minified
     CSS/JS"**, não o botão simples. O nosso CSS e JS são servidos com `?ver=`,
     por isso um browser normal pega logo nos ficheiros novos — mas se o
     minify/combine estiver ligado, o ficheiro combinado já não leva esse
     `?ver=` e fica com o conteúdo antigo lá dentro. É a causa clássica de
     "limpei a cache e continua com o aspeto velho".
  2. **Cloudflare → Caching → Configuration → Purge Everything**, e só depois do
     passo 1: ao contrário, o Cloudflare vai buscar as páginas ao servidor e
     recebe as antigas, ficando com lixo novo na borda.

  O passo 2 só é preciso se tiveres **APO** ou uma Cache Rule com *Cache
  Everything* — por omissão o Cloudflare não guarda HTML, só estáticos. Para
  saber, `curl -sI` a uma página e ver o `cf-cache-status`: se der `MISS` ou
  `DYNAMIC` numa página normal, o HTML não está a ser cacheado na borda.
  Para testar sem lutar contra a cache, liga o **Development Mode** do
  Cloudflare (bypass de 3 horas).

### Migração única: as calculadoras passam a viver sob o hub

A partir da versão 0.14.0 do `hti-engine` as oito calculadoras são páginas-filhas
do hub (`/tools/{ferramenta}/` e `/pt/ferramentas/{ferramenta}/`), como no
`/forex/`. Num site já publicado isso é uma alteração de dados, não de conteúdo,
por isso **não** corre sozinha em nenhum deploy — nem pelo `Content_Sync`, para
não haver um cron a re-parentar dezasseis páginas em silêncio.

Depois do primeiro deploy desta versão, correr uma vez no servidor:

```bash
wp hti tools-migrate --dry-run   # mostra o que ia mudar, sem escrever
wp hti tools-migrate             # aplica
```

Sem WP-CLI no servidor, o mesmo se faz no wp-admin em
**Ferramentas → Semear conteúdo** (`/wp-admin/tools.php?page=hti-seed`): o botão
corre o `Seeder::seed()` completo, que inclui esta migração, e o aviso no fim
lista o que foi movido — a amarelo se alguma página tiver sido saltada ou tiver
mudado de slug.

É idempotente — se correr duas vezes, a segunda não faz nada. **A ordem não
importa:** os 301 dos URLs antigos só disparam quando a página de destino já
existe, por isso entre o deploy e a migração as calculadoras continuam a ser
servidas nos URLs antigos, e passam a redirecionar sozinhas assim que a migração
correr. Se o relatório imprimir uma linha `WARNING … slug changed on re-parent`,
essa página ficou com outro slug e o 301 aponta para o antigo: corrigir o slug à
mão antes de seguir.
