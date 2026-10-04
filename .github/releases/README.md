# Publishing LibreBugBounty releases

Release notes are stored here so the published GitHub text can be reviewed
alongside the code. For Moneta, use tag `v2.0.0`, title
`LibreBugBounty 2.0.0 — Moneta`, and the body in `v2.0.0.md`.

## Before publication

Confirm that the release commit contains the intended changes and that the
working tree is clean. Keep private architecture notes, databases, artifacts,
and local test or demo files outside Git. Check the application and worker
versions, changelog, upgrade guide, and release notes together.

Run the checks used by CI with DDEV for PHP 8.3, Node.js 22 for the worker, and
Python 3.11 or later for backups. Use an isolated worker checkout for `npm ci`
if a running local instance shares its dependency directory.

```bash
ddev exec composer validate --strict --no-check-publish
ddev exec vendor/bin/phpunit
ddev exec composer audit --locked --no-interaction
npm --prefix playwright-worker ci --no-audit --no-fund
npm --prefix playwright-worker test
npm --prefix playwright-worker audit --omit=dev --audit-level=high
python3 -B -m unittest discover -s tests -p 'backup_local_test.py' -v
```

Review the successful browser acceptance for changed workflows and a fresh
DDEV installation. Application tests use isolated storage; never point them
at a working installation's database or artifact directory.

## Publish the approved commit

Run these steps from the approved release commit after its branch has been
reviewed and pushed, and confirm that GitHub CI passed for that exact commit.
Install GitHub CLI and authenticate it with access to this repository. Creating
the tag and GitHub release publishes that commit. Use the reviewed notes file
rather than automatically generated commit messages.

```bash
git tag -a v2.0.0 -m 'LibreBugBounty 2.0.0 Moneta'
git push origin v2.0.0
gh release create v2.0.0 \
  --verify-tag \
  --title 'LibreBugBounty 2.0.0 — Moneta' \
  --notes-file .github/releases/v2.0.0.md
```

Once published, check the release title, notes, source archives, and links to
the tagged documentation. Record the publication date in the changelog for
future releases; preparation alone does not establish that date.
