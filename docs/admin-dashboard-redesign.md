# Administrator dashboard redesign

## Scope and integration

Only `owner_admin` on `index.php` receives the new dashboard. Existing authentication, permission checks, module definitions and marketing queries run unchanged before rendering. Other roles retain the original dashboard; internal module pages are unchanged.

The shared header adds the `.ess-dashboard` body scope only when this view is active. All new CSS is scoped to that class. The dashboard preserves the shared presence, notification and clock hooks, existing module destinations, Packing List unread badge and System Issues counts. Recent activity uses existing notification records; empty or unavailable data is not replaced with fabricated metrics.

## Inspiration maintenance

`shared/ess-inspiration.php` contains 60 KJV excerpts and 61 sourced quotations. Each quotation includes its author, original work and source URL. Texts were checked against the original Project Gutenberg editions. Keep quotations short, attributed and source-verified when updating the catalog.

Today’s Thought displays only one entry: a verse on even civil-day indexes and a quote on odd indexes. Selection uses the date in `Africa/Windhoek`, with `cycle = floor(day / 2)`. Verses use cycle modulo 60; quotations use `(cycle * 7 + 13) modulo 61`. This allows every entry to appear without skipping alternate catalog entries. The combined selection cycle is 7,320 days. PHP and the browser use the same formula; the display updates after midnight or when the page becomes visible again. Refreshing does not randomize the selection.

## Verification

- PHP lint and JavaScript syntax checks pass.
- Run `php tests/ess-dashboard-inspiration.php` for catalog size, duplicate detection, stable selection, timezone boundaries and cycle coverage.
- Run `php tests/ess-dashboard-render.php` for role isolation, escaping, real metric rendering and unavailable-data handling.
- Browser checks covered 1920, 1280, 1024, 768 and 390px widths: no horizontal page overflow, responsive equal-card grids and Jost on visible text.
- Search, empty results, all nine module links, notification keyboard preview and mobile More/Escape/focus return were checked. Browser reported no errors.

## Review status

The owner approved the dashboard and authorised publication on 11 September 2026, including the fixed full-height sidebar, smaller circular avatar and alternating Today’s Thought. The local review fixture is outside the repository and uses a captured dashboard snapshot; production rendering continues to use existing live queries. Do not publish the fixture or snapshot. Other pages remain outside the redesign scope.
