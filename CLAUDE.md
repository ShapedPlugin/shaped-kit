# Shaped Kit — agent instructions

Shaped Kit is ShapedPlugin's companion plugin. It bundles the official WordPress Model Context Protocol (MCP) Adapter, hosts the "AI & MCP" dashboard in WordPress Admin, coordinates per-product MCP registries and switches, keeps the agent audit log, and installs and updates ShapedPlugin products. The design lives in `docs/PRD/shaped_kit_prd.md`; the edge-case matrix is in `docs/flow.md`; known third-party and sibling defects are in `docs/EXISTING_PLUGIN_BUGS.md`.

`docs/` and `AGENTS.md` are gitignored and exist only on the owner's machine. **Read `docs/MEMORY.md` (full state and cross-repo context) and the PRD at the start of any new session, before planning or writing code.** `AGENTS.md` carries the same rules for other agents; keep the two in step.

This file is tracked in a public repository. Never put local paths, keys, license data or internal item IDs in it.

## 1. Binding constraints (PRD §0)

1. **No guessed code (C1).**
   - Every WordPress function, hook, filter, class, option name, argument order and return value is read from source before code uses it:
     - WordPress core on the local site: `app/public/wp-includes/`, `app/public/wp-admin/includes/`;
     - the bundled adapter: `libs/mcp-adapter/`;
     - product modules: the sibling plugin's source (for example `../location-weather/includes/Mcp/`, `../testimonial-pro/`).
   - **Mandatory:** in the turn that writes or modifies code, name the exact `file:line` you read.
   - If something can't be verified in source, don't use it; record the open point in the PRD.
2. **Reference implementation first (C2).**
   - For any feature a reference product already has, read its version first and match its shape unless there is a documented reason to differ. PRD §0 names the reference products and maps each feature to the exact reference file; the comparison notes in `docs/` record the differences.
   - A reference product that is not installed locally is cloned to a temporary scratch directory to read it, never onto the site.
   - Any difference needs an explicit written reason in the docs or PRD.
3. **Probe, don't reason (C3).** An edge case or assumption counts as verified only when it has been run:
   - by an automated unit or integration test, or
   - by a live probe on `http://localhost:10003` that writes nothing permanent to the database, stubs outbound HTTP (`pre_http_request`), and snapshots `wp_options` before and after.
4. **Edge check before commit (C4).**
   - Run the phase's rows in PRD §19.
   - Report what was checked and found fine, not only what was fixed.
   - Add newly found edge cases to §19 with a phase tag.
5. **Other plugins' bugs are reported, never patched here (C5).** Bugs in external or sibling ShapedPlugin products go in `docs/EXISTING_PLUGIN_BUGS.md`. Don't add ad-hoc workarounds or fixes for them in the Kit.
6. **Deep console debugging and database verification (C6).**
   - When writing or changing any code, debug it exhaustively from the console or CLI before calling the work done.
   - Where database state is involved (`wp_options`, custom tables, post types, transients), inspect the database before and after execution. Confirm that state transitions, queries, edge cases, corner cases and guardrails hold, with no side effects, data pollution, or hidden SQL or runtime warnings.

## 2. Technical stack and code rules

- **PHP 7.4 floor.** WordPress Coding Standards.
- **WordPress 6.9+ for all MCP capabilities.** Older versions skip gracefully (`AdapterBootstrap::wordpress_supported()`).
- **REST only.** Every Kit backend route is `register_rest_route` with an explicit `permission_callback` (typically `manage_options`) and, for browser callers, a nonce (`wp-api-fetch` handles cookie auth). No new `wp_ajax_*` handlers.
- **Admin UI is a React mount point.** `src/Admin/DashboardPage.php` renders only the wrapper `#shaped-kit-dashboard`; PHP renders no other markup. JS lives in `assets/admin/`, never under `src/`.
- **`libs/mcp-adapter/` is an unmodified upstream copy** of `WordPress/mcp-adapter`, currently pinned to 0.7.0. Replace it on upgrade, never patch it. All workarounds, compatibility handling and step-aside logic live in `src/Mcp/AdapterBootstrap.php`. A new adapter release is run through the adapter matrix before it is trusted (PRD E93).
- **Isolation of plugin state.**
  - The Kit never writes another plugin's options.
  - MCP on/off switches go through product-registered handlers (`shaped_kit/mcp_toggle_handlers`).
  - Products reach the Kit only through filters (`shaped_kit/products`, `shaped_kit/mcp_toggle_handlers`) and the action `shaped_kit/mcp_tool_called`.
  - Licenses stay with each product's Pro edition. The Kit never stores a Pro license key.
- **Safety defaults.**
  - **Off by default (FR-S1):** MCP stays disabled until the site owner enables it. Installing the Kit enables no MCP server or endpoint.
  - When the Kit supplies the adapter, the adapter's shared default server is off (`mcp_adapter_create_default_server` returns false).
- **The audit log stores argument keys only, never values.**
- **Hook callbacks take no test-only arguments (E143).** `do_action()` hands every callback an empty string when the hook has no arguments (`wp-includes/plugin.php:513-515`), so an optional parameter such as `$asset_file = null` or `$terminate = true` receives `''`. Register a no-argument wrapper (`DashboardPage::render_page()`, `SelfUpdater::on_admin_init()`) and keep the argument for tests only.

## 3. Tests and lint

**What to test is decided by `tests/TESTING.md`:** risk-based. Write tests only where a bug would expose data or bypass a permission, change data wrongly, take a site down, install unverified code, fail silently, or come back after a fix. Don't test markup, layout, labels, pass-through calls, WordPress core or the adapter. Every test is mutation-checked.

Always use the Kit's own isolated toolchains (`tools/phpunit`, `tools/phpcs`), never a global binary.

| Command | What |
|---|---|
| `composer tools:install` | Install both toolchains (first run on a fresh checkout) |
| `composer test:scripts` | Dependency-free loader and package tests (`tests/unit/*.php`), a fresh PHP process per scenario |
| `composer test:unit` | PHPUnit, no WordPress (`tests/phpunit/unit`) |
| `composer test:integration` | PHPUnit inside real WordPress 7.1.3 (`tests/phpunit/integration`); skips cleanly without the test library |
| `composer test:multisite` | The same integration suite on a real multisite (`WP_MULTISITE=1`) |
| `composer test:integration:ci` / `test:multisite:ci` | What CI runs: the integration suite without the tests that need a sibling product plugin, failing on any skip. Run `tools/install-wp-tests.sh` first on a fresh machine (it installs pinned WordPress and the test library) |
| `composer test` | All four |
| `npm run test:js` | Jest unit tests for the React dashboard (`assets/admin/**/*.test.js`) |
| `npm run build` | Compile the admin bundle to `build/admin/` |
| `composer lint` / `lint:fix` | WPCS + PHPCompatibilityWP 10.0.0-alpha2 (the stable 9.3.5 cannot see PHP 8 syntax) |
| `npm run package:verify` | Check the release zip against the strict allow/denylist |

The integration suite uses `.wordpress/` (core 7.1.3) and `.wordpress-tests-lib/` (wordpress-develop 7.1.3, with a machine-local `wp-tests-config.php`), both gitignored. Its database is `shaped_kit_tests` on Local's MySQL socket. The test library drops and recreates every table in that database on each run, so never point it at `local`.

## 4. Architecture and implementation status

Built and functional:

- `src/Mcp/AdapterBootstrap.php`: include-time adapter loader. Guards against duplicate classes, detects active adapter plugins, handles WP-CLI activations, enforces the WP 6.9+ floor, keeps the default server off.
- `src/Mcp/ProductRegistry.php`: sanitises and normalises product entries from `shaped_kit/products` and binds handlers from `shaped_kit/mcp_toggle_handlers`.
- `src/Mcp/ProductSwitch.php`: delegates on/off to the product's handler and verifies the result by reading it back; never touches the product's options.
- `src/Mcp/AppPasswords.php`: mints application passwords through core `WP_Application_Passwords`, returned once with `Cache-Control: no-store`.
- `src/Mcp/WriteGuard.php`: Level 1 destructive confirmation (preview, single-use token bound to user, canonical arguments and entity state; atomic consume; idempotency keys). Reference copy for products: `templates/product-module/Support/WriteGuard.php`.
- `src/Audit/` (`LogTable`, `LogListener`, `LogPruner`): the append-only `{$wpdb->prefix}shaped_kit_mcp_log` table fed by `shaped_kit/mcp_tool_called`; keys only; 90-day prune plus a 100,000-row cap.
- `src/Store/` (`CatalogData`, `CatalogClient`, `PackageVerifier`, `Installer`, `EddBridge`, `BetaInstaller`, `SelfUpdater`): Ed25519-signed catalog, SHA-256 check before unpacking, host allowlist, free and Pro installs, one-time beta installs with a no-downgrade barrier, Kit self-update.
- `src/Rest/McpController.php` (`GET /mcp`, `POST /mcp/products/{slug}`, `POST /mcp/app-password`, `GET /mcp/logs`) and `src/Rest/StoreController.php` (`/store/*`), both under `shaped-kit/v1`.
- `src/Admin/DashboardPage.php`: the admin page under `ShapedPlugin > AI & MCP`.
- `assets/admin/`: React dashboard with the overview strip, product cards with switches and opt-in toolsets, the sample-prompt drawer (`toolsCatalog.js`), the connect drawer (Application Passwords, client snippets for Claude Code, Claude Desktop, Cursor, Codex, Copilot, Gemini CLI and generic), the connection tester (`connectionTest.js`), Try a Tool (`ToolTester.js`), the Activity Log and the Products & Store tab.
- `templates/product-module/`: the drop-in MCP module for sibling products (`docs/PRODUCT_MODULE_TEMPLATE.md`).
- **Product modules built** (in the product repos, branch `feat/mcp-module`): Location Weather Free and Pro, Real Testimonials Free and Pro, each with an "MCP for AI Agents" settings tab. Location Weather: Free 10 abilities, Pro 11 (adds `get-cloud-status`). Real Testimonials: Free 16, Pro 20 (both editions have bulk `create-testimonials`; Pro adds `list-testimonial-categories`, `get-review-sources-status`, `list-testimonial-forms`, `get-testimonial-form`; both editions have the opt-in Builder toolset `get-block-catalog`, `validate-block-attributes`, `insert-block`, `list-ready-patterns`, `insert-ready-pattern`). `assets/admin/toolsCatalog.js` is the source of truth; update these counts when it changes.

**Active context:** full state and cross-repo context live in `docs/MEMORY.md`.

## 5. Roadmap and phases

The three roadmap items AGENTS.md lists, with their state in the code:

- **Phase 6, audit log (`{$wpdb->prefix}shaped_kit_mcp_log`, `shaped_kit/mcp_tool_called`, keys only, 90-day prune):** built (`src/Audit/`, commit `d5a6530`).
- **Phase 7, destructive confirmation (Level 1 token round trip, WriteGuard semantics):** built (`src/Mcp/WriteGuard.php`, commit `d5a6530`), together with opt-in toolsets (D14).
- **Store track S0–S5, catalog loader, Ed25519 signature checks, SHA-256 package verification, manual beta installs:** code built (`src/Store/`, commits `9abd305` to `a5f9ec7`).

Still open:

- **Phase 8:** roll the product module template out to WP Carousel Pro and the other products.
- **Store track go-live (S0 release pipeline, outside this repo):** the pinned public keys in `CatalogData` are development placeholders. A real Ed25519 key pair, a hosted signed `catalog.json` plus `.sig`, release zips with SHA-256, the Kit's own EDD item, EDD item IDs checked against the live store, and any CDN host EDD redirects to (add it to `PackageVerifier::ALLOWED_HOSTS`) must exist before a release. See `docs/PRODUCTION_READINESS.md`. Q12 (the EDD beta option on the live store) is still unverified. Until then the Store is off: `StoreGate::LIVE` is false, which hides the dashboard tab, makes every `/store/*` route answer 503 and stops the update checks; flip it in the release that ships the pipeline. A developer can turn it on for one site with the `shaped_kit/store_live` filter.
- **FR-K6:** one-click Kit install from Pro editions (Free editions only link, by rule). Decided, not built.
- **PRD §19 rows fixed and verified** (the PRD states each limit): E21 (multisite: a site admin is refused and a super admin allowed, `can_install` is per user, uninstall is clean on every site), E25 (a header forwarded under another name is recovered and a 401 carries a host hint; a header removed completely can only be explained), E75 (every list and search query passes `lang => ''` and `suppress_filters`; checked with the real Polylang, WPML not), E88 residual (stray output is scrubbed from when the Kit loads to the last hook before the echo; output printed before the Kit loads is not caught), E116 (the CORS header is scoped to MCP routes, and a cross-origin failure names CORS as a possible cause), E119 (the template guard; Free yields its product card, toggle handler and settings route). Store rows E45–E64 are checked against tests, and the checks found and fixed four defects: a refused install was reported as installed (core returns an empty default and puts the reason on the skin, so `Installer::install_package_file()` reads both, as core's AJAX installer does), a beta of a product that was not installed could clear another plugin's folder of the same name, a beta's version was not re-checked under the install lock, and the store's own error text could carry the licence key back to the browser. E113 is done: `tools/verify-package.php` fails a zip whose admin build lacks `wp-api-fetch`, and CI's package job runs it.
- **CI** runs the integration suite (`test-integration` job: PHP 7.4 and 8.3, plus a multisite run) on a MySQL service, without the 10 test files that load a sibling product plugin (`integration-ci` in `phpunit.xml.dist`). Those run locally only, because the product repos are private. A new test that needs a sibling goes in that exclude list.
- **Still open:** store rows still open: E45 (no stale flag; the bundled catalog is served once the 12 h cache expires), E51 (an activation failure is untested), E59 (catalog timeout and throttle are untested), E62 (FR-K6, not built).
- **Deferred by decision:** Level 2 approvals in wp-admin, the update pusher, add-on UI (until the first add-on ships), MCP rate limiting (revisit with audit-log data), an in-WordPress chat.

## 6. Workflow rules

- **Product branching (FR-BR1).** In every product plugin, Free and Pro (`testimonial-free`, `testimonial-pro`, `location-weather`, `location-weather-pro`, and later products), use one unified branch named `feat/mcp-module` across both repos. All MCP features, tools, settings, fixes and updates for that product go there. Don't create separate feature or phase branches in product plugins (such as `feat/mcp-toolsets`, `feat/mcp-abilities` or `feat/mcp-settings-tab`). The Kit itself keeps its own phase and feature branch naming.
- **Ability catalog and sample prompts sync (FR-K10).** Whenever any product, new or existing, Free or Pro, introduces or changes an MCP ability, **immediately sync the Kit's `assets/admin/toolsCatalog.js`**:
  - add the ability under its exact namespaced id (for example `real-testimonials/get-testimonial-view`);
  - give it a clear human-readable `label` and a concise `summary`;
  - add at least 3 realistic `prompts` of the kind a user would give an AI client (Claude, Cursor, Copilot);
  - run `npm run build` so the "View tools & sample prompts" drawer and the tool counters stay in lockstep with the registered abilities.
- **New product modules** start from `templates/product-module/`. Pro tools register through `Abilities_Registrar::register()`, never raw `wp_register_ability()` (E118). The transport gate returns exactly `true` or `false`, never a `WP_Error` (E87).
- **No guesswork.** When introducing or changing functions or hooks, read the source in the local WordPress install, the adapter or the sibling plugin first, and cite the line numbers in the turn.
- **Phase by phase delivery.** One phase and one file at a time, as listed in PRD §13.
- **Green suite before a coding step is finished.** `composer test`, `npm run test:js` and `composer lint` all pass.
- **No unapproved commits.** Never run `git commit` unless the owner says so. No AI attribution in commit messages.


## 7. Code reuse across editions and products

A product's Free and Pro editions run the same code, and the next products (WP Carousel, then the rest) reuse it with few or minor changes. Full rule: PRD §20.

- **Data, not code, differs.** Edition and product differences live in constants and config arrays (block lists, enums, design rules, presets, the pattern `pro` flag, `Module::EDITION`). No `if ( 'pro' === … )` in shared logic; the only edition check allowed is the one-owner guard (PRD §9, E119). Pro-only tools join through `{prefix}/mcp_loaded` and `{prefix}/mcp_ability_names`.
- **Generic engine in the template.** Shared code lives in `templates/product-module/` (the MCP module, and the `Builder/` folder for Build with AI). A product copies it and supplies only its data.
- **One change, every copy, same turn.** When shared code changes in `testimonial-pro`, make the same change in `testimonial-free` (and the reverse) in the same turn. Check it with a normalised diff (namespace, text domain and slug replaced), so only the expected lines differ, and run both editions' tests. If the fix is generic, put it in the template too, and list the products not yet updated in `docs/MEMORY.md`.
- **No runtime dependency.** Products never call Kit code, and the Kit never calls product classes (D6). Reuse means copying the template.
- **Shared package decision at Phase 8.** WP Carousel makes three products, the D3 trigger for a namespace-prefixed shared package. Raise it with the owner then; don't build it earlier.
- **Parity is tested.** Shared behaviour gets a test that runs in every edition (for example the `BuildPipeline` JS/PHP parity fixtures). Passing in one edition and failing in the other is not done.
