# PHP staging operations

Repository: https://github.com/caymanlogistic-crypto/1c-ip-php.
Host: 89.208.213.14; root folder `/srv/1c-ip-php`.

| Stand | Branch | HTTPS | Compose project | Private app | DB |
|---|---|---|---|---|---|
| UI | staging-ui | https://ui.89.208.213.14.sslip.io | ipphp-ui | 172.30.101.10:80 | ipphp_ui |
| INTEGRATIONS | staging-integrations | https://integrations.89.208.213.14.sslip.io | ipphp-integrations | 172.30.102.10:80 | ipphp_integrations |

Each project has its own app, MySQL 8.4.9, network, DB user, file secrets, documents, runtime/sessions, private data and logs. Containers have no published ports; only the host proxy can reach their private IPs. UI uses an internal Docker network. Only the host Apache HTTPS proxy is public. A dedicated TLS certificate covers the two sslip.io names; certbot.timer renews it and a scoped deploy hook reloads Apache. Existing sites, certificates and databases are retained. Basic access and application test accounts are distinct per stand. Credentials live only in private carriers, never in Git.

PHP 8.5.6 Apache image and MySQL are pinned by digest. Composer 2.8.9 installs the existing composer.lock without updates. Source comes from the image, not a writable checkout. `.dockerignore` starts from an allowlist; `.local`, private profiles/keys, SQL dumps, sessions, Git and vendor do not enter the context. Dependency vendor is built from lock.

## Deploy a task

1. Branch from current main into `task/<task-id>`. One task owner per stand. Commit/push runs the image build and PHP syntax checks without deploying.
2. Incorporate current main and push the prepared commit to the appropriate staging branch. Do not force another owner's candidate off a stand. No deploy is triggered by main or task pushes.
3. Wait for the GitHub Actions run to succeed; compare `/_ops.php` commit and image_id against its artifact/summary. Check login and the task scenario. Freeze the staging branch while the owner reviews. After acceptance, merge sequentially into main.

The workflow ships a gzip Docker archive over SSH, checks its SHA256 and loads the declared image ID. Compose runs that exact immutable ID. `image_id` is the SHA256 Docker image config digest, not a registry manifest digest; no registry or owner PC is needed. Source commit is also embedded as an OCI label and release.json. Server receipt and diagnostics bind those values to the running container.

GitHub environment branch policies restrict UI and INTEGRATIONS secrets to their exact staging branches. Branch protection is unavailable on this private repository's current plan. Each deployment key is restricted to one forced command, has no Docker group membership, cannot forward ports or open an interactive shell, and can sudo only the corresponding receiver operation. The receiver verifies GitHub's signed OIDC token: repository/owner, exact staging ref, workflow path, event, commit and run ID. SSH host key is pinned. The app never mounts Docker socket.

There is a separate GitHub concurrency group and server flock for each stand. In-progress deployments are not cancelled by later pushes. A stale branch head is skipped before mutation; a run ID older than the highest accepted server run is rejected. A rerun cannot replace a newer deployed run. No SQL or migrations are part of the receiver.

## Diagnosis and image rollback

`https://<stand-address>/_ops.php` is behind Basic access and reports stand, commit, image_id, deployment time, actual DB identity/readiness, PHP and external gates. It never reports credentials. Server receipts are in `<stand>/state/current.json`, previous.json and release-<run>.json. Compose configuration is root-owned and separate from application images.

As the authorized server administrator:

```sh
sudo /usr/local/sbin/ipphp-deploy ui rollback
sudo /usr/local/sbin/ipphp-deploy integrations rollback
```

This activates the previous retained image, verifies it and preserves DB/documents. It is only an application-image rollback for a compatible schema. Automatic rollback to the current receipt is attempted if a deployment fails readiness. Do not prune the current/previous images. For an incompatible schema change, prepare an explicit backup and compatibility/return plan before deployment.

## Initial transfer and external prerequisites

Source: local T001 DB ip_ya_t001_20261117; fresh consistent snapshot 17 November 2026 17:52:31 Moscow, 115 tables / 48,548 rows. SHA256 SQL: `8c38177567fc96bac4f98dfc63ce2d26dda70a54d1b785a40e930bc5f318fe74`. `initial-backup` holds the SQL, receipt, file archive and manifest outside Git. Both copies were verified against every row/schema hash before separate test-account password rotation. Import uses explicit `time_zone='+03:00'`; the first failed UTC copy is preserved separately, not used. Source was not modified. An INITIAL_IMPORT_COMPLETE marker prevents accidental initialisation through the deploy path. Subsequent deployments only recreate app.

164 source files were copied with hashes, including 10 document files (378,333 bytes). External integration keys/profiles were excluded from both stands. UI gets none. Necessary read profiles can be installed into INTEGRATIONS under a concrete provider task. Settings in the copied DB can still show enabled=1; runtime admission=false and no workers prevent inherited effects. Historical outbox/pending/attempts are data, not permission to replay.

Transport for 1C is explicitly `direct` in each stand's private config. Server DNS resolves 1c.dltkmsk.ru to 89.208.213.32; TCP/TLS and anonymous HEAD succeeded without the Windows PC/VPN. This proves prerequisites, not 1C login, organization access or bank-statement reading.

Host network preflight checked DNS/TCP/TLS and anonymous HEAD for 1C/bank, Diadoc/auth, Mosenergo, Mosvodokanal, PIK, MAX, CIAN, Yandex Realty, Morrow and Fortex; SMTP greeting/TLS was checked on the configured imap.spaceweb.ru:465. DNS/TCP succeeded for all. Default Linux trust rejected Mosvodokanal and MAX certificate chains; resolve the approved CA chain in their specific tasks, without disabling verification. HTTP 405/302 are connectivity observations, not authentication success. Report: `/srv/1c-ip-php/network-preflight.json`. No provider authentication, exchange, send or external write occurred.

Automatic Word→PDF, historical guards and the actual accepted design gallery remain outside this infrastructure task.
