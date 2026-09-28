# Storage study: TXT vs JSON vs SQLite

JotKite needs to store posts, users with roles, a review workflow (who submitted, who approved, change-request notes), categories and settings. It has to run on ordinary shared hosting (cPanel/Hostinger-style) next to GeoRank, which itself stores everything in flat files (`.html` pages plus `georank-config.json`).

## What the blog actually needs from storage

| Need | Why it matters here |
|---|---|
| Safe concurrent writes | A contributor saving a draft while an editor approves another post must not clobber either write. |
| Filter and sort | "Published and `published_at <= now`, newest first, page 3", "pending review, oldest first", "posts in category X". |
| Relations | Post → author → role, post → category, post → review events. |
| Atomic multi-step changes | Approving a post updates the post and writes an audit event, and both must land together. |
| Growth | 10 posts or 5,000 posts, without a rewrite. |
| Zero setup | No MySQL database to create, no credentials to paste. Upload and go, like GeoRank. |
| Easy backup | Copy a file or a folder. |

## Option 1: plain TXT files (one file per post, front-matter + body)

**Pros:** Human-readable, git-friendly, trivially backed up, no PHP extension needed.

**Cons:**
- No querying. Every list page, category page, sitemap and review queue means opening and parsing every file. That's fine at 50 posts and slow at 2,000.
- Needs a hand-written format for metadata (YAML-ish front-matter). Parsing edge cases (colons in titles, multi-line notes) become bugs.
- Users, roles and review history don't fit "one text file per post". You end up with more files and more custom formats.
- No transactions. Approve = rewrite post file + append log file, and a crash in between leaves them inconsistent.
- Concurrency needs manual `flock()` everywhere.

**Verdict:** great for a static-site generator with one author in git. It's the wrong fit for a multi-user CMS with a review workflow.

## Option 2: JSON files (one `posts.json`, or one JSON per post plus an index)

**Pros:** Native to PHP (`json_encode`/`json_decode`), matches how GeoRank already stores config, readable, no extension needed.

**Cons:**
- **Single `posts.json`:** every save rewrites the whole file. Two people saving within the same second means last-writer-wins and **silent data loss** unless every write is wrapped in `flock()` plus read-modify-write. Memory and time grow with the total size of all post bodies.
- **One JSON per post plus an index:** the index must be kept in sync by hand, which is effectively reinventing a database, badly. Index corruption means the posts become invisible.
- Filtering, sorting and pagination are all in PHP, loading everything first.
- No atomicity across files (post plus audit event plus index).

**Verdict:** good for small config blobs (which is exactly why GeoRank uses it for `georank-config.json`). Risky for multi-author content.

## Option 3: SQLite (single file `data/postbase.sqlite`) ✅ chosen

> The file keeps its original name from before JotKite was renamed (it was *PostBase* until 0.21.0). Renaming it would force a risky file move on every existing site, so it stays `postbase.sqlite`.

**Pros:**
- **Still just one file.** It keeps GeoRank's zero-setup spirit: no MySQL database or credentials, and a backup is copying one file plus `uploads/`.
- **Real transactions and locking.** Concurrent editors are handled by the engine (`busy_timeout`, WAL mode). An approval and its audit event commit together or not at all.
- **Real queries.** Pagination, category pages, review queues, counts per status and search are one indexed `SELECT` each.
- **Relations with integrity.** Foreign keys (deleting a category un-categorises its posts instead of breaking them), `CHECK` constraints on roles and statuses.
- **Scales well past any small-business blog.** Tens of thousands of posts are fine for read-heavy sites.
- **Available almost everywhere.** `pdo_sqlite` ships enabled by default in PHP and on virtually all shared hosts. JotKite shows a clear message if it's missing.
- **Easy upgrade path.** The code uses only PDO, so a future MySQL driver is a small change if a client outgrows SQLite.

**Cons and mitigations:**
- *Binary file, not diffable in git.* Content isn't meant to live in git. An export-to-JSON/Markdown feature is on the roadmap for portability.
- *Must not be web-downloadable.* `data/.htaccess` denies all access, the root `.htaccess` blocks `/data/`, and `config.php` can move the file outside the web root entirely.
- *Some network filesystems dislike WAL mode.* JotKite tries WAL and silently falls back to the default journal.
- *Very high write concurrency (hundreds of writers per second).* That doesn't apply to a blog.

## Schema (v1)

```
users          id, email (unique, case-insensitive), name, password_hash, role (contributor|editor|admin),
               active, source (local|georank), bio, created_at, last_login_at,
               default_category_id → categories (SET NULL)                       ← v4
categories     id, slug (unique), name, description, sort
posts          id, slug (unique), title, excerpt, body (sanitized HTML), cover_image, cover_alt,
               category_id → categories (SET NULL), author_id → users,
               status (draft|pending|changes_requested|published|archived),
               seo_title, seo_description, created_at, updated_at, submitted_at,
               published_at (future = scheduled), first_published_at, reviewed_by → users,
               type (post|page), pinned                                          ← v2
post_events    id, post_id → posts (CASCADE), user_id, action, note, created_at   ← audit trail
settings       key, value
login_attempts ip, attempted_at                                                  ← brute-force throttle
devices        id, user_id → users (CASCADE), selector (unique), token_hash (SHA-256),
               label, created_at, last_used_at, expires_at                       ← v3, "keep me signed in"
```

Indexes: `posts(status, published_at)` for every public query, `posts(author_id, status)` for "my posts", `post_events(post_id, id)`.

Schema version is stored in `PRAGMA user_version`. `pb_migrate()` applies numbered upgrades on first request after an update, so no installer is ever needed.

**Timestamps** are stored as UTC `YYYY-MM-DD HH:MM:SS` strings. They sort correctly as text and are converted to the blog's timezone only for display.

## Publishing workflow stored in this schema

```
            submit                 approve / publish (editor)
  draft ───────────► pending ─────────────────────────────► published ──(future date)──► scheduled
    ▲                  │  │                                    │
    │     withdraw     │  │ request changes (note required)    │ unpublish
    └──────────────────┘  ▼                                    ▼
                  changes_requested ──── edit & resubmit ───► pending        draft
  any ── archive (editor) ──► archived ── restore ──► draft          delete (admin / own unpublished draft)
```

Every arrow writes one `post_events` row, so each post has a complete "who did what, when, and why" history in the editor sidebar.
