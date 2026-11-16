# Publishing and maintaining funset/mfa-email

The GitHub repository is https://github.com/SirFunset/mfa_email.
The Composer package is **funset/mfa-email**, not the GitHub username.
Upstream is https://github.com/MrSilaz/mfa_email; keep its copyright and license.

## Supported release lines

| Branch | Initial tag | TYPO3 | PHP minimum | Upstream starting point |
| --- | --- | --- | --- | --- |
| main | v14.0.0 | ^14.3 | 8.2 | Fork's existing TYPO3 14 implementation |
| main-v13 | v13.0.0 | ^13.4 | 8.2 | upstream/main-v13 (826a941) |
| main-v12 | v12.0.0 | ^12.4 | 8.1 | upstream/main-v11-and-v12 (3b0184c) |

The v13 branch also receives security fixes present on upstream/main but absent
from upstream/main-v13, as well as the fork's hardening. TYPO3 11 is not supported.

## One-time setup (after reviewing and committing the prepared changes)

1. Push each branch to the fork, without pushing upstream's historical tags.
   Only the new 12.*, 13.*, and 14.* tags should be published for this package.
   Existing upstream v1/v2 tags still contain `ralffreit/mfa-email`; do not rewrite
   or republish them as fork releases. Do not use `git push --tags` or `--mirror`.
2. Submit https://github.com/SirFunset/mfa_email at https://packagist.org/packages/submit.
   Packagist registration is a one-time manual/account action; the update API does
   not create the package. Confirm it is registered as `funset/mfa-email`.
3. In the GitHub repository's Actions secrets, add `PACKAGIST_USERNAME` and
   `PACKAGIST_API_TOKEN`. Use a package-scoped update token where available.
   Never put credentials in this repository or in a workflow URL/log.
4. Permit GitHub Actions to write repository contents. Branch/ruleset policies
   must allow the release bot to push the version-only commit and annotated tag.
   Do not disable protection broadly just to make a release pass; grant a narrow
   exception or adapt the process to release PRs if direct bot pushes are forbidden.
5. Set the Actions repository variable `ENABLE_AUTO_RELEASE` to `true`.
   It is deliberately opt-in: pushes run CI but cannot publish before this is set.
6. Run the `Release` workflow manually on each supported branch for the initial
   release, or push the next reviewed change. The workflow file must first exist
   on GitHub's default branch for `workflow_dispatch` to be available.

No setup, credentials, remote commits, tags, or publishing were performed by
preparing these files locally. Worktrees keep the uncommitted branch changes
separate: `../mfa_email-v12` and `../mfa_email-v13` alongside the main checkout.

## Automatic releases

Every push to `main`, `main-v12`, or `main-v13` runs Composer validation, PHP lint,
provider regression tests, dependency auditing, and release-script tests. PHP CI
covers 8.1–8.5 for TYPO3 12 and 8.2–8.5 for TYPO3 13/14. Each runner resolves
compatible dependencies for its PHP version. No credentials are exposed to PR CI.

After CI succeeds and auto-release is enabled:

1. Check Packagist credentials are configured.
2. Select a version only from that branch's major line. On the first release,
   use the checked-in metadata version. Subsequently bump the latest patch unless
   a higher version was deliberately set in the metadata.
3. Synchronize `extra.typo3/cms.version` in `composer.json` and `ext_emconf.php`.
   If they changed, create a version-only `github-actions[bot]` commit. The workflow
   never adds a co-author or co-signer. Automated bot commits/tags are unsigned;
   human commits can retain the maintainer's own signing configuration.
4. Create an annotated `vMAJOR.MINOR.PATCH` tag and atomically push it and the
   branch. An ancestry-checked explicit lease prevents stale/concurrent updates
   without permitting history rewrites.
5. Create a GitHub release with generated notes without changing the repository's
   global "latest" release to an older maintained major line.
6. Call Packagist's documented update API, then poll its public metadata until
   the exact version **and source commit** are indexed. An accepted API request
   alone is not considered successful publishing.

The root Composer `version` field is intentionally absent: Packagist derives it
from Git tags. TYPO3's version metadata remains synchronized by the script.
Development branch aliases start at 12.0.x, 13.0.x, and 14.0.x respectively.

The publish step is in the same workflow because pushes made with `GITHUB_TOKEN`
do not trigger another tag/push workflow. Do not add a second tag-triggered
workflow and assume it will run. The bot's own version bump does not recursively
release. A run on an already-tagged release commit reuses that release rather
than allocating another version. Never delete or move a public tag to repair a
failed publish step. If a version-bump commit was pushed before publishing
failed, use **Run workflow** on the branch's current release commit (not
**Re-run jobs** on the original pre-bump event). The latter intentionally refuses
the now-stale trigger SHA. If normal development has advanced the branch, run on
the current reviewed commit to publish its next release; existing tags remain
immutable.

For a minor release, set **both** TYPO3 metadata versions to (for example)
`13.1.0`, and update `extra.branch-alias.dev-main-v13` to `13.1.x-dev`, before
pushing. Do not use a different major number on an existing maintenance branch.
All pushes, including documentation-only changes, are releases once enabled.

## TYPO3 12 security gate

Dependency audits report abandoned packages without failing solely on abandonment
(`--abandoned=report`). TYPO3 12/13 depend on abandoned `doctrine/annotations`;
that is a maintenance warning, not itself a vulnerability advisory. Actual
vulnerability advisories remain blocking on every release line.

Public Packagist currently stops at TYPO3 12.4.45. Composer flags security
advisories in this core and its SVG sanitizer dependency; resolving the v12 line
with normal security blocking fails. Consequently the default v12 release workflow
is deliberately blocked, not silently marked green. To ship from that branch,
configure a supported patched/ELTS dependency source and its CI access, then run
all gates successfully. This fork does not fix TYPO3 core vulnerabilities.

Local compatibility tests can run against the historical public core in an
isolated development installation. That is not evidence that the underlying
TYPO3 installation is production-safe. Do not commit `--no-blocking`, advisory
ignores, or platform-requirement bypasses to the release workflow as a workaround.

## Local verification

Use a PHP installation with TYPO3's required extensions (including intl, sodium,
iconv, mbstring, PDO, and XML):

```sh
composer update --prefer-dist --no-interaction --no-plugins --no-scripts
composer validate --strict
composer test
composer audit --locked --abandoned=report
python3 -m unittest discover -s .github/scripts -p 'test_*.py' -v
```

Release tests use temporary local Git repositories, never the real repository or
Packagist. Publish API tests use explicitly mocked network responses. Testing
live publication requires the real registration, credentials, and a pushed
release; local tests do not claim it has happened.

After committing, preview the next version without making a commit or tag:

```sh
python3 .github/scripts/release.py --branch main --expected-sha "$(git rev-parse HEAD)" --dry-run
```

Use `main-v12` or `main-v13` in the corresponding worktree. The script requires a
clean working tree; do not run the publishing form against unreviewed local work.

## Backport policy

Backport security fixes and compatible functionality, not TYPO3-14-only APIs.
Keep the `Ralffreit\\MfaEmail` namespace, extension key `mfa_email`, and provider
identifier `email` so existing user settings survive migration.

- TYPO3 12 retains string MFA view types, `StandaloneView`, and `FluidEmail`.
- TYPO3 13 retains `ViewFactoryInterface` and `FluidEmail`.
- TYPO3 14 uses `ViewFactoryInterface`, `TemplatedEmailFactory`, and `MailerInterface`.
- All lines use secure code generation, timing-safe comparison, single-use
  invalidation, configurable expiry, sane defaults, and failed-attempt locking.

Cherry-pick compatible follow-up fixes deliberately after the initial branch
changes have been committed, resolving API differences and running that branch's
tests. Do not blindly merge main into an older TYPO3 branch.

Packagist API reference: https://packagist.org/about#how-to-update-packages
GitHub token event behavior: https://docs.github.com/en/actions/how-tos/writing-workflows/choosing-when-your-workflow-runs/triggering-a-workflow
