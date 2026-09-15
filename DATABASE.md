# Database

SQLite is the dynamic-data store. Static course and media metadata will remain
in JSON so content can change independently of user and purchase records.

## Migration workflow

Migration files live in `database/migrations/` and use the format:

```text
NNN_short_description.sql
```

The application creates `schema_migrations`, applies files in lexical order,
and records each successful migration inside the same transaction. Never edit a
migration that has already run; add the next numbered migration instead.

The first migration creates `app_settings`, `users`, and `audit_logs`.
Migration 002 creates hashed auth-token storage, and migration 003 creates
rolling login-attempt storage for throttling.

Migration 004 creates the commerce and access tables:

- `payment_methods` — enabled payment methods and display instructions
- `payment_submissions` — pending, approved, or rejected payment claims
- `course_access` — lifetime or expiring access grants tied to a course offer
- `course_access_events` — grant and revoke history

Course offers and prices remain in `content/courses/*.json`; payment and access
records reference those stable course and offer IDs. Payment-proof upload
storage is intentionally deferred until the upload-security module.
