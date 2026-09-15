# GermanPath

GermanPath is a portable German-learning platform built for PHP + SQLite shared
hosting. Static learning metadata will live in modular JSON files; dynamic
accounts, purchases, access, progress, administration, and audit data will live
in SQLite. Protected media will later be served through a Cloudflare Worker
that returns short-lived R2 URLs.

## Current status

Phases 1–5 are implemented on top of the shared-hosting foundation:

- central configuration with `.env` support
- secure session defaults and CSRF primitives
- PDO SQLite connection
- transactional, idempotent migrations
- centralized routing, responses, and error handling
- responsive base layout
- development/application logging
- deployment documentation
- registration, login, email verification, password reset, and account settings
- course-specific offers, payment submissions, manual admin review, and
  lifetime/expiring course access
- student purchase and admin payment-review routes
- protected Worker/R2 media signing, course authorization, MP4/SRT delivery,
  and a retry-safe lesson player

See [`project.md`](project.md) for the living implementation record and next
module. Payment-proof uploads, production Worker deployment, CORS policy, and
full administration are not complete.

## Local development

The production runtime is PHP. If PHP is available locally:

```bash
cp .env.example .env
php -S localhost:8000 -t public
```

Then open `http://localhost:8000`.

## Upload to GitHub

Create an empty repository on GitHub, then run these commands in the project
directory. Do not commit a real `.env` file; it is already excluded by
`.gitignore`.

```bash
git branch -M main
git config user.name "Your Name"
git config user.email "you@example.com"
git add .
git commit -m "Build GermanPath commerce and access workflow"
git remote add origin https://github.com/YOUR_USERNAME/germanpath.git
git push -u origin main
```

Use the GitHub account's verified email or its `noreply` email for
`user.email`. GitHub does not accept account passwords for HTTPS Git pushes;
authenticate through the browser/credential helper or use an SSH remote.
