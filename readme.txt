=== Database Search & Replace ===
Contributors: ibrahimhajjaj
Tags: search replace, database, migration, serialized, elementor
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find and replace across your database with a free sampled visual preview, a serialized and Elementor-safe engine, optional backup and one-click undo.

== Description ==

Changing a domain, moving off a staging URL, or switching to HTTPS means rewriting text all through your database. Most tools do it blind: you type into a box, press go, and hope. When the value is serialized (theme settings, Elementor layouts, WooCommerce data) a plain replace miscounts the length prefix and corrupts the row, and there is no way back.

Database Search & Replace is built the other way around. You preview representative matches before anything is written, can take a safety snapshot, and can undo unchanged cells while that snapshot is retained. Nothing about that is held behind an upgrade.

= What makes it safe =

* **Visual preview, always free.** Up to 20 representative matches per table, grouped by table and column, with the exact characters that change highlighted. Review them, then decide.
* **Serialized-data safe.** The engine walks serialized arrays and objects, replaces inside them, and recalculates the `s:N` byte-length prefixes so the value stays valid.
* **Handles the case other tools miss.** Page builders store URLs as JSON inside serialized data, with escaped slashes (`http:\/\/`). A search for `http://` never matches that, so the old link silently survives. This engine decodes the JSON, replaces, and re-encodes in the original style. Base64-wrapped values are handled the same way.
* **Optional snapshot and one-click undo.** When enabled at confirmation, affected cells are copied before each write. Undo restores cells that still contain the applied value and reports later edits as conflicts.
* **Protected by default.** Network-global, user, and login tables are excluded from site-level runs. Post GUIDs are left alone unless you ask, because changing them breaks feeds and comments.

= Built for real sites =

* **Background batching for large databases.** Work runs in bounded batches through Action Scheduler and resumes from durable progress after each completed batch.
* **Regex, case sensitivity, and exclusions** for precise control, all optional behind an Advanced panel so a first-time user is never overwhelmed.
* **Target specific tables** while safely discovering their text columns, or scan every eligible site table.
* **Automatic cache purge after a run.** Regenerates Elementor CSS and clears WP Rocket, LiteSpeed Cache, and W3 Total Cache so the site does not keep serving old URLs.
* **Downloadable CSV of retained preview excerpts**, streamed through an authorized admin route when change logs are enabled.
* **WP-CLI** for scripted and staged migrations.
* **Multisite aware.**

= A note on the paid product =

The search and replace here is complete and free. If you are moving an entire site rather than rewriting text, SafeGuard handles full migrations with scheduled off-site backups. It is mentioned in the plugin's own Pro Tools tab and nowhere else. You never need it to use everything on this page.

== Installation ==

1. Upload the plugin to `wp-content/plugins/database-search-replace`, or install it from the Plugins screen.
2. Activate it.
3. Open Tools then Database Search & Replace.
4. Enter what to find and what to replace it with, press Preview changes, review the diff, then apply.

Always run a preview first. It never writes anything.

== Frequently Asked Questions ==

= Will this corrupt my serialized data? =

No. When a value is serialized the engine parses it, replaces inside the structure, and re-serializes so the byte-length prefixes are recalculated correctly. A value it cannot parse is skipped and reported rather than guessed at, so a broken row is never written.

= Does it work with Elementor and other page builders? =

Yes. Builders store URLs as JSON inside serialized data with escaped slashes. The engine detects that, decodes the JSON, replaces, and re-encodes in the original escaping style. The preview marks those rows so you can see it working.

= Is the preview really free? =

Yes. The visual diff is never restricted. It retains up to 20 representative matches per table, and apply is bound to the completed preview so new candidates are not silently added afterward.

= Can I undo a replace? =

When you enable the safety snapshot at confirmation, the success screen offers Undo while the snapshot is retained. Undo restores a cell only if it still contains the value written by that run, so later edits are preserved and reported as conflicts. Snapshots are pruned after 30 days.

= What is protected by default? =

Network-global tables, including users and usermeta, are excluded from site-level runs so a site administrator cannot damage network data or logins. Post GUIDs are protected by default and can be included from the Advanced options.

= How does it handle a large database? =

Processing runs in bounded background batches and persists progress after each batch. This reduces request-timeout risk and lets work continue after you leave the page.

= Can I use it from WP-CLI? =

Yes.

    wp safesr replace 'http://staging.example.com' 'https://example.com' --dry-run
    wp safesr replace 'http://staging.example.com' 'https://example.com'
    wp safesr undo <job-id>

`--dry-run` reports what would change without writing. Every apply creates the same snapshot the admin does.

== Screenshots ==

1. The search and replace form, with the safety steps alongside it.
2. The visual diff preview, grouped by table, showing exactly what changes.
3. The confirmation step, where you can enable a safety snapshot before applying.
4. The result, with one-click undo when a retained snapshot is available.

== Changelog ==

= 1.0.0 =
* Initial release: serialized and JSON-inside-serialized safe engine, free sampled visual diff preview, optional snapshot and guarded undo, background batched processing, regex and targeting options, cache purge integrations, WP-CLI, multisite support.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
