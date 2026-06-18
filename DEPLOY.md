# Deploy via cPanel Git™ Version Control

Como o servidor de produção recebe automaticamente o **tema** (`howtoinvest`) e o
**plugin** (`hti-engine`) a partir deste repositório. O core do WordPress,
`wp-config.php` e `uploads/` **não** estão no repo e nunca são tocados.

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

Por omissão o `.cpanel.yml` instala em `~/public_html/wp-content`. Se o WordPress
desse ambiente estiver noutro sítio (ex.: staging num subdomínio), cria **um
ficheiro** com o caminho do `wp-content` desse ambiente:

```bash
echo "$HOME/staging.howtoinvest.pro/wp-content" > $HOME/.hti-deploypath
```

(Na produção, se o WP está em `public_html`, não precisas de fazer nada.)

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

## 6. Notas

- **Nunca** versionar `wp-config.php` nem chaves — usa `define()` no `wp-config.php`
  do servidor (`HTI_GEMINI_API_KEY`, `HTI_BREVO_API_KEY`).
- `vendor/` (Dompdf) **não** está no repo; o `composer install` do deploy é que o
  cria. O rsync preserva-o entre deploys (`--exclude vendor/`).
- Testa sempre em **staging** (`develop`) antes de promover para `main`.
- Limpa a cache (LiteSpeed/WP) depois de cada deploy se usares cache de página.
