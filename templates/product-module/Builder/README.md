# AI Builder MCP Module Template (`Builder/`)

This directory contains the drop-in reference implementation for the **Build with AI & Ready Patterns** MCP engine. It provides the unified block design, validation, placement, and ready patterns architecture established in Real Testimonials (AI Builder Phases B1–B5).

A sibling product (such as WP Carousel in Phase 8) copies this folder and supplies **only its block data and presets**, requiring zero custom plumbing for AI block creation or WriteGuard safety rails.

---

## 1. Directory Structure

```text
templates/product-module/Builder/
├── README.md               # This adoption guide and token checklist
├── Catalog.php             # Schema lookup, allowed enums, design rules, worked examples
├── ValueValidator.php      # Deep attribute validation with dotted path drop reasons
├── BuildPipeline.php       # Unified finishing pipeline: sanitize -> defaults -> patch
├── AttributePatch.php      # Presets, layout overrides, prompt column extraction
├── BlockInserter.php       # Unique ID generation, group wrapping, safe placement & replacement
├── PatternLibrary.php      # Remote pattern fetching, cache auto-healing, KSES sanitization
├── Builder_Tools.php       # Callbacks for get-block-catalog, validate-block-attributes, insert-block
├── Pattern_Tools.php       # Callbacks for list-ready-patterns, insert-ready-pattern
└── Builder_Abilities.php   # Helper to wire tool definitions & toolset map into Abilities_Registrar
```

---

## 2. Adoption Checklist (Search & Replace)

When copying this folder into a product (e.g. `src/Mcp/Builder/` or `includes/Mcp/Builder/`), perform the following replacements:

| Step | Find | Replace With | Scope & Notes |
|:---:|---|---|---|
| **1** | `ShapedPlugin\Template\Mcp\Builder` | `ShapedPlugin\{Product}\Mcp\Builder` | Target PHP namespace across all files in this folder. |
| **2** | `ShapedPlugin\Template\Mcp` | `ShapedPlugin\{Product}\Mcp` | Base MCP module namespace (for `Module`, `WriteGuard`, `Pagination`). |
| **3** | `template-slug` | Product slug (kebab-case) | MCP endpoint identifier & wire server ID (e.g. `wp-carousel`). |
| **4** | `template_prefix` | Product prefix (snake_case) | Transients, hooks, and option prefixes (e.g. `wpcp`). |
| **5** | `template-domain` | Product text domain | Used in all internationalization functions `__()`, `_e()`. |

---

## 3. Product-Specific Data Configuration

Per PRD §20, **code does not differ across products; only data differs**:

### A. In `Catalog.php`:
1. `PREFIX`: Update with the product's Gutenberg block prefix (e.g. `'wp-carousel/'`).
2. `BLOCKS`: Array of bare block slugs supported by Build with AI (e.g. `array( 'carousel', 'gallery' )`).
3. `LAYOUT_KEYS`: Initial fallback layout attributes required before a fresh block leaves its picker.
4. `DENIED_KEYS`: Attributes the model must never modify (custom CSS/JS, identity, query limits).
5. `ENUMS`: Allowed options for block settings (alignment, pagination styles, etc.).
6. `DESIGN_RULES`: Human-readable guidelines included in the prompt for AI clients.

### B. In `BlockInserter.php`:
1. `GROUP`: Set bare slug if your blocks require a composite wrapper (e.g. `'carousel-group'`), or `''` if standalone.
2. `ID_PREFIX`: Unique ID prefix (e.g. `'wpcp-slide-'`).
3. `ADDONS_BEFORE` / `ADDONS_AFTER`: Toggles that automatically inject child blocks.

### C. In `PatternLibrary.php`:
1. `PRO_PATTERNS_INSERTABLE`: `true` in Pro edition, `false` in Free edition.
2. `LIST_URL` & `SINGLE_URL`: Product remote demo pattern library REST endpoints.
3. `CACHE_DIR`: Directory under `wp-content/uploads/` where the pattern catalog is cached.

---

## 4. Wiring Into `Abilities_Registrar.php`

There is nothing to wire. `Abilities_Registrar::definitions()`, `prompt_definitions()` and `ability_toolset_map()` pick up `Builder_Abilities` as soon as this folder is present (`has_builder()`), and leave it out when it is not. Put the product's own tools in `core_definitions()` and `core_toolset_map()`.

All Builder tools and the prompt are in the opt-in `builder` toolset, so a fresh site serves none of them until the owner switches it on (E126). The `block` argument of every tool and of the prompt comes from `Builder_Tools::block_schema()`, an enum of `Catalog::BLOCKS`, so the adapter refuses an unknown block before any callback runs. Change the list in `Catalog::BLOCKS` and every schema follows.

The Build with AI modal in the editor needs `Rest_Controller::builder_client()` for its "use your own AI client" hand-off. Set `Rest_Controller::SETTINGS_PATH` to the product's MCP settings tab.

The template runs on the wire with these placeholder blocks (`composer test:adapter`, product `template`), so the whole Builder path is checked before any product copies it.

---

## 5. Architectural & Security Safeguards Enforced

- **Level 1 WriteGuard Confirmation (CH10 / BL2):** `insert-block` and `insert-ready-pattern` require a WriteGuard token preview/confirmation round-trip for modifying existing posts.
- **One-Call Drafts (BL1):** `target.new_draft` allows instant 1-call draft page creation with an idempotency key.
- **KSES Protection (BL6):** Users without `unfiltered_html` cannot insert patterns carrying unallowed markup.
- **Block Denylist (BL6):** Blocks raw HTML (`core/html`), shortcodes (`core/shortcode`), and external templates.
- **Dotted-Path Rejection (BL18):** Inner drops in composite settings objects are reported with detailed reasons (`invalid_value`, `unknown_key`, `denied_key`, `not_editable`).
- **Gradient Injection Protection (BL19):** Strictly enforces single-layer CSS gradients, forbidding malicious `url()` injections.
