# Media tools: `find-media` and `import-image`

Gives an AI client a media library id for an image, so the product's write tools can take an id (a reviewer photo, a company logo, a carousel slide) and never fetch anything themselves (PRD D18, D22).

| Tool | Toolset | What it does |
|---|---|---|
| `{slug}/find-media` | `core` (on by default, read-only) | Media library images (or videos) by title, alt text, file name or id. Returns id, short plain text, size and URLs. |
| `{slug}/import-image` | `media` ("Media Import", off by default) | Downloads up to 5 https images into the media library and returns their ids. This site then fetches addresses from the internet, so the owner switches it on. |

## Adopting it

1. Copy the whole folder to `src/Mcp/Media/` (or wherever the product's MCP module lives).
2. Replace the namespace `ShapedPlugin\Template\Mcp` and the text domain `template-domain`, as for every template file. Nothing in the folder uses `template_prefix`.
3. In `Abilities_Registrar`:
   - a template-shaped registrar picks the folder up by itself (`has_media()`): nothing to wire;
   - an older registrar (Real Testimonials) merges `Media_Abilities::definitions()` into `definitions()`, `Media_Abilities::toolset_map()` into the map, and lists a `media` toolset.
4. Point the product's own write tools at it: the description of an attachment-id field says where an id comes from ("from find-media or import-image"), and the drop reason for an id that is not an image says the same.
5. Add both tools to the Kit's `assets/admin/toolsCatalog.js` (FR-K10).
6. Keep the copy in step: the Kit's `RealTestimonialsMediaIntegrationTest` compares each copied file with this folder, name for name. Add the product to it, or to an equivalent test.

## Files

| File | What it holds |
|---|---|
| `Remote_Guard.php` | Pure checks: the address form, public IPv4 only (core's ranges, without the filter other plugins can use), the size cap, the cURL options of a pinned hop, the address hash. |
| `Remote_Fetch.php` | The one guarded request: `wp_safe_remote_get()` plus three listeners at PHP_INT_MAX that restore certificate checks, pin each hop to its checked address, and check each redirect. |
| `Image_File.php` | The file judged by its bytes: JPEG, PNG, GIF, WebP; 25 megapixels; GIF 2 MB. |
| `Media_Tools.php` | The two tools: permissions, reuse of an earlier import, the per-address lock, storage under our own name and text, the multisite quota, the 20-second deadline. |
| `Media_Abilities.php` | Definitions and toolset map, picked up by `Abilities_Registrar`. |

## Rules that must survive a copy

Each has a test in the Kit (`ProductModuleMediaTest`, mutation-checked) and a row in PRD §19.8.

- **No other plugin can relax the request.** Core's own check can be switched off by filters, so the address is checked without them: every A record public, IPv4 only, the site's own host refused. The hop is then pinned with `CURLOPT_RESOLVE`, and the redirects are checked one by one (at most 3).
- **The listeners run last.** Certificate checks are restored on `requests-requests.before_request`, because Requests applies its `verify` option after `curl.before_send`; a live probe accepted an expired certificate before this. The pin and the other cURL options are set on `http_api_curl`. A request that never reached that hook (another plugin answered it, or no cURL) is thrown away.
- **Size is counted raw.** Decoding is off, because `MAXFILESIZE` counts compressed bytes. The cap is the smallest of 5 MB, the site's upload limit and, on multisite, `fileupload_maxk`.
- **No remote text is stored.** The title, caption and alt text written inside a file are replaced by ours. `find-media` returns plain text cut to 120 characters, never captions or descriptions.
- **Search reads what the answer shows.** `find-media` matches every word against title, file name and alt text, and nothing else: a caption or description is never returned, so a match on it could not be explained to the client (E175). Core's search cannot do this (it reads caption and description, never alt, and `posts_search` is off under `suppress_filters`), so `Media_Tools::search_ids()` runs one prepared query with `esc_like()`. Pages stop at `MAX_PAGE` (E176).
- **The address is never stored or echoed.** A signed address carries a credential in its query string: only its SHA-256 goes on the attachment (`_shaped_mcp_source_sha256`, shared by every product, so one address is one attachment on a site).
- **Permissions:** both tools need `upload_files` on top of the product's base capability, plus `read_post` on each item. An id the user cannot read answers the same as a missing one.

## Testing

Never use `@runInSeparateProcess` in the Kit's integration suite: the child process reinstalls the shared test database, and every later test fails (E160). To test something that needs a constant, inject it instead, as `Remote_Fetch::proxy_applies( $url, $proxy )` does.

A download can only succeed against a real public host, which a test suite must not call. So `Media_Tools::store()` is tested on a real file, and the full fetch path is proven by the live probe recorded in PRD §19.8.
