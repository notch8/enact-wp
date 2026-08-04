# WordPress for Enact

A plain vanilla WordPress site (official `wordpress` + `mysql` Docker images)
running on the `enact` Kubernetes cluster, for internal use.

Two independent environments, each its own namespace with its own MySQL instance:

| Environment | Namespace              | URL                                        |
|-------------|------------------------|---------------------------------------------|
| staging     | `enact-wp-staging`     | https://enact-wp-staging.enacthyku.com       |
| production  | `enact-wp-production`  | https://enact-wp-production.enacthyku.com    |

## Access

Both URLs are on public DNS (proxied through Cloudflare, so `bot_fight_mode` and
the WAF still apply), but the site itself is private: every front-end page
requires a real WordPress login. This is done by a one-file mu-plugin
(`wordpress/templates/configmap-private-site.yaml`, controlled by the
`privateSite.enabled` value, `true` by default) that hooks WordPress's own
`auth_redirect()` -- the same function wp-admin uses -- into every page load.
Nothing renders for anonymous visitors, so bots/scrapers get nothing to crawl,
and there's no dependency on Cloudflare Access or any other external service.

The very first time you open a given environment, you'll land on WordPress's
normal 5-minute install wizard (it's unaffected by the login gate) -- pick a
site title and an admin username/password there. Save that admin login in
1Password; nothing about it is templated or stored by this repo.

## Deploying
### Ongoing deployment
Trigger the **Deploy** workflow from the Actions tab, choose `staging` or
`production`.

### One-time setup, before the first deploy of a new environment: the
`enact-wp-staging` / `enact-wp-production` namespaces must exist before
`notch8-ops`'s `github-deploy-sa` Terraform can create the `github-deploy`
ServiceAccount inside them (that module deliberately doesn't create
namespaces itself -- see `terraform/modules/github-deploy-sa/README.md` in
`notch8-ops`). Create them by hand once:

```bash
kubectl --context enact create namespace enact-wp-staging
kubectl --context enact create namespace enact-wp-production
```

After that, `notch8-ops`'s `./bin/tf-cosector enact apply` (which provisions
the `github-deploy` SA/RBAC/token) and
`./scripts/push-github-deploy-kubeconfig.sh --cluster enact` (which pushes the
resulting `KUBECONFIG_FILE` secret here) only need to run once per namespace.

### Making a site public

Your boss (or anyone with deploy access) can lift the login requirement for an
environment independently of anything above -- it's just a Helm value:

1. In `ops/staging-values.yaml` or `ops/production-values.yaml`, add:
   ```yaml
   privateSite:
     enabled: false
   ```
2. Re-run the **Deploy** workflow for that environment (or
   `./bin/helm_deploy enact-wp-staging enact-wp-staging` locally with
   `HELM_EXTRA_ARGS="--values ops/staging-values.yaml"`).

That's it -- no plugin to uninstall, no file to delete. Setting
`privateSite.enabled` back to `true` and redeploying restores the login wall.

## Layout

- `wordpress/` -- the Helm chart. WordPress is the parent chart; `wordpress/charts/mysql/`
  is a nested subchart for its database. One `helm install` deploys both.
- `ops/staging-values.yaml`, `ops/production-values.yaml` -- the only
  per-environment differences (hostname, which 1Password item backs the DB
  credentials). Everything else is shared via `wordpress/values.yaml`.
- `bin/helm_deploy RELEASE_NAME NAMESPACE` -- thin wrapper around
  `helm upgrade --install` used by both local and CI deploys.
- `.github/workflows/deploy.yaml` -- `workflow_dispatch` deploy (pick
  `staging` or `production`).
- `.github/workflows/lint.yaml` -- runs `helm lint`/`helm template` against
  both environments' values on every push/PR to `main`.

## Secrets

Database credentials never touch GitHub. Each environment's `ExternalSecret`
(`wordpress/templates/externalsecret.yaml`) pulls from a 1Password item via the
`enact` cluster's existing `onepassword` ClusterSecretStore:

- `wordpress-mysql-staging-enact`
- `wordpress-mysql-production-enact`

Each item needs `root-password` and `wordpress-password` fields. The resulting
Kubernetes Secret is mounted directly into both the WordPress and MySQL pods --
no duplication into GitHub Actions secrets.

The only thing GitHub Actions holds is `KUBECONFIG_FILE` per environment, a
namespace-scoped `github-deploy` service account token (see `notch8-ops`'s
`terraform/modules/github-deploy-sa`).


## Versions

Both images use floating tags (`wordpress:latest`, `mysql:lts`) rather than a
pinned patch version -- this is the normal way to run these official images and
keeps the deployment on the current stable release without manual tag bumps.
WordPress's own dashboard handles in-app core updates independently of the
container tag regardless.
