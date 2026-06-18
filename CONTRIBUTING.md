# Contribuir — fluxo de branches e PRs

## Modelo de branches

| Branch | Papel | Deploy |
|---|---|---|
| **`main`** | **produção** — só código pronto para lançar (releases) | cPanel (produção) → `main` |
| **`develop`** | **integração / pré-produção** — onde tudo se junta e é validado | cPanel (staging) → `develop` |
| `feature/*`, `fix/*`, `claude/*` | trabalho individual | — |

## Fluxo normal (dia-a-dia)

```
feature/x ──PR──▶ develop ──(deploy staging, validar)──▶ PR ──▶ main ──(deploy produção)
```

1. Cria uma branch a partir de **`develop`**: `git checkout develop && git pull && git checkout -b feature/o-que-for`.
2. Trabalha, faz commits pequenos e descritivos.
3. Abre **PR com base em `develop`** (NÃO `main`).
4. Merge para `develop` → o staging recebe a alteração para validação.
5. Quando um conjunto está validado em staging, abre um **PR de release `develop` → `main`** e mergeia → produção faz deploy.

> **Regra:** PRs de funcionalidades apontam **sempre a `develop`**. Só os PRs de
> *release* (`develop` → `main`) tocam em produção.

## Tornar `develop` o destino por omissão dos PRs (uma vez)

No GitHub, define a **branch default** como `develop` para que os novos PRs e clones
a usem por omissão:

- **GitHub → o repositório → Settings → General → Default branch** → muda para `develop` → confirma.

(Opcional, recomendado) **Proteger `main`** para evitar pushes diretos a produção:

- **Settings → Branches → Add branch ruleset/protection** em `main`: exigir Pull Request
  antes de merge; (e em `develop` se quiseres revisão).

## Hotfix urgente em produção

Se for preciso corrigir produção sem esperar pelo ciclo:

```
main ──▶ hotfix/x ──PR──▶ main   (deploy produção)
                     └────▶ develop  (re-merge para não perder o fix)
```

Lembra-te de trazer o hotfix de volta a `develop` (merge ou cherry-pick).

## Antes de marcar um PR como pronto

- Testes do motor verdes: `for t in engine settings explainer prompt ratelimit cron mailer; do php wp-content/plugins/hti-engine/tests/test-$t.php; done`
- `php -l` sem erros; JS com `node --check`.
- Strings novas em **EN + PT**.
- Sem segredos no diff (chaves vão para o `wp-config.php` do servidor).
