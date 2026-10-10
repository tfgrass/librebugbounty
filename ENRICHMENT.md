# Follow-up and contact enrichment (2.0.2)

## Scope

Follow-up is administrative state, **not a vulnerability assessment**. Closing a
case does not mark it fixed, discard its evidence or undo contact/sent markers.
Legacy discarded cases are not interpreted as contact or testing objections.

The default scope is one case. An explicit, separate form can record restrictions
for the exact, lowercase hostname. No subdomains or related organizations are
implicitly included. Case and domain restrictions combine by denial: lifting one
cannot override the other. Closing pauses checks and contact discovery; explicit
contact/check refusal reasons also set the matching case flag. Reopening retains
flags still checked in the form; removing a flag is an explicit edit.

Restricted cases are excluded from batch selections and checked again at claim
and execution boundaries. Queued screenshot jobs are retained, not deleted, and
can resume when their restriction is lifted. A restriction arriving after claim
but before capture is recorded as a failed attempt instead of making a request.
Existing recheck dates are cleared. Reopening does not schedule a fresh recheck.
Already-started requests cannot be undone; their results remain evidence, not a
reason to reopen the case. Old browser pages cannot be revoked remotely: reload
to see current restrictions. This is an application policy, not an egress firewall.

Closed cases leave normal review supply but stay in Inventory. A check-only
restriction still allows reviewing stored evidence; refreshed Review pages hide
the external URL-open control. Recording an already completed contact or sent
report remains possible: these actions do not send anything.

Case policy and discovery attempts are removed with the case. Exact-hostname
restrictions survive deletion/reimport. The restriction audit deliberately keeps
case identifiers, hostname, scope, previous/new flags and timestamp after case
deletion. It stores no notes, URLs or payloads and is not a tamper-proof audit or
an attribution record. Domain rows and audit history currently have no separate
UI purge operation; account for this retention when exporting/backing up the DB.

## Manual security.txt provider

Only a CSRF-protected POST invokes discovery. Opening a page, filtering Inventory
or reading statistics never performs a contact lookup. Requests for the same
case/provider within 60 seconds are coalesced by an atomic insert. A pending row
left by a crash remains visible; after the cooldown an explicit retry is possible.
Discovery is denied for closed/discarded cases and contact or check opt-outs.

The first-party provider:

- Receives **only a hostname**, not notes, evidence, credentials or a PoC URL.
- Fetches HTTPS `/.well-known/security.txt`, falling back to `/security.txt`
  only for HTTP 404/410. No redirects, crawling or provider-link retrieval.
- Uses a dedicated TLS-verifying, proxy-disabled client wrapped by Symfony's
  `NoPrivateNetworkHttpClient` (DNS/address checks plus peer-address validation).
- Sets 4-second inactivity and 6-second request limits, with a 64 KiB body cap.
  System DNS resolution is also subject to the host resolver's own limits.
- Requires UTF-8 `text/plain`, usable Contact, Expires and matching Canonical
  when specified. Missing, invalid, expired and transport failures are distinct.
- Supports email and HTTPS web channels. A web URL is labelled **form or
  disclosure portal**, not asserted to be a contact form. Policy links and
  preferred languages are retained without visiting those links.
- Does not currently parse PGP-signed documents. Unsupported channels are omitted
  with a warning; old successful results remain stored when a later lookup fails.

The detail view shows the last ten attempts, source and fetch time, published
expiry and warnings. Candidate data is rendered literally, not as executable
markup or auto-opened links. A published channel is neither permission to test
nor proof of ownership, identity, a bounty program or willingness to pay.

## Internal provider seam — not a plugin platform

Trusted PHP services implement `ContactDiscoveryProviderInterface` and receive
the `app.contact_discovery_provider` tag through autoconfiguration. IDs must be
unique lowercase identifiers. `ContactDiscoveryService` owns explicit triggering,
policy checks, cooldown and append-only attempt storage; providers return
`ContactDiscoveryResult` with status, provenance, channels and optional metadata.

This interface is **not a sandbox**. Trusted code in the Symfony process can use
other services. There is no uploadable plugin loader, arbitrary hook execution,
background enrichment trigger, Hunter.io integration, AI API key or mail sender.

Before adding Hunter.io or an AI/web-search provider, decide separately:

1. Operator opt-in, secret storage/redaction, external data transfer and retention.
2. Per-provider budgets, timeouts/rate limits and explicit retry behavior.
3. Source quality and provenance; suggestions must not silently become verified
   contact identities or change factual assessments.
4. Capability boundaries and deployment trust. A genuinely untrusted plugin
   requires an out-of-process sandbox and an explicit permission model.

Keep provider tests on mocks/synthetic fixtures; they must never contact real
organizations. Contact/sent marker timestamps are historical operator records,
not delivery telemetry. Follow-up statistics show the median from recorded
confirmation to documented contact marker, usable sample size and missing/invalid
chronology. They use the retained all-time cohort and selected TLD, not the chart
period. Closure-reason counts include all currently closed retained cases.

## Deployment

These changes require `Version20261010000000`. Before applying it in a real
installation, stop/drain all old web/CLI workers, take a database/artifact backup,
apply the additive migration, deploy the matching code, and restart workers with
the new code only. Verify migration and policy state before allowing work again.
**An already running old PHP worker does not acquire these guards just because
files or the schema changed.** No live migration or worker restart is part of the
isolated acceptance tests.

Until migration, old read paths stay compatible; the new editing/discovery UI is
unavailable. The down migration deliberately aborts rather than erase opt-outs.
A rollback needs a deliberate backup/data-retention plan. Do not deploy old code
against new restrictions and assume it will enforce them.
