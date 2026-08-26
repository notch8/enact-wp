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

## Local development

Mirror of the k8s deployment (same official images) via docker compose:

```bash
docker compose up -d            # WordPress on http://localhost:8080, MySQL alongside
./bin/wp core install --url=http://localhost:8080 --title="Enact" \
    --admin_user=admin --admin_password=<pick-one> --admin_email=<you>@notch8.com --skip-email
./bin/wp rewrite structure '/%postname%/'
./bin/wp user application-password create admin local --porcelain   # feed to bin/provision
./bin/provision --base-url http://localhost:8080 --user admin --app-password '<from above>'
```

`./bin/wp <anything>` runs wp-cli against the local instance. The compose file
sets `WP_ENVIRONMENT_TYPE=local` so application passwords work over plain http;
that setting is intentionally absent from the k8s deployment, which is https.

## Site content as code

The `content/` directory is the seed for the whole site: block-markup HTML for
every page and post, curated media (with alt text), self-hosted web fonts, the
navigation tree, header/footer and news-listing template overrides, global
styles, and site settings, all declared in `content/site.json`. `bin/provision`
applies it over the WordPress REST API and is idempotent: items are matched by
slug and updated in place, so it can be re-run after content edits without
duplicating anything.

`bin/provision --only SECTION[,SECTION...]` applies a subset of the seed
(sections: media, fonts, pages, posts, navigation, template-parts, templates,
global-styles, site-branding, settings, cleanup). This is how changes reach an
environment where editors already own the content — e.g. the brand rollout is
`--only media,fonts,template-parts,global-styles,site-branding`, which never
touches pages, posts or navigation. See `docs/branding.md` for the brand
implementation (palette, logo renders, IBM Plex Sans with the FF Real swap
plan) and `bin/hero-variant` for the two approved home-hero looks.

Two provisioning caveats. First, it is a seed, not a sync: once real editors
own the site in wp-admin, re-running `bin/provision` will overwrite their
versions of anything it manages, so from that point treat `content/` as the
record of how the site was born, not as the source of truth. Second, it never
touches credentials: passwords printed by the scripts go to 1Password and
nowhere else.

## Bootstrapping a new environment

For a fresh k8s environment (production was set up this way; a wiped staging
would be too), no kubectl is needed -- everything runs over https:

```bash
bin/remote-bootstrap --base-url https://enact-wp-production.enacthyku.com \
    --title Enact --admin-user notch8-admin --admin-email nick@notch8.com
# prints the generated admin password and a REST application password
bin/provision --base-url https://enact-wp-production.enacthyku.com \
    --user notch8-admin --app-password '<printed above>' --testers
```

`remote-bootstrap` runs the install wizard, sets pretty permalinks, and mints
an application password. `--testers` also creates the reviewer accounts listed
in `content/site.json` with fresh random passwords printed to stdout. Save
everything printed in 1Password immediately; nothing is stored.

## Pipeline

Every PR against `main` runs the **Lint** workflow, which is really a suite of
Helm chart checks shared with **Deploy** via `.github/actions/helm-checks` so
both stay in sync:

- `helm lint` and `helm template`, against both `ops/staging-values.yaml` and
  `ops/production-values.yaml`
- [kubeconform](https://github.com/yannh/kubeconform) -- validates the
  rendered manifests against the real Kubernetes API schema (catches
  typos/structural mistakes `helm lint`/`helm template` won't, e.g. a wrong
  field name or `apiVersion`)
- [helm-unittest](https://github.com/helm-unittest/helm-unittest) -- unit
  tests in `wordpress/tests/`, e.g. asserting that `privateSite.enabled: false`
  actually removes the mu-plugin ConfigMap and its volume mount, and that the
  `required()` guards on `ingress.hostname` / `externalSecret.onePasswordItem`
  fire correctly
- A [Trivy](https://github.com/aquasecurity/trivy) config scan of the rendered
  manifests -- report-only (doesn't fail the build), since the official
  `wordpress`/`mysql` images run parts of their entrypoint as root before
  dropping privilege, which a hard-fail policy would otherwise block on

**Merging to `main` runs those same checks again, then auto-deploys
staging** -- `deploy.yaml`'s `push: branches: [main]` trigger, gated by the
same `checks` job. Production is never auto-deployed; promoting to it is
always a deliberate action.

## Deploying
### Ongoing deployment
Staging deploys itself on every merge to `main`, once checks pass. For a
manual deploy of either environment (including production), trigger the
**Deploy** workflow from the Actions tab and choose `staging` or
`production` -- same as before, unaffected by the auto-deploy trigger. There's
also an optional tmate-debug toggle there for troubleshooting a stuck deploy.

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

1. In `ops/staging-values.yaml` or `ops/production-values.yaml`, change
   `privateSite.enabled` to `false`:
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
- `content/` -- the site seed (pages, posts, media, navigation, styles,
  settings) declared in `content/site.json`; see "Site content as code".
- `bin/provision` -- applies `content/` to any environment over the REST API.
- `bin/remote-bootstrap` -- one-time install wizard + permalinks + application
  password for a fresh remote environment.
- `bin/wp` -- wp-cli against the local docker compose instance.
- `docker-compose.yml` -- local development mirror of the deployment.
- `bin/helm_deploy RELEASE_NAME NAMESPACE` -- thin wrapper around
  `helm upgrade --install` used by both local and CI deploys. `NAMESPACE`
  must already exist (see one-time setup below).
- `.github/actions/helm-checks/` -- composite action with the actual chart
  checks (lint, template, kubeconform, helm-unittest, Trivy); see Pipeline
  above.
- `.github/workflows/lint.yaml` -- runs `helm-checks` on every PR against
  `main`.
- `.github/workflows/deploy.yaml` -- runs `helm-checks`, then deploys.
  Auto-deploys `staging` on push to `main`; `workflow_dispatch` also covers
  manual deploys to either environment.
- `wordpress/tests/` -- the helm-unittest test suites.

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
