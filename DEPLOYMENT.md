# Deployment

GermanPath is intentionally deployable to ordinary PHP shared hosting.

## Production checklist

- [ ] PHP 8.1+ with PDO SQLite enabled
- [ ] HTTPS enabled and `SITE_URL` set to the canonical domain
- [ ] web root points to `public/`
- [ ] `.env` created outside version control
- [ ] SQLite and log directories writable by PHP but not publicly downloadable
- [ ] server directory listing disabled
- [ ] backups stored outside `public/`
- [ ] `AUTO_MIGRATE` reviewed before going live
- [ ] production errors do not expose diagnostics
- [ ] Worker URL and secret configured only after the Worker module is tested

Cloudflare Worker CORS configuration is deliberately deferred until the website
and protected media flow are complete, as required by the master specification.
