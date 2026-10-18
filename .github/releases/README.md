# Publishing LibreBugBounty releases

Release notes are stored here so the published GitHub text can be reviewed
alongside the code. For the prepared release, use tag `v2.1.0`, title
`Moneta (Conatus) - v2.1.0`, and the body in `v2.1.0.md`. It remains a draft
until publication is explicitly approved and the release checks pass.

From 2026-10-12 onward, GitHub release titles always use
`<Release-Name> - v<Version>`, for example **Moneta (Conatus) - v2.1.0**.
Keep the release name first. The 2.x family is Moneta; feature releases may add
a parenthesized name, as 2.1.0 does with Conatus. Do not rename historical
releases as part of an ordinary publication.

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

## Prepare without publishing

Commit and push the reviewed preparation branch, then create an unpublished draft
targeting its exact commit. Check for an existing draft first; edit it rather than
creating duplicate release records. Do not create or push the tag at this stage.

```bash
gh release create v2.1.0 \
  --draft \
  --target "$(git rev-parse HEAD)" \
  --title 'Moneta (Conatus) - v2.1.0' \
  --notes-file .github/releases/v2.1.0.md
```

The changelog's `(unreleased)` marker is deliberate during preparation. At final
publication, record the actual publication date, update the verification notes,
and verify CI for the resulting release commit. If that differs from the draft's
target, update the draft target explicitly before publication.

## Publish the approved commit

Run these steps from the approved release commit after its branch has been
reviewed and pushed, and confirm that GitHub CI passed for that exact commit.
Install GitHub CLI and authenticate it with access to this repository. Creating
the tag and GitHub release publishes that commit. Use the reviewed notes file
rather than automatically generated commit messages.

```bash
git tag -a v2.1.0 -m 'Moneta (Conatus) - v2.1.0'
git push origin v2.1.0
gh release edit v2.1.0 \
  --draft=false \
  --verify-tag \
  --latest \
  --target "$(git rev-parse HEAD)" \
  --title 'Moneta (Conatus) - v2.1.0' \
  --notes-file .github/releases/v2.1.0.md
```

Once published, check the release title, notes, source archives, and links to
the tagged documentation. Record the publication date in the changelog for
future releases; preparation alone does not establish that date.
