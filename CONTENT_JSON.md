# Content JSON

The content engine is implemented in `app/Content/`. Its content directories
are:

```text
content/
├── teachers/
├── courses/
├── playlists/
├── videos/
├── shorts/
├── pages/
└── site/
```

Each JSON object uses `schema_version` and `type` fields. The PHP
`ContentValidator` checks required fields, scalar types, identifier formats,
supported schema versions, and collection-specific relationships. The
`ContentLoader`:

- loads one collection at a time
- rejects malformed JSON instead of silently skipping it
- reports all errors in a collection together
- caches valid files in memory using modification time and file size
- supports lookup by `id` and `slug`
- produces a validation report for future administration/system health screens
- validates cross-collection references before content is trusted

Media objects carry their own storage identifier, video filename, subtitle
filename, and thumbnail metadata. A course must never be assumed to map to one
bucket because a single course may span multiple R2 accounts or buckets.

## Current fixture model

- `teachers/*.json`: teacher profiles and supported languages
- `courses/*.json`: course metadata, multiple `teacher_ids`, pricing, and access options
- `playlists/*.json`: ordered video IDs
- `videos/*.json`: lesson metadata and per-video storage mapping
- `shorts/*.json`: short-form free or paid media
- `pages/*.json`: static page content
- `site/*.json`: site-level content settings

Cross-reference checks currently cover:

- course `teacher_ids` → teachers
- playlist `video_ids` → videos
- video `teacher_id`, `course_id`, and `playlist_id`
- short `teacher_id`
- duplicate IDs within each collection

Malformed JSON must produce a visible validation error for administrators; it
must not be silently ignored.
