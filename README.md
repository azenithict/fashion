# A Zenith Fashion

Custom tailoring platform for reviewed specifications, verified payments, manufacturing and independent partner services. Existing cPanel target: https://fashion.azenith.ca.

**Production readiness: NOT READY.** The live site has not been replaced. See [verified implementation and remaining scope](docs/PRODUCTION_READINESS.md).

## Architecture
Static Next.js/React frontend + one same-origin PHP8.3/PDO MySQL API + MariaDB. Shared enums/measurement requirements/state graph: `shared/contract.json`. Secrets, sessions, uploads, PHP modules, database migrations and backups stay outside the public document root. No additional recurring hosting subscription.

## Development / checks

```sh
npm ci
npm run build
npm run typecheck
npm run lint
npm test
npm audit --omit=dev
```

The GitHub Actions workflow also runs PHP/MariaDB integration, concurrency and real HTTP acceptance tests against an isolated database. PHP fixtures require environment=test and must never run against production. `backend/tests/config.php` contains test-only credentials, not production configuration.

## Operator documents
- [Architecture](docs/ARCHITECTURE.md) and [shared API contract](docs/API_CONTRACT.md)
- [cPanel installation, private staging, mail/payment setup, backup and recovery](docs/DEPLOY_CPANEL.md)
- [Commercial approval inputs](docs/COMMERCIAL_APPROVALS.md)
- [Production-readiness evidence and limitations](docs/PRODUCTION_READINESS.md)
- [Task board](docs/MVP_BOARD.md)

Preserve existing history and review changes before merge/release. No active partners, real tariff, supplier delivery promise, paid order or payment-provider activation is implied by test fixtures. The50-location Ontario family network is uncontracted.
