# GermanPath Project Record

## Overview

GermanPath is a German-learning media platform designed for low-cost PHP +
SQLite shared hosting. The canonical production domain is configured through
`SITE_URL` and defaults to `https://germanpath.site`.

## Current architecture

- `public/`: the only intended web document root
- `app/`: configuration, bootstrap, HTTP, database, and support layers
- `database/migrations/`: numbered transactional SQLite migrations
- `content/`: reserved for modular static JSON metadata
- `storage/`: runtime database/private data; ignored by Git
- `logs/`: development history plus runtime logs
- Cloudflare Worker/R2: deferred protected media layer

## Current module

**Phase 4 — Commerce and course access: complete for this action.**

## Completed

- Created a portable PHP application structure.
- Added `.env`-based centralized configuration and `.env.example`.
- Added secure session cookie defaults and CSRF token primitives.
- Added PDO SQLite connection with foreign-key enforcement.
- Added idempotent transactional migration runner.
- Added initial schema for settings, users, and audit logs.
- Added centralized request/response/router foundation with parameterized routes.
- Added safe development/production exception handling.
- Added output escaping helper and a responsive GermanPath base layout.
- Added Apache rewrite rules and shared-hosting documentation.
- Added direct foundation tests and ran PHP lint/tests after implementation.
- Initialized a local Git repository; no remote, credentials, or Replit-only
  state is required by the application.
- Added modular JSON collections for teachers, courses, playlists, videos,
  shorts, pages, and site settings.
- Added schema-versioned content validation with collection-specific required
  fields and type checks.
- Added a content loader with malformed-JSON rejection, aggregated errors,
  safe in-memory caching, `id`/`slug` lookups, and validation reports.
- Added development fixtures covering multiple teachers and multiple R2
  storage locations within one course.
- Connected `/courses`, `/course/{slug}`, and `/free` to validated JSON data.
- Added content tests for valid loading, malformed JSON, unknown collections,
  multiple teachers, and per-media storage mapping.
- Added cross-collection integrity validation for teacher, course, playlist,
  video, and short references, including duplicate-ID detection.
- Added authentication services for registration, login, logout, profile
  updates, password changes, email verification, email changes, and password
  reset.
- Added hashed, time-limited, single-use auth tokens with generic purposes.
- Added a mail service abstraction with safe logging as the default driver and
  optional PHP mail delivery.
- Added CSRF-protected browser routes for registration, login, verification,
  password reset, and account settings.
- Added authentication tests and live registration/login/account smoke tests.
- Added persistent login-attempt throttling for email/IP pairs and structured
  authentication audit events.
- Added course-specific, teacher-specific offers and duration pricing in JSON.
- Added payment methods, pending payment submissions, admin approval/rejection,
  lifetime or expiring course access, and access event history.
- Added student purchase routes and an admin payment-review screen with CSRF
  protection.
- Added payment/access tests for offers, pending state, authorization,
  approval, rejection, expiration, lifetime access, and revocation.

## Database changes

Migration `001_foundation.sql` creates:

- `app_settings`
- `users`
- `audit_logs`
- supporting indexes

Migration `002_auth_tokens.sql` creates the generic `auth_tokens` table for
email verification, password reset, and email-change workflows.

Migration `003_login_attempts.sql` creates the rolling login-failure tracking
table used by authentication throttling.

Migration `004_payment_access.sql` creates payment methods, payment
submissions, course access, and access history tables.

## Known limitations

- Content fixtures are development examples; media files and Worker access do
  not exist yet.
- Payment-proof uploads, protected media access, Worker/R2 integration, full
  administration, payment notifications, and progress tracking are not
  implemented.
- PHP was installed in the development environment for verification; hosting
  still needs PDO SQLite enabled.
- CORS is intentionally not configured yet.

## Security considerations

No production secrets are committed. Runtime logging redacts sensitive-looking
context keys. The full authentication, upload, rate-limit, authorization, and
media security audits remain required before production.

## Exact next steps

1. Add secure payment-proof upload handling and confirmation/rejection emails.
2. Add media authorization and Cloudflare Worker/R2 protected delivery.
3. Add content administration/reporting and progress tracking.
4. Keep development fixtures clearly separate from production content.

## Production-readiness status

Not production-ready. The current work is the tested foundation only.
