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
intentionally deferred until the final media flow.
