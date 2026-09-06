# Reviews & Testimonials implementation report

## Result and issue addressed

AJ Core now provides a reusable Google Business Profile review backend and a separate permanent Manual Testimonials model. AJNanda now presents those collections through two dynamic Gutenberg blocks, five editable patterns, and a shared accessible carousel. Previously the theme's static testimonial content did not provide managed review data, OAuth, synchronization, expiry, or reusable moderation.

Implementation is complete for the documented bounded provider and presentation workflows, with the external/API limitations below. Runtime, browser, live Google, test-suite, and build verification remain to be performed by the site owner. Syntax checks are not evidence that OAuth or end-to-end synchronization has been exercised.

## Architecture, data and UI

- **AJ Core functionality:** provider interface, official Google APIs, OAuth with PKCE and one-time user/session-bound state, encrypted credentials and temporary content, account/location discovery, manual and 28-day WP-Cron synchronization, named-lock exclusion, controlled retries, expiry, neutral featured selection/order, safe sync metadata/history, permanent testimonials, public PHP DTOs and authenticated status REST.
- **AJNanda presentation:** `ajnanda/google-reviews`, `ajnanda/manual-testimonials`, grid/list/carousel/featured layouts, global-style controls, real-data server-rendered editor previews, graceful missing-plugin/empty states, attribution/source links and unchanged text expansion.
- **Database:** no custom table or schema migration. Per-site `ajcore_reviews_*` non-autoloaded options, encrypted expiring WordPress transients, and the non-public `ajcore_testimonial` post type with `_ajcore_testimonial` metadata. See the [data inventory](reviews-testimonials.md#data-inventory-lifecycle-and-privacy).
- **Admin tabs:** Overview, Google Connection, Review Inbox, Featured Reviews, Manual Testimonials, Display Settings, Sync History under **AJ Core → Reviews & Testimonials**.
- **Lifecycle:** deactivation preserves data subject to transient expiry; disconnect revokes where possible and removes local Google credentials/content; uninstall removes integration options and keeps manual testimonials unless the owner explicitly opts into deletion.

## Files created by this implementation

Paths are relative to `/Users/sandip/Projects/ajwp`.

AJ Core:

```text
ajcore/README.md
ajcore/includes/settings-encryption.php
ajcore/modules/reviews/bootstrap.php
ajcore/modules/reviews/interface-ajcore-review-provider.php
ajcore/modules/reviews/class-ajcore-google-review-provider.php
ajcore/modules/reviews/class-ajcore-reviews-vault.php
ajcore/modules/reviews/class-ajcore-reviews.php
ajcore/modules/reviews/class-ajcore-reviews-admin.php
ajcore/modules/reviews/class-ajcore-testimonials.php
ajcore/modules/reviews/public-api.php
ajcore/uninstall.php
ajcore/phpunit-reviews.xml
ajcore/tests/reviews/bootstrap.php
ajcore/tests/reviews/fixtures.php
ajcore/tests/reviews/test-reviews.php
ajcore/docs/reviews-testimonials.md
ajcore/docs/reviews-implementation-report.md
```

AJNanda:

```text
ajnanda/blocks/ajnanda-blocks/carousel.php
ajnanda/blocks/ajnanda-blocks/carousel.js
ajnanda/blocks/ajnanda-blocks/carousel.css
ajnanda/blocks/ajnanda-blocks/reviews/loader.php
ajnanda/blocks/ajnanda-blocks/reviews/editor.js
ajnanda/blocks/ajnanda-blocks/reviews/view.js
ajnanda/blocks/ajnanda-blocks/reviews/style.css
ajnanda/patterns/reviews-google-section.php
ajnanda/patterns/reviews-manual-section.php
ajnanda/patterns/reviews-featured-summary.php
ajnanda/patterns/reviews-carousel.php
ajnanda/patterns/reviews-call-to-action.php
ajnanda/phpunit-reviews.xml
ajnanda/tests/reviews/bootstrap.php
ajnanda/tests/reviews/test-rendering.php
ajnanda/tests/reviews/carousel.test.js
ajnanda/docs/reviews-testimonials.md
```

## Existing files changed by this implementation

| File | Change |
|---|---|
| `ajcore/ajcore.php` | Load the reviews module; extract existing encryption helpers into a shared file without changing their legacy behavior |
| `ajnanda/functions.php` | Load the new block integration |
| `ajnanda/blocks/ajnanda-blocks/loader.php` | Route the existing slider through the shared accessible carousel and remove Swiper asset loading |
| `ajnanda/blocks/ajnanda-blocks/frontend.js` | Remove the replaced Swiper initializer |
| `ajnanda/README.md` | Link the managed reviews feature |
| `ajnanda/docs/AJNANDA-CAPABILITIES.md` | Advertise the blocks, shared carousel, and PHP-level AJ Core integration |
| `ajnanda/docs/README.md` | Add the feature documentation entry |
| `ajnanda/docs/patterns.md` | Document the five new editable patterns |

Existing static testimonial blocks/patterns remain available. The shared carousel preserves existing slider content and supports its loop, delay, pagination, fade/slide, and speed settings with documented bounds; Previous/Next are now always available. Native fade differs from Swiper's overlap implementation and should receive manual regression testing.

## OAuth setup and remaining Google Cloud configuration

1. Obtain the appropriate Google Business Profile API project approval and quota. Enable My Business API (review v4), Account Management, and Business Information APIs.
2. Configure the OAuth consent screen, authorized domains, applicable verification/privacy requirements, and a Web application OAuth client.
3. Copy the exact redirect URI displayed by AJ Core into the OAuth client configuration.
4. Save the client ID/secret in AJ Core, connect the authorized Google account, load business accounts and locations, and save the intended location.
5. Test Connection, Sync Now, then feature reviews explicitly in Review Inbox.

These steps require the owner's Google project and credentials. No live account was connected. Full instructions and troubleshooting are in [the AJ Core guide](reviews-testimonials.md#requirements-and-google-setup).

## Sync, security, and accessibility protections

Successful retrieval creates a complete snapshot with **28½-day maximum expiry** measured from retrieval start. Regular synchronization is **every 28 days**. Failed refreshes never renew old content's age. Pagination must finish before publication, duplicate IDs are collapsed, absent source reviews disappear after a successful replacement, and featured order survives for still-present IDs. Temporary errors have at most five increasing retries per scheduled/manual cycle. Concurrent jobs and configuration mutations use a shared per-site database lock.

Secrets and Google content are encrypted using the existing sodium mechanism, with a fail-closed adapter. Admin mutations require capability and nonce checks; OAuth state adds one-time, user/session/site binding and PKCE. Fixed HTTPS API endpoints, disabled credential-bearing redirects, safe fixed error codes, allowlisted public DTOs, and no raw API-body logging reduce exposure. Public reads reject expired/malformed rows; manual queries exclude drafts/private/unfeatured/passworded records and private notes.

Google content is not saved into post content, media, schema, feeds, ordinary post REST responses, search results, or discovery/sitemap records. The theme uses no-store/cache-bypass signals and removes an open collection at expiry. Host caches, backups, and cron still require owner configuration.

Carousel protections include visible Previous/Next, optional pagination, track-only keyboard navigation, native touch/swipe, reachable offscreen content, visible focus, minimum target sizes, pause/start, pause on focus/hover/hidden tabs, and reduced-motion handling. Autoplay defaults off. No new third-party carousel or font dependency was introduced.

## Tests added

- **22 AJ Core PHPUnit cases:** encryption/redaction, corrupt-key behavior, original-text normalization, deduplication, explicit selection, refresh/removal, expiry without cron, idempotent 28-day scheduling, failed-sync retention, bounded backoff, reentrant and separate-connection locking, state binding/replay, permissions/nonces, malformed filtering, disconnect, manual visibility/notes, REST permission/redaction, paginated failure, token refresh, empty revocation responses, and correctly encoded OAuth parameters.
- **9 AJNanda PHPUnit cases:** registration, inactive-plugin fallback, escaping/attribution/source links, expiry, empty/unfeatured editor states, layouts/full-text expansion, manual/Google separation, feed/search/REST exclusions, and synced-pattern/shared-slider integration.
- **6 Node cases:** default no-autoplay/navigation, keyboard targeting, hover/focus/visibility/pause, reduced motion, touch/boundary controls, and detached-carousel timer cleanup.

The PHPUnit suites use the standard WordPress test library, load only relevant production modules, and require a disposable test database. HTTP defaults to a local error; only synthetic mocked responses are allowed. No live Google HTTP is made by tests. Node uses its built-in test runner and a small DOM harness; actual rendering/touch/screen-reader behavior still needs browser verification.

## Commands run and observed results

Read-only inspection used `pwd`, `ls`, `rg`, `find`, `cat`, `sed`, `head`, `tail`, `git status --short`, `git diff`, and `git log -1` in the two repositories. Official Google documentation was consulted. Files were written using patches and small local Python edits.

Verification commands run:

```sh
php -v
php -l ajcore/modules/reviews/class-ajcore-reviews-admin.php
php -l ajcore/modules/reviews/class-ajcore-google-review-provider.php
git -C /Users/sandip/Projects/ajwp/ajcore diff --check
git -C /Users/sandip/Projects/ajwp/ajnanda diff --check
```

A Python wrapper invoked `php -n -l <file>` for all **25 relevant PHP files** and `node --check <file>` for all **5 relevant JavaScript files**, including new tests. **All syntax checks passed.** An earlier admin-pagination parenthesis error was corrected before the passing checks. Diff whitespace checks passed. These checks do not execute WordPress or contact Google.

**Test-suite results: not run. Build results: not run. Browser/live OAuth results: not run.** Standing workspace instructions reserve tests, builds, Docker, environment refreshes, and final browser verification for the owner. No install, migration, seed, deployment, package upgrade, or container action was performed by this assistant. The source is plain PHP/JavaScript/CSS and does not require a new transpilation build.

## Exact manual verification commands

First configure an already installed WordPress PHPUnit test library and test database. Set `WP_TESTS_DIR` to its real path; the illustrative path below must be replaced. Use your existing PHPUnit executable. No installer is included or run.

```sh
export WP_TESTS_DIR=/absolute/path/to/wordpress-tests-lib
phpunit -c /Users/sandip/Projects/ajwp/ajcore/phpunit-reviews.xml
phpunit -c /Users/sandip/Projects/ajwp/ajnanda/phpunit-reviews.xml
AJCORE_TESTS_DISABLED=1 phpunit -c /Users/sandip/Projects/ajwp/ajnanda/phpunit-reviews.xml
node --test /Users/sandip/Projects/ajwp/ajnanda/tests/reviews/carousel.test.js
```

The inactive-plugin PHPUnit run intentionally skips cases needing AJ Core. To use a non-sibling AJ Core checkout, set `AJCORE_TEST_PLUGIN_DIR` to that repository's root for the theme tests.

After your own local deployment and OAuth setup, these standard WP-CLI commands inspect schedules and exercise the live integration. Replace the site path. The `cron event run` commands perform real synchronization/cleanup and are separate from the mocked automated tests:

```sh
AJ_REVIEWS_WP_PATH=/absolute/path/to/your/wordpress
wp --path="$AJ_REVIEWS_WP_PATH" cron event list --fields=hook,next_run_gmt,schedule
wp --path="$AJ_REVIEWS_WP_PATH" cron event run ajcore_reviews_sync
wp --path="$AJ_REVIEWS_WP_PATH" cron event run ajcore_reviews_cleanup
```

Manually verify: authorization/callback and account/location selection; neutral feature/unfeature/order; successful and failed refresh; expiry and removal; disconnected and plugin-inactive states; published versus draft manual records; full-text/source/translation behavior; each block/pattern and existing sliders at mobile/desktop sizes; keyboard, touch, focus, pause, reduced motion, and JavaScript-disabled behavior. Confirm site/CDN/cache bypass, core transient cleanup, backup exclusions, and discovery/REST/feed exclusions. These manual scenarios have not been marked passed.

Do not treat `bin/build-release.sh` as a harmless verification command: the existing scripts can commit, push, publish, and deploy. Run the normal owner-controlled packaging/release process only when you intend those actions.

## Unimplemented optional items, external blockers, and policy follow-up

- **Individual review/source/reporting/translation fields:** the documented GBP review resource does not provide several requested optional fields, especially an individual public review URI. Available values are retained; otherwise they remain empty. The business Maps link is labeled accurately, never presented as an individual review link. Google/API support is needed to close this limitation without inventing data or changing providers.
- **Policy:** confirm that the intended public curated display is permitted by Google's Business Profile limited-storage/performance-only and no-manipulation/aggregation rules. This implementation does not establish Google approval. See the official sources and explanation in [the policy section](reviews-testimonials.md#google-policy-and-api-limitations).
- **Host retention:** regular cron, external object-cache TTLs, database-shadow cleanup when changing cache backends, page/CDN cache exclusions, and backup/staging retention require owner verification. No WordPress plugin can guarantee timely physical deletion on a stopped server or remove unconfigured external backups.
- **Large collections:** the documented time/page/size bounds fail closed; no resumable multi-request sync worker is included.
- **Optional combined block:** deliberately omitted to keep Google and manual content distinct. A grouped public PHP collection interface is provided.
- **Optional custom WP-CLI command:** not added because AJ Core had no existing command convention; standard WP-Cron CLI execution is documented.
- **Verification:** test suites, production-version matrix, browser accessibility/regression checks, and live Google flow remain unverified. No new build pipeline or dependency installation was added.

## Change discipline

No live credentials were added; fixture strings are synthetic and nonfunctional. This assistant did not commit, push, publish, deploy, or run release scripts. Repository history advanced externally while the task was open, including release commits incorporating earlier work. Those external actions are not claimed as this assistant's work or as verification evidence. Unrelated/concurrent edits were preserved. No version bump was performed by this assistant.
