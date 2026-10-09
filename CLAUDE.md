# Shaped Kit — agent instructions

Shaped Kit is ShapedPlugin's companion plugin. It loads the WordPress MCP Adapter, hosts the "AI & MCP" dashboard, and installs and updates ShapedPlugin products. The design lives in `docs/PRD/shaped_kit_prd.md`. `docs/` is gitignored and exists only on the owner's machine; read it before planning or writing code.

## Binding constraints (PRD §0)

1. **No guessed code.** Every WordPress function, hook, class, option name, argument order and return value is read from source before code uses it: core in the local site, `libs/mcp-adapter/`, or the product's own code. Name the `file:line` you read in the turn that writes the code. If it can't be checked, don't use it; record the open point in the PRD.
2. **Reference implementation first.** For any feature FluentCRM, FluentCart or FluentHub already has, read their version first and copy its shape. FluentHub is `https://github.com/WPManageNinja/fluent-toolkit`, not installed locally: clone it to a scratch directory to read it, never onto the site. PRD §0 maps each feature to the exact reference file. Any difference needs a written reason.
3. **Probe, don't reason.** An edge case is checked only when it has been run: a unit test, or a live probe on `http://localhost:10003` that writes nothing to the database, stubs outbound HTTP, and snapshots `wp_options` before and after.
4. **Edge check before commit.** Run the phase's rows in PRD §19. Report what was checked and found fine, not only what was fixed. Add new cases to §19.
5. **Other plugins' bugs are reported, not fixed** here: they go in `docs/EXISTING_PLUGIN_BUGS.md`.

## Code rules

- PHP 7.4 floor, WordPress 6.9+ for the MCP feature, WordPress Coding Standards.
- REST only (`register_rest_route` with an explicit `permission_callback` and a nonce for browser callers). No new `wp_ajax_*`.
- Admin UI is a React mount point; PHP renders no markup beyond it.
- `libs/mcp-adapter/` is an unmodified upstream copy. Replace it on upgrade, never patch it. Kit-side workarounds go in `src/Mcp/AdapterBootstrap.php`.
- The Kit never writes another plugin's options (MCP toggles go through product-registered handlers; licenses stay with each Pro edition).

## Tests and lint

**What to test is decided by `tests/TESTING.md`:** risk-based. Write tests only where a bug would expose data, change data wrongly, take a site down, install unverified code, fail silently, or come back after a fix. Don't test labels, layout, WordPress core, the adapter, or pass-through code. Every test is mutation-checked.

Always use the Kit's own isolated toolchains (`tools/phpunit`, `tools/phpcs`), never a global binary.

| Command | What |
|---|---|
| `composer tools:install` | Install both toolchains (first run on a fresh checkout) |
| `composer test:scripts` | Dependency-free loader tests (`tests/unit/*.php`), a fresh PHP process per scenario |
| `composer test:unit` | PHPUnit, no WordPress (`tests/phpunit/unit`) |
| `composer test:integration` | PHPUnit inside real WordPress 7.1.3 (`tests/phpunit/integration`); skips cleanly without the test library |
| `composer test` | All three |
| `composer lint` / `lint:fix` | WPCS + PHPCompatibilityWP 10.0.0-alpha2 (the stable 9.3.5 cannot see PHP 8 syntax) |

The integration suite uses `.wordpress/` (core 7.1.3) and `.wordpress-tests-lib/` (wordpress-develop 7.1.3, with a machine-local `wp-tests-config.php`), both gitignored. Its database is `shaped_kit_tests` on Local's MySQL socket. The test library drops and recreates every table in that database on each run, so never point it at `local`.

## Delivery

Phase by phase, one file at a time, as listed in PRD §13. Commit only when the owner says so. No AI attribution in commit messages.
