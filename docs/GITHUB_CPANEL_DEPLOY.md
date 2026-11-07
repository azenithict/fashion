# GitHub → existing cPanel preview

CI already builds and tests the repository. The `deploy-preview` job in `ci.yml` adds code-only automatic deployment to the existing owner-gated preview after a successful **push** build of `feature/launch-experience`. It is disabled until the repository variable `CPANEL_PREVIEW_DEPLOY_ENABLED=true` is set. Production/public-root deployment and payment activation are excluded.

## One-time owner setup

Hosting SSH access must allow an authorized key for `isp85`; cPanel Terminal availability does not alone establish remote SSH access. Obtain the host's SSH hostname, port and host-key fingerprint from the provider. Verify that fingerprint before trusting a known_hosts entry. Never disable host-key checking.

Create a dedicated deployment key and authorize its public half for this account. Store its private half only in GitHub environment `cpanel-preview` secret `CPANEL_SSH_KEY`, and the verified OpenSSH known_hosts entry in secret `CPANEL_KNOWN_HOSTS`. Do not paste either into chat. Set repository variables `CPANEL_HOST` and `CPANEL_PORT`; then enable `CPANEL_PREVIEW_DEPLOY_ENABLED`. Restrict who may change the deployment branch/workflow or this environment. The account key has the hosting account's privileges; a provider-supported restricted deployment account is preferable when available.

Once configured, routine source changes require no ZIP downloads, File Manager uploads or Terminal copy/paste. A failed build does not deploy. The job depends on both frontend and backend checks and accepts only a push to the named repository branch; pull requests do not deploy. Retry a failed deployment job from the existing Actions run. The workflow runs directly on the feature branch; no main-branch workflow merge is required to enable it.

## Update guarantees

- Downloads the successful run's preview artifact; verifies the transfer checksum and package manifest.
- Retains private releases and backs up the current public preview entry.
- Requires all existing database migration names/checksums to match; applies no migration.
- Keeps config-preview.php, credentials, storage and both databases unchanged.
- Atomically switches only `/_fashion-preview/index.php` to the new private release.
- Verifies unauthenticated owner gate + noindex on HTTPS; restores previous entry if acceptance fails.
- Records rollback entry in private `preview-active.json`.

No credentials are presently connected to the agent, and the repository enable variable has not been set. Therefore automated server deployment is **prepared, not activated or verified on the host**.

## Rollback

Use the private `previous_entry` path recorded by the successful deployment. Restore that exact backed-up PHP entry with mode0644 using a temporary file and atomic rename. This rolls back code selection only; it does not rewrite databases or credentials. Keep releases referenced by current and rollback entries. Review any future schema migration separately.
