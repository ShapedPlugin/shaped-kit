# Testing Shaped Kit — what to test, and what not to

Test **risk**, not lines of code. A test earns its place by catching a bug that would hurt someone and that nobody would notice in time. Coverage percentage is not a goal here.

Commands and setup: see `CLAUDE.md` → "Tests and lint".

## The rule: write a test when a bug would be one of these

| # | The bug would… | Examples in this plugin |
|---|---|---|
| 1 | **Expose data or bypass a permission** | A tool's `permission_callback`, the transport gate (kill switch + base capability), REST route caps and nonces, `get-settings` returning a secret, `find-*-blocks` returning a private post |
| 2 | **Change or destroy data wrongly** | Destructive confirmation tokens (replay, wrong user, changed arguments, changed state), idempotency keys, the no-downgrade guard on beta installs |
| 3 | **Take a site down** | The adapter loader's step-aside decisions (a wrong call fatals on every request), activation guards, the PHP 7.4 / WordPress 6.9 gates |
| 4 | **Install unverified code** | Catalog signature check, SHA-256 check before unpacking, host allowlist, catalog serial/expiry (replay) |
| 5 | **Fail silently** | Anything whose failure produces no error: the audit log not writing, the default server quietly coming on, a filter dropping our tools from `tools/list` |
| 6 | **Come back after a fix** | Every bug fixed once gets one test that would have caught it |

If a bug fits none of these, **don't write the test**.

## Don't write tests for

- **Things that fail loudly and cheaply.** A typo in a menu label, a wrong icon, a broken layout. You see it the first time you open the page.
- **WordPress core or the bundled adapter.** Don't test that `register_rest_route()` registers a route, or that the adapter speaks JSON-RPC. Test *our* decision, such as which capability the route requires, not their machinery.
- **Pass-through code.** A method that only calls one WordPress function and returns the result.
- **Plain data.** Constants, config arrays, ability label text, descriptions.
- **React presentation.** Markup, CSS, which component renders where. *Do* test React logic that decides something: what the dashboard shows when an Application Password is unavailable, or the masking of a key before display.
- **One-off environment facts.** "Which adapter copy loads on this site with Rank Math" is a **probe** (PRD C3), recorded in the PRD, not a permanent test.

## How to write the ones that are worth it

1. **One test per behaviour, not per method.** `test_editor_cannot_call_get_settings`, not `test_get_settings_method`.
2. **Test the edge, not just the happy path.** For each requirement, the valuable cases are usually the wrong user, the expired token, the empty input, the pre-release version string, the second concurrent call. PRD §19 lists them; each phase picks its rows.
3. **Every test must be able to fail.** After writing it, break the code it protects (delete the guard, flip the condition) and watch it fail, then restore. A test that passes either way proves nothing. Phase T3 did this with the default-server filter.
4. **Pick the cheapest layer that can see the bug:**
   - `tests/unit` scripts: the loader, where classes and constants can't be undone, so each scenario needs a fresh PHP process.
   - `tests/phpunit/unit`: pure decisions in `src/` with no WordPress calls.
   - `tests/phpunit/integration`: anything that needs real WordPress (abilities, the MCP server, REST permissions, options, transients).
5. **Realistic test doubles.** A fake must be able to return something *different* from what was asked, or it hides the bug (for example, a fake EDD that echoes the requested version back can never show a version mismatch).
6. **Name tests as sentences** a reviewer can read without the code: `test_token_from_user_a_is_refused_for_user_b`.

## Per-phase checklist

Before a phase is committed, its report states:

- which PRD §19 rows got a test, and which were probed instead (with the result);
- which tests were mutation-checked (rule 3);
- what was deliberately **not** tested, and why (one line each).

## Where this plugin's real risk is

In rough order. Spend test effort here first:

1. Permission and transport gates on every product endpoint.
2. Destructive confirmation (FR-S2).
3. The adapter loader (built: 54 + 21 + 2 cases).
4. Store-track verification: signature, hash, allowlist, no-downgrade.
5. Secret masking and private-post filtering in read tools.
6. Audit log: writes keys only, never values; never breaks a tool call.

The dashboard's look, menu wiring, connect-panel snippets text and settings card layout sit at the bottom: check them by opening the page.
