# Sibling Product MCP Module Template

This directory contains the drop-in reference implementation for adding Model Context Protocol (MCP) support to ShapedPlugin products (e.g. Real Testimonials, WP Carousel, etc.).

It provides the complete generic MCP plumbing, modeled on the Location Weather pilot and brought up to date with everything Real Testimonials added since (opt-in toolsets, audit telemetry, the Build with AI engine), excluding product-specific business tools.

It is **tested in this repository**, not only documented: `tests/phpunit/integration/ProductModuleTemplateTest.php` loads it as a real module under the placeholder names, and the `adapter-template` suite runs it on the wire through every real MCP adapter copy (section 7). A change to the template that breaks either fails the Kit's CI.

---

## 1. Directory Structure

```text
templates/product-module/
├── README.md               # This checklist and adoption guide
├── Module.php              # Lifecycle bootloader, WP 6.9+ floor check, lazy registration
├── Permission_Gate.php     # Transport authentication, capability checks, on/off kill-switch, active toolsets
├── Abilities_Registrar.php # Tool definitions, toolsets and their map, audit telemetry, try/catch safety & ob buffering
├── Server.php              # Instantiates isolated /wp-json/{slug}/mcp endpoint
├── Kit_Registration.php    # Discovers product in Shaped Kit dashboard via filters
├── Rest_Controller.php     # REST endpoints for product settings card (/wp-json/{ns}/mcp), toolsets, Build with AI helper
├── Builder/                # Generic Build with AI & Ready Patterns engine (Phase B6)
│   ├── README.md           # Builder-specific adoption guide and configuration checklist
│   ├── Catalog.php         # Schema lookup, allowed enums, design rules
│   ├── ValueValidator.php  # Deep attribute validation with dotted path drop reasons
│   ├── BuildPipeline.php   # Unified finishing pipeline: sanitize -> defaults -> patch
│   ├── AttributePatch.php  # Presets, layout overrides, prompt column extraction
│   ├── BlockInserter.php   # Unique ID generation, group wrapping, safe placement & replacement
│   ├── PatternLibrary.php  # Remote pattern fetching, cache auto-healing, KSES sanitization
│   ├── Builder_Tools.php   # Callbacks for get-block-catalog, validate-block-attributes, insert-block; the shared `block` schema
│   ├── Pattern_Tools.php   # Callbacks for list-ready-patterns, insert-ready-pattern
│   └── Builder_Abilities.php # Tool and prompt definitions and the toolset map, picked up by Abilities_Registrar when this folder is present
├── Media/                  # find-media and import-image (D22): an AI client gets a media library id for an image
│   ├── README.md           # Adoption guide and the rules every copy must keep
│   ├── Remote_Guard.php    # Pure checks: address form, public IPv4 only, size cap, cURL options of a pinned hop
│   ├── Remote_Fetch.php    # The one guarded request, pinned per hop, certificate checks restored, redirects checked
│   ├── Image_File.php      # The file judged by its bytes: type, pixels, GIF size
│   ├── Media_Tools.php     # Callbacks for find-media and import-image
│   └── Media_Abilities.php # Definitions and the toolset map, picked up by Abilities_Registrar when this folder is present
├── Support/
│   ├── Audit.php           # Reports every tool call to Shaped Kit's audit log, argument keys only (E159)
│   ├── Pagination.php      # Clamps pages (10,000) and page size (15-100), envelope metadata, real total past the last page
│   ├── Secret_Mask.php     # Masks secrets (configured / not configured, or the last 4 characters) and names secret keys
│   ├── Token_Budget.php    # Measures what a set of tools costs an AI client on every message (E126)
│   ├── Url.php             # Strict http(s) address cleaning for tool arguments (E126)
│   └── WriteGuard.php      # Level 1 destructive confirmation & token engine
└── Tools/
    └── Context_Tools.php   # Universal {slug}-get-context orientation tool
```

---

## 2. Adoption Checklist (Search & Replace)

When copying this folder into a product (e.g. into `src/Mcp/` or `includes/Mcp/`), perform the following replacements across the files in order:

| Step | Find | Replace With | Scope & Notes |
|:---:|---|---|---|
| **1** | `ShapedPlugin\Template\Mcp` | `ShapedPlugin\{Product}\Mcp` | Target PHP namespace across all `.php` files (e.g., `ShapedPlugin\Testimonial\Mcp`). |
| **2** | `template-slug` | Product slug (kebab-case) | MCP endpoint identifier & wire server ID (e.g., `real-testimonials`, `wp-carousel`). Edition-neutral (no `-pro`). |
| **3** | `template_prefix` | Product prefix (snake_case) | Used in options, transients, error codes, hook names and the WriteGuard keys (e.g., `sp_testimonial`, `wpcp`). |
| **4** | `template-domain` | Product text domain | Used in all internationalization functions `__()`, `_e()`, etc. Every string in the template uses this one placeholder; a test enforces it. |
| **5** | `template/v1` | Product settings REST namespace | In `Rest_Controller.php` (e.g., `sp-testimonial/v1`). |
| **6** | `Module.php` Constants | Product metadata constants | Update `NAME`, `EDITION`, `VERSION`, and `ENTRY_POST_TYPE` in `Module.php`. |
| **7** | `Rest_Controller.php` `SETTINGS_PATH` | The admin address of the product's "MCP for AI Agents" settings tab | Used by `builder_client()`; relative to wp-admin. |

### Detail on Step 6 (`Module.php` Constants)

In `Module.php`, update the class constants:
```php
const SLUG            = 'real-testimonials';         // Kebab-case product slug
const NAME            = 'Real Testimonials';         // Human-readable product name
const EDITION         = 'free';                      // 'free' or 'pro'
const VERSION         = '4.0.0';                     // Version string or constant fallback
const ENTRY_POST_TYPE = 'spt_testimonial';          // Main post type or '' if none
```

> [!NOTE]
> Setting `ENTRY_POST_TYPE` is optional. If your product does not manage a custom post type, leave it as an empty string `''`.

The Builder and the media tools are optional: if you do not copy `Builder/` or `Media/`, `Abilities_Registrar` serves only your own tools. If you copy one, there is nothing to wire (see `Builder/README.md` and `Media/README.md`).

---

## 3. How Pro Joins Free (PRD §9)

ShapedPlugin Pro products are standalone plugins (or can be active alongside Free). The template accommodates this seamlessly:

1. **One Endpoint per Product:** Both Free and Pro share the same `SLUG`, endpoint (`/wp-json/{slug}/mcp`), and kill-switch option (`{prefix}_mcp_enabled`).
2. **One Owner Rule is enforced built-in (E119):** If Free and Pro are both active, Pro loads at `init` priority 4 and sets the `{SLUG}_MCP_MODULE_OWNER` constant. Free loads at priority 5 and yields (`should_load()` returns `false`, `Kit_Registration` yields). AI clients and Shaped Kit see a single unified endpoint and product card. This ensures products whose Free edition does not go inert automatically remain safe without duplicate registration.
3. **Registering Pro Tools:** Pro editions add abilities without modifying core template files by hooking:
   - `{prefix}/mcp_loaded`: Action fired during `Abilities_Registrar::register_abilities()`. Register each Pro tool here through `Abilities_Registrar::register()`, **not** raw `wp_register_ability()`: the registrar adds the required category, hides the tool from core's REST API and the default server, and wraps it with the exception, output and audit guards.
   - `{prefix}/mcp_ability_names`: Filter fired in `Abilities_Registrar::names()`. Append the Pro tool names (e.g., `{slug}/pro-tool-name`) to the array.
   - `{prefix}/mcp_toolset_map`: Filter on the toolset map. **Add an entry for every Pro tool.** A name missing from the map counts as `core`, which is always on, so a Pro write tool without an entry would be served to every site.

Example (Pro edition; `Category_Tools` is the Pro's own class):
```php
add_action( 'sp_testimonial/mcp_loaded', function () {
    \ShapedPlugin\Testimonial\Mcp\Abilities_Registrar::register(
        array(
            'real-testimonials/get-categories' => array(
                'label'               => __( 'Get Categories', 'testimonial-pro' ),
                'description'         => __( 'List testimonial categories.', 'testimonial-pro' ),
                'input_schema'        => array( 'type' => 'object', 'additionalProperties' => false ),
                'execute_callback'    => array( Category_Tools::class, 'get_categories' ),
                'permission_callback' => array( \ShapedPlugin\Testimonial\Mcp\Permission_Gate::class, 'can_access' ),
                'annotations'         => array(
                    'readonly'      => true,
                    'destructive'   => false,
                    'openWorldHint' => false,
                ),
            ),
        )
    );
} );

add_filter( 'sp_testimonial/mcp_ability_names', function ( $names ) {
    $names[] = 'real-testimonials/get-categories';
    return $names;
} );

add_filter( 'sp_testimonial/mcp_toolset_map', function ( $map ) {
    $map['real-testimonials/get-categories'] = 'core'; // read-only: core. A write tool names 'management'; a delete, 'destructive'.
    return $map;
} );
```
Raw `wp_register_ability()` without a `category` returns null, so the tool silently does not exist. With a category it registers, but a top-level `annotations` key is ignored (core reads them from `meta`), so the read-only hints are lost. Both probed on WordPress 7.1.3.

---

## 4. Toolsets and the token budget (E126, D14)

Every tool a server serves is listed to the AI client with its name, description and input schema, and that list travels with **every message** of every client. A tool added to a default-on set is a standing cost on every site.

- **Toolsets.** `Abilities_Registrar::toolset_catalog()` lists the sets a product can offer: `core` (always on), `management`, `destructive`, `builder`, `bulk`, `media`. A tool belongs to one, through `core_toolset_map()` (your own tools) and the toolset map the Builder and Pro filters add to. `names()` and `prompt_names()` serve only the tools whose set is on. A fresh site gets `core` and `management`; the owner opts in to the rest from the dashboard. A set with no tool is not listed, so a product with no deletion tool shows no deletion switch.
- **Every tool needs a map entry.** A tool missing from the map counts as `core`, which is always on. A test in this repo checks that every tool the template defines has one; add the same check for your own tools.
- **Put anything large in an opt-in set.** Real Testimonials' bulk create was 4,171 characters on top of a default of 2,091; it got its own `bulk` set, and the single create tool was cut to its ten major properties.
- **Keep schemas small.** No per-value enum where the tool can refuse a bad value and list the valid ones in its answer (the one exception is the Builder's `block` list, so the adapter refuses an unknown block before the callback runs). Descriptions only where the name does not give the format. No default-on tool that carries every property of the record.
- **Pin the size.** `Support\Token_Budget::total( definitions, map, toolsets )` gives the characters of name + description + input schema (about four per token, a rule of thumb). Add a test in your product that fails when the default-on tools pass a ceiling you chose, so a property added later is a decision and not an accident (Real Testimonials pins core plus management at 5,200 characters). The template's own test pins its default at 700 (`get-context` plus `find-media`) and `import-image` at 800.

---

## 5. Write tools: rules every product learned the hard way

- **Audit.** `Abilities_Registrar::wrap()` reports every call to Shaped Kit's audit log through `Support\Audit::report()`, which fires `shaped_kit/mcp_tool_called` (product, ability, outcome, duration, argument names, never values). A tool registered any other way never reaches the log, and nothing says so.
- **Confirm destructive writes.** Use `Support\WriteGuard` for anything that deletes or overwrites: a preview, a single-use token bound to the user, the arguments and the record's state. Return `preview()` as it is: it answers a `WP_Error` when the arguments cannot be encoded (E190).
- **Match the editor's storage shape (E123).** Before a write tool ships, save the same record in the product's own editor and compare the stored row with what the tool writes: the keys, their order, the nesting. Real Testimonials stored a reviewer location in a flat key that nothing read; it never reached a card, and nothing failed. Pin the comparison in a Kit integration test, and make the read side tolerate rows the older tool wrote.
- **Clean addresses strictly (E126).** `esc_url_raw()` puts `http://` in front of text that has no scheme, so "not a url" is stored as a link. Pass every website, video, social or image address through `Support\Url::clean()`, which keeps only a full http(s) address.
- **Secrets are read-only through MCP (E184, E223).** A read tool shows a stored credential only through `Support\Secret_Mask::describe()` (`$allow_hint = false` for a licence key: no characters at all). A write tool refuses every key `Secret_Mask::is_secret_key()` matches, under `dropped` with reason `secret_read_only`, so a masked value the agent echoes back can never replace the real one.
- **Bound every page (E181, E182).** Read `page` and `per_page` with `Support\Pagination::from_input()` (pages above `Pagination::MAX_PAGE` are clamped) and give every `page` schema `'maximum' => Pagination::MAX_PAGE`. For a WP_Query list, report `Pagination::found_posts( $query, $args )` as `total`: WordPress counts nothing on an empty page, so `found_posts` alone reads 0 past the last page. Filter in the query, not in PHP after it, or `total` and the pages stop agreeing (E192).
- **Lowering the access capability widens `total`.** With the default `manage_options` every caller can open every item. A site that lowers it through the access-capability filter gets totals that include items the user cannot open; the items stay hidden (E193).
- **Register through `Abilities_Registrar::register()`**, never raw `wp_register_ability()` (E118).
- **Answer in one place.** The transport gate returns exactly `true` or `false`, never a `WP_Error` (E87).

---

## 6. The settings tab ("Quick Connect", E127)

The product's own settings screen shows how to connect an AI client. `Rest_Controller::summary()` is its state, and it already carries what the tab needs:

- `connect.user_login`: the signed-in user's login (not a secret). The tab fills it into the command, shell-quoted, so a login with a quote or a space cannot break or inject into the command the owner pastes into a terminal.
- `connect.passwords_available`: from core's `wp_is_application_passwords_available_for_user()`. A site that switches application passwords off sees a message and no command.
- `connect.kit_url` (with the Kit: the Kit's connect panel for this product) and `connect.passwords_url` (without it: the profile page's application passwords section). The password itself stays a placeholder: the product never handles one.
- `toolsets`: the opt-in switches, saved by posting `toolsets` with `enabled` to the same route.

The reference tab is the Kit's shared one (`assets/admin/SharedProductSettingsTab.js`, with `snippets.js` for the command text); Real Testimonials (`McpAgents.jsx`) and Location Weather (`mcp-agents.js`) are the two product-side versions. The tab is a front-end file in the product's repo, so it is not part of this folder.

---

## 7. Testing the module

| What | Where | Needs |
|---|---|---|
| The template's own behaviour (toolsets, map, telemetry, errors, REST, URLs, budget, placeholders) | `tests/phpunit/integration/ProductModuleTemplateTest.php`, in `composer test:integration` and CI | Nothing but the Kit |
| The template on the wire, through every real adapter copy (prompt, tools and toolsets as an MCP client sees them) | `composer test:adapter`; `SHAPED_KIT_PRODUCTS=template composer test:adapter` for the template alone (CI runs this) | Nothing but the Kit |
| A product on the wire | the same command, `SHAPED_KIT_PRODUCT={slug}` | A profile and a thin test class (below) |

**Adding a product to the wire suite.** The suite is data plus one shared body (`Builder_Adapter_Test_Case`):

1. Add `tests/phpunit/adapter/products/{slug}.php`: the product's slug, its editions (plugin folder, namespace, path constant), the wire names of its Builder tools and prompt, and which toolsets "Builder on" switches on. Copy `template.php` or `real-testimonials.php`.
2. Add `{Product}AdapterTest extends Builder_Adapter_Test_Case`, empty unless the product has tools of its own to check (Real Testimonials adds the bulk create tool).
3. Add its suite to `phpunit.xml.dist` (`adapter-{slug}`) and its slug to `SHAPED_KIT_PRODUCTS` in `tests/run-adapter-matrix.sh` if it should run by default.

**Product-side tests.** Real-WordPress tests live in the Kit (a product may have no PHPUnit of its own). Pin in the product or the Kit: the default-on size (section 4), every tool's toolset, every write tool's stored shape (section 5), and the free-only, pro-only and both-active cases.

---

## 8. Working in a product repository

- **Lint.** A product's `phpcs.xml` may exclude a folder such as `*/app/*`. On a machine whose site lives under a path with an `app` folder (Local by Flywheel does) that pattern matches every file: PHPCS reports "0 files in queue" and looks clean. Lint a copy outside that path (copy `src` and `phpcs.xml` to a temp folder and run the Kit's PHPCS there), compare with `HEAD`, and fix only the lines you wrote; product files carry old warnings.
- **Build.** Some products' `npm run build` runs the formatter first (`wp-scripts format`), which rewrites unrelated files. Use the product's block or admin build script that does not (for example `build:blocks`, or `build:admin` and its postbuild), rebuild the admin bundle of every repo you changed, and commit the tracked `dist/` output with the source. Check `git diff` for formatting you did not write before committing.
- **Backups.** To compare before and after, copy the file (`cp`) instead of `git stash` or `git checkout`, which can lose uncommitted work.
- **Free and Pro in step.** A change to shared code goes into both editions in the same turn, checked by a diff with the namespace, text domain and slug normalised (PRD §20). For the files the sync script manages, that is the script's job (section 9).

---

## 9. Keeping shared files in step (sync script and drift test)

`Support/` and `Media/` are shared code: every product keeps a copy, and a copy must equal the template once the names are mapped. Hand-copied shared code drifted within a week (PRD E181, `docs/wp-mcp-engine.md` §9.6), so the copies are written by a script and checked by a test.

| | |
|---|---|
| **Which files** | Listed per product in `shaped_kit_sync_products()` (`tools/sync-product-module.php`): Real Testimonials and WP Carousel take `Support/` and `Media/`; Location Weather takes `Pagination`, `Secret_Mask` and `WriteGuard` only. `Builder/` and every plumbing file stay product code. |
| **Change a shared file** | Edit the template, never a product copy. Test it here (`ProductModuleTemplateTest`, `ProductModuleMediaTest`), then sync. |
| **Sync** | `php tools/sync-product-module.php <real-testimonials\|wp-carousel\|location-weather> --diff` (dry run) then `--write`. Free and Pro are written together or not at all. |
| **What the script refuses** | A product repo not on `feat/mcp-module`; uncommitted or untracked changes in a target file; a folder that is missing or a symlink; a copy that does not parse, still holds a template name, or names a third-party product in a comment (C8). It never creates a folder and never runs `git checkout` or `git stash`. |
| **Check** | `SharedFileCopyTest` (in `composer test:integration`, local only because the product repos are private) fails on any drift and names the file and the command that fixes it. Run it on a clean product tree right before a product commit. |
| **Name mapping** | Namespace, text domain, transient prefix (`template_prefix_mcp_` to `spt_`, `wpcp_`, `splw_`), ability slug, `@package`, and the header lines. A placeholder the map does not know is refused, not shipped. |

Rules learned in the first rollout (Stage 1, 2026-10-22):

- **Change callers in the same commit.** A synced file can change an answer or a signature (`Secret_Mask::describe( $value, $allow_hint )`, `Pagination::found_posts()`, `Support\Audit::report()`). Update every caller in the product, run its suites, and commit both together.
- **A new shared file may need a class-map update.** A product that commits an optimized class map listing its MCP classes (WP Carousel Free) needs `composer dump-autoload -o`; check the diff adds only the new class.
- **Location Weather Free and Pro share one namespace**, so whichever edition loads first serves both: its copies must stay byte-identical (E216).
- **Watch the token budget.** A schema change in a shared helper's caller adds characters to every message; Real Testimonials keeps the page clamp in code only for that reason (E194).

---

## 10. Key Architectural Safeguards Enforced

- **Missing can_access() fix:** Permission checking for default tools uses `Permission_Gate::can_access()`, guaranteeing non-null ability registration even without custom entity tool classes.
- **Strict Booleans on Transport Gate (B5 / E87):** `Permission_Gate::transport()` returns strictly `true` or `false` (never `WP_Error`), preventing authentication bypasses on older adapters.
- **Strict Return on Toggle (B6 / E94):** `Permission_Gate::set_enabled()` returns `true` or `WP_Error` (never `false`), preventing failed toggles from being misreported as successful.
- **Output Buffering & Exception Shielding (E69 / E88):** `Abilities_Registrar::wrap()` buffers output to discard stray notices/deprecations and intercepts exceptions to prevent JSON-RPC protocol corruption.
- **Audit on every call (FR-S3):** `wrap()` reports each call, whatever its outcome, to the Kit's audit log, which stores argument names only.
- **Opt-in toolsets (D14 / E126):** nothing outside `core` and `management` is served until the owner opts in.
- **Context Ceiling (E17):** `Support\Pagination` automatically clamps query requests between 1 and 100 items (default 15).
