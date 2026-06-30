# Playwright Worker

This worker runs inside the DDEV sidecar container and exposes a very small HTTP API:

- `GET /health`
- `POST /retest`

It is meant to be called from the Symfony application inside the same DDEV network.

Example payload:

```json
{
  "url": "https://example.com/search?q=%3Csvg%20onload=alert(1)%3E",
  "expectedEvidence": null,
  "timeoutMs": 10000,
  "screenshot": true
}
```
