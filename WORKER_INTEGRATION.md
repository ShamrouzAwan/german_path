# Cloudflare Worker / R2 integration

The intended protected-media flow is:

1. PHP verifies the user is authenticated.
2. PHP verifies course access and expiry.
3. PHP loads the media item's JSON metadata, including its storage identifier.
4. PHP authenticates server-to-server with the Worker.
5. The Worker validates the request and returns short-lived signed R2 URLs.
6. The browser streams media directly from R2.

The Worker URL and secret are configuration values only. They must never be
sent to browser JavaScript, stored in content JSON, or written to logs. CORS is
deferred until the production domain and custom media policy are fixed.

## Implemented integration

The PHP application now provides:

- `WorkerMediaService` for server-to-server signing requests
- `MediaAccessService` for free-media and course-access authorization
- `/media/{id}` JSON signing endpoint
- `/watch/{slug}` player page with retry-safe loading
- per-media `storage`, MP4, and SRT key forwarding
- strict key validation, short Worker timeouts, and safe error responses

The reference Worker implementation is in `worker/index.js`. It accepts only
server requests carrying `X-Worker-Secret`, creates short-lived HMAC-signed
media URLs, selects an R2 binding from the media storage identifier, and
streams the object without exposing R2 credentials. Copy
`worker/wrangler.toml.example` to a private deployment configuration and set
`WORKER_SECRET` with Wrangler secrets.

Application configuration:

```text
WORKER_URL=https://your-worker.example
WORKER_SECRET=outside-the-repository
WORKER_SIGN_PATH=/sign
WORKER_TIMEOUT_SECONDS=8
WORKER_TOKEN_TTL=300
```

The Worker must be deployed and its R2 bindings configured before media can
play in production. CORS and final custom-domain policy remain part of the
production-readiness phase.
