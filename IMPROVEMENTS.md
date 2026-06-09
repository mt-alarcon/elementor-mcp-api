# IMPROVEMENTS — fork engineering notes

Deep-work record for the `mt-alarcon/elementor-mcp-api` fork (GPL-3.0, forked from
[bvisible/elementor-mcp-api](https://github.com/bvisible/elementor-mcp-api) at 1.3.0).
This documents what the plugin *is*, what was *changed in 1.4.0*, what's *deferred*, and
what *needs a live WordPress* to validate.

---

## 1. Understanding — what the plugin is and how it works

A WordPress plugin (no build step, plain PHP) that exposes an Elementor page-building API
two ways from the same core:

- **REST** under `neoservice/v1` (`class-rest-controller.php`).
- **MCP abilities** under the `neoservice-elementor` category (`class-abilities-provider.php`),
  registered only when the WordPress Abilities API + MCP Adapter plugins are present.

### Boot flow (`neoservice-elementor-api.php`)
1. Guards on `ABSPATH`, defines version/path constants.
2. Loads `includes/` classes.
3. On `rest_api_init` (only if `elementor/loaded` fired) → registers REST routes.
4. On the Abilities API hooks (only if that plugin is active) → registers the category +
   abilities.
5. Registers `_elementor_data` post-meta with `show_in_rest` and an `edit_posts` auth callback.

### The three core classes
- **`Element_Factory`** — pure builders that emit well-formed Elementor element JSON:
  `container`/`row`/`column`/`widget` primitives, ~12 widget shortcuts (heading, text,
  image, button, divider, spacer, icon, social-icons, nav-menu, form), and two composites
  (`hero`, `content_row`). Also `generate_id()` (8-char hex) and `reassign_ids()` (for
  duplication). The composites carry opinionated brand defaults (specific fonts/colors).
- **`Elementor_Data`** — the read/write engine over `_elementor_data`. Dual-path: native
  Elementor document API when present (regenerates CSS), direct post-meta fallback
  otherwise. Tree ops (find/insert/remove/duplicate/update-settings), structure summary,
  templates (theme-builder), kit read/write, media import, widget discovery
  (`list/schema/defaults`, schema is read live from the widget — not hardcoded), CSS flush.
- **`REST_Controller`** / **`Abilities_Provider`** — thin HTTP/MCP wrappers over the above.

### Endpoint / ability inventory (pre-fork)
Pages (list/get/structure/update/create/build), elements (get/add/update/remove/duplicate/
move), bulk (`patch-bulk`, `column-width`, `find`), `section`, templates (list/create),
kit (get/update), widgets (list/schema/defaults), media import, flush-css. Permissions:
reads gate on `read`, writes on `edit_posts`.

### The gaps found in the read (what this fork addresses)
1. **Write API with thin safety.** No structural validation of the element tree before it
   replaces a page; no snapshot/rollback; recursion has no depth/size bound.
2. **Media import LFI.** `import_image` `copy()`-ed *any* server path — a path-traversal /
   Local File Inclusion vector. Dedup was title-only (different images, same title →
   collapsed into one).
3. **REST↔MCP drift.** The 1.3.0 bulk/find/section additions landed on REST only; the MCP
   ability surface lagged. Also a real bug: the `add-element` *ability* inserted into a
   parent's `elements` without ensuring the key existed (the REST twin guarded it).
4. **Design-system exposure.** Nothing surfaced the Kit's global colors/fonts for
   `__globals__` referencing — the #1 lever for *professional*, brand-consistent output.
5. **PHP floor mismatch.** Header says `Requires PHP: 7.4`, code used `str_starts_with()`
   (PHP 8.0+).
6. **No tests, no health probe.**

---

## 2. Plan — prioritized

| Pri | Area | Item | Status |
|-----|------|------|--------|
| P0 | Security | Media path traversal/LFI guard | ✅ done |
| P0 | Security | Element-tree validation (shape + depth + size) on all writes | ✅ done |
| P0 | Safety | Save snapshot + `restore` (undo a bad write) | ✅ done |
| P1 | Correctness | Media dedup by content hash; unique destination filename | ✅ done |
| P1 | Correctness | Fix `add-element` ability undefined-`elements` crash | ✅ done |
| P1 | Parity | Bring MCP abilities to REST parity (find/bulk/restore/globals) | ✅ done |
| P1 | Quality | `GET /kit/globals` for `__globals__` design-system referencing | ✅ done |
| P2 | Compat | PHP 7.4 `str_starts_with` polyfill | ✅ done |
| P2 | Ops | Public `GET /health` probe | ✅ done |
| P2 | Ops | Dependency-free PHP test harness | ✅ done |
| P2 | Docs | README attribution + CHANGELOG + skill.md sync + version bump | ✅ done |
| — | Deferred | Mapper-side `__globals__` / responsive overrides | n/a (Python client) |
| — | Deferred | Per-page write lock (PATCH race is documented, not enforced) | deferred |
| — | Deferred | Multi-level undo history (only one backup slot today) | deferred |

---

## 3. What was implemented (1.4.0)

New file **`includes/class-validator.php`**:
- `validate_tree()` — structural + bounded (depth 30, 5000 elements) validation of any
  element tree before it replaces `_elementor_data`.
- `resolve_media_path()` — canonicalize + confirm-inside-uploads + image-MIME-only;
  rejects `../`, absolute escapes, remote URLs, non-images.
- `is_valid_element_id()` — for ids arriving in request bodies.

**`class-elementor-data.php`** — `save_page_data()` snapshots prior state; new
`restore_backup()`; `get_kit_globals()`; `import_image()` now content-hash dedups and uses
`wp_unique_filename()`.

**`class-rest-controller.php`** — validation wired into `update_page`/`create_page`/
`build_page`/`add_element`; id checks in `patch-bulk`; resolved paths in `import_media`/
`build_page`; new routes `POST /page/{id}/restore`, `GET /kit/globals`, `GET /health`;
uniform `error_response()`.

**`class-abilities-provider.php`** — fixed the `add-element` crash; added `find-elements`,
`patch-elements-bulk`, `restore-page`, `get-kit-globals`; reused the Validator across write
abilities.

**`neoservice-elementor-api.php`** — loads Validator; `str_starts_with` polyfill; version → 1.4.0.

**`tests/`** — `run.php` + `wp-stubs.php` + 2 suites, 41 assertions, runs on bare PHP.

Verification done locally: `php -l` clean on all 10 PHP files; `php tests/run.php` → 41/41.

---

## 4. What needs a live WordPress + Elementor to validate (post-install)

The static layer (lint + pure-logic unit tests) is green, but these runtime paths cannot be
exercised without a real install and **must** be checked on the pilot site:

1. **Save + restore round-trip** — write a page, confirm the backup meta is set, call
   `/page/{id}/restore`, confirm the page reverts and still opens cleanly in the Elementor editor.
2. **Tree validation in situ** — a deliberately malformed payload returns 400 (not a 500 /
   white screen); a valid payload still saves and renders.
3. **Media path guard** — an in-uploads image imports; an out-of-uploads path and a `../`
   path are rejected; content-hash dedup returns the same attachment on re-import.
4. **`/kit/globals`** — returns the real Kit colors/fonts, and a widget using the resulting
   `__globals__` references renders with the global value (the single most important
   professional-quality check).
5. **`/health`** — reachable unauthenticated and reports correct versions / active state.
6. **MCP parity** — the four new abilities register and execute through the MCP Adapter.
7. **PHP 7.4** — confirm the polyfill path on an actual 7.4 runtime (CI matrix or a 7.4 box).

---

## 5. Deferred / future work
- **Per-page write lock.** The PATCH race is *documented* but not enforced; a transient
  lock around `save_page_data` would make concurrent writes safe by construction.
- **Multi-level undo.** One backup slot today; a small ring buffer would allow deeper undo.
- **Mapper-side `__globals__` + responsive overrides.** These live in the *Python client*
  that consumes this plugin (`int-elementor-design-to-page`), not in the plugin — the
  plugin now *exposes* the globals (`/kit/globals`); the client decides when to reference them.
- **Upstream PRs.** The LFI guard, the `add-element` crash fix, the tree validation, and the
  PHP 7.4 polyfill are generic, non-fork-specific bug/security fixes — good upstream PR
  candidates to `bvisible/elementor-mcp-api`.
