# Changelog

All notable changes to this fork of [bvisible/elementor-mcp-api](https://github.com/bvisible/elementor-mcp-api) are documented here.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/); versions are the plugin header version.

## [1.4.1] — 2026-06-09

Independent security-audit follow-up, applied **before any production install**.
Focuses on stored-XSS surface and capability hardening across both REST and MCP.

### Security (MUST-FIX)
- **SVG stored-XSS — fixed.** Removed `image/svg+xml` from the media-import allowlist
  (now raster-only: jpg/png/gif/webp/avif). SVG is XML that can carry `<script>` and the
  import does a raw `copy()` with no sanitization — it would execute on the site domain.
  WordPress blocks SVG uploads by default for the same reason.
- **Per-post capability checks (REST).** `edit`/`read` permission callbacks now check
  `edit_post`/`read_post` on the **specific** page when the route carries an id (not the
  blanket `edit_posts`), so one Author cannot rewrite or read another user's page/draft.
  Page creation requires `edit_pages`.
- **Per-post capability checks (MCP).** An Ability's `permission_callback` cannot see its
  input, so every write/read ability now re-checks the resolved `post_id` *inside* its
  `execute_callback` (`guard_edit_post` / `guard_read_post` → 403). This closes the MCP
  door that fixing only REST would have left open.
- **Site-global writes require `manage_options`.** `update_kit`, `create_template`, and the
  `register_post_meta` auth callback for `_elementor_data` are administrator-only — they
  are whole-site config, not per-page content. Abilities enforce the same via `guard_admin`.
- **Raw-HTML gate.** The direct-meta fallback save (which bypasses Elementor's own save
  pipeline and could persist inline `<script>`) now requires `unfiltered_html`.
- **Documented Administrator-account requirement** in the README — create the Application
  Password on a dedicated admin user.

### Security / robustness (SHOULD)
- **Draft leak — fixed.** `list_pages` / `list-pages` no longer return non-published pages
  to callers who can't edit them; per-id reads check `read_post`.
- **Field-name consistency.** `build_page` and `import_media` now accept both `source_path`
  (canonical, matches the MCP ability) and the legacy `path`, so neither surface silently
  imports nothing.
- **Payload ceiling.** Write request bodies over ~4 MB are rejected (413) before JSON
  decoding (`Validator::check_body_size`).
- **Snapshot semantics documented.** Restore is one level deep and only valid until the next
  write (two writes in a row destroy the good backup) — noted in README + IMPROVEMENTS.
- `create_template` now validates its element tree (parity with the other writers).

### Tests
- 46/46 assertions (`php tests/run.php`): added SVG-rejection and payload-ceiling cases.
  Capability/draft-leak checks call WordPress and are flagged for on-install validation.

## [1.4.0] — 2026-06-09

First release of the `mt-alarcon` fork. Focus: hardening the plugin for unattended,
AI-driven writes to live WordPress sites, and closing gaps between the REST and MCP
surfaces. Forked from upstream 1.3.0.

### Security
- **Media import path guard (LFI fix).** `import_media` / `build-page` previously
  `copy()`-ed any server path into the media library — a path-traversal / Local File
  Inclusion vector. Paths are now canonicalized and must resolve inside
  `wp_upload_dir()`; remote URLs and non-image MIME types are rejected.
  (`Validator::resolve_media_path`)
- **Element-tree validation.** Every full-page write (`update_page`, `create_page`,
  `build_page`, `add_element`, and their MCP twins) is structurally validated and
  bounded — max depth 30, max 5000 elements, valid `elType`, `widgetType` required on
  widgets, array-typed `settings`/`elements` — before it replaces `_elementor_data`.
  Malformed payloads return a 400 instead of bricking the page.
  (`Validator::validate_tree`)
- **Request-body element-id validation.** Element ids arriving in bodies
  (`add_element`, `patch-bulk`) are validated; URL routes were already constrained.

### Added
- **Save snapshot + rollback.** Each save snapshots the prior `_elementor_data` to a
  backup meta slot. New `POST /page/{id}/restore` endpoint and `restore-page` ability
  revert one level — bad writes are now undoable.
- **`GET /kit/globals`** + `get-kit-globals` ability — the active Kit's global colors
  and fonts in a flat, agent-friendly shape, so generated widgets can reference
  Elementor's `__globals__` (e.g. `globals/colors?id=primary`) instead of hardcoding
  inline hex. (Design-system-first generation — the #1 professional-quality lever.)
- **`GET /health`** — public, unauthenticated probe reporting plugin/Elementor
  versions and active state. Lets a client confirm the plugin is installed without
  edit credentials.
- **MCP parity abilities** — `find-elements`, `patch-elements-bulk`, `restore-page`,
  `get-kit-globals` (the REST surface had these since 1.3.0; the MCP surface lagged).
- **Dependency-free test harness** — `php tests/run.php` (41 assertions, no composer /
  PHPUnit) over the pure-logic surface.

### Fixed
- **`add-element` ability crash** on a parent container with no `elements` key
  (undefined-index). The REST twin already guarded this; the ability now matches.
- **Media dedup correctness.** `import_image` deduped by *title* only — two different
  images sharing a title collapsed into one. Now dedups by SHA-1 content hash and uses
  `wp_unique_filename()` to avoid clobbering existing uploads.
- **PHP 7.4 floor.** Widget discovery used `str_starts_with()` (PHP 8.0+) while the
  header declares `Requires PHP: 7.4`. Added a polyfill so the declared floor is real.

### Notes / needs validation on a real install
- The validation/snapshot/health/globals/parity changes are statically verified
  (lint + unit tests) but the runtime paths (data save, kit reads, REST/MCP wiring)
  require a live WordPress + Elementor to confirm end-to-end.

## [1.3.0] — upstream (bvisible)
- Bulk patch, column-width helper, and find endpoints. See upstream history.
