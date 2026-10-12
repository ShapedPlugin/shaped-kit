# Sibling Product MCP Module Template

This directory contains the drop-in reference implementation for adding Model Context Protocol (MCP) support to ShapedPlugin products (e.g. Real Testimonials, WP Carousel, etc.).

It provides the complete generic MCP plumbing modeled directly after the Location Weather pilot (`location-weather/includes/Mcp/`), excluding product-specific business tools.

---

## 1. Directory Structure

```text
templates/product-module/
├── README.md               # This checklist and adoption guide
├── Module.php              # Lifecycle bootloader, WP 6.9+ floor check, lazy registration
├── Permission_Gate.php     # Transport authentication, capability checks, on/off kill-switch
├── Abilities_Registrar.php # Registers get-context, handles try/catch safety & ob buffering
├── Server.php              # Instantiates isolated /wp-json/{slug}/mcp endpoint
├── Kit_Registration.php    # Discovers product in Shaped Kit dashboard via filters
├── Rest_Controller.php     # REST endpoints for product settings card (/wp-json/{ns}/mcp)
├── Support/
│   ├── Pagination.php      # Clamps tool lists to 15-100 items with envelope metadata
│   └── Secret_Mask.php     # Masks secrets: configured / not configured, or the last 4 characters
└── Tools/
    └── Context_Tools.php   # Universal {slug}-get-context orientation tool
```

---

## 2. Adoption Checklist (Search & Replace)

When copying this folder into a product (e.g. into `src/Mcp/` or `includes/Mcp/`), perform the following 6 replacements across the files in order:

| Step | Find | Replace With | Scope & Notes |
|:---:|---|---|---|
| **1** | `ShapedPlugin\Template\Mcp` | `ShapedPlugin\{Product}\Mcp` | Target PHP namespace across all `.php` files (e.g., `ShapedPlugin\Testimonial\Mcp`). |
| **2** | `template-slug` | Product slug (kebab-case) | MCP endpoint identifier & wire server ID (e.g., `real-testimonials`, `wp-carousel`). Edition-neutral (no `-pro`). |
| **3** | `template_prefix` | Product prefix (snake_case) | Used in options, transients, error codes, and hook names (e.g., `sp_testimonial`, `wpcp`). |
| **4** | `template-domain` | Product text domain | Used in all internationalization functions `__()`, `_e()`, etc. |
| **5** | `template/v1` | Product settings REST namespace | In `Rest_Controller.php` (e.g., `sp-testimonial/v1`). |
| **6** | `Module.php` Constants | Product metadata constants | Update `NAME`, `EDITION`, `VERSION`, and `ENTRY_POST_TYPE` in `Module.php`. |

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

---

## 3. How Pro Joins Free (PRD §9)

ShapedPlugin Pro products are standalone plugins (or can be active alongside Free). The template accommodates this seamlessly:

1. **One Endpoint per Product:** Both Free and Pro share the same `SLUG`, endpoint (`/wp-json/{slug}/mcp`), and kill-switch option (`{prefix}_mcp_enabled`).
2. **One Owner Rule is NOT enforced by this template.** If Free and Pro are both active and both load this module, they register the same server and abilities. Location Weather and Real Testimonials are safe because Free goes inert while Pro is active (PRD §9). Before using the template in a product, confirm its Free edition does the same. If it does not, add the `{PREFIX}_MCP_MODULE_OWNER` guard from PRD §9 Case A to `Module::should_load()` and test Free-only, Pro-only and both.
3. **Registering Pro Tools:** Pro editions add abilities without modifying core template files by hooking:
   - `{prefix}/mcp_loaded`: Action fired during `Abilities_Registrar::register_abilities()`. Register each Pro tool here through `Abilities_Registrar::register()`, **not** raw `wp_register_ability()`: the registrar adds the required category, hides the tool from core's REST API and the default server, and wraps it with the exception and output guards.
   - `{prefix}/mcp_ability_names`: Filter fired in `Abilities_Registrar::names()`. Append the Pro tool names (e.g., `{slug}/pro-tool-name`) to the array.

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
```
Raw `wp_register_ability()` without a `category` returns null, so the tool silently does not exist. With a category it registers, but a top-level `annotations` key is ignored (core reads them from `meta`), so the read-only hints are lost. Both probed on WordPress 7.1.3.

---

## 4. Key Architectural Safeguards Enforced

- **Missing can_access() fix:** Permission checking for default tools uses `Permission_Gate::can_access()`, guaranteeing non-null ability registration even without custom entity tool classes.
- **Strict Booleans on Transport Gate (B5 / E87):** `Permission_Gate::transport()` returns strictly `true` or `false` (never `WP_Error`), preventing authentication bypasses on older adapters.
- **Strict Return on Toggle (B6 / E94):** `Permission_Gate::set_enabled()` returns `true` or `WP_Error` (never `false`), preventing failed toggles from being misreported as successful.
- **Output Buffering & Exception Shielding (E69 / E88):** `Abilities_Registrar::wrap()` buffers output to discard stray notices/deprecations and intercepts exceptions to prevent JSON-RPC protocol corruption.
- **Context Ceiling (E17):** `Support\Pagination` automatically clamps query requests between 1 and 100 items (default 15).
