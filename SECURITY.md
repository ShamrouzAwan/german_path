# Security baseline

The foundation establishes these defaults:

- PDO with SQLite foreign-key enforcement and prepared migration inserts
- secure, HttpOnly, SameSite=Lax session cookies
- password hashing, session regeneration on login, and CSRF-protected account
  forms
- verification, password-reset, and email-change tokens are stored as hashes
  with expiry and single-use enforcement
- repeated login failures are tracked by normalized email and client IP with a
  rolling throttle
- authentication events are recorded in the audit log without passwords or
  tokens
- output escaping helper for HTML
- centralized exception handling with safe production messaging
- runtime logs redact keys containing password, secret, token, credential, or
  signed URL
- secrets belong in environment configuration, never JSON content or Git
- only `public/` is intended as the web document root
- payment approval and manual access changes require an admin role and are
  written to the audit log
- payment submissions are pending by default; they do not grant access until
  an admin approves them

Before production, payment-proof uploads, media-level access control, Worker
authentication, payment notifications, and a full security regression pass
must be implemented and tested.
