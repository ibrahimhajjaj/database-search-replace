# Database Search & Replace

Find and replace across your WordPress database without breaking your site. You see every change before it's written, a backup is taken first, and you can undo the whole run in one click.

Most tools do this blind: type into a box, hit go, and hope nothing broke. This one doesn't.

## What it does

The preview is the point. Before anything is written you get every match, grouped by table and column, with the changed characters highlighted. Nothing happens until you say so, and when it does, the affected rows are copied first so you can roll back.

Where it earns its keep is the data other tools mangle. Serialized values (theme settings, widgets, WooCommerce orders) get unserialized, replaced, and re-serialized so the `s:N` length prefixes stay valid. Get that wrong and the row corrupts. It also reaches into the JSON that page builders like Elementor keep inside serialized data, where URLs are stored with escaped slashes (`http:\/\/`) that a plain `http://` search never matches, and re-encodes it the way it found it. Base64 too.

The rest:

- Runs in the background in batches, so big databases finish instead of timing out
- Regex, case-sensitive matching, and table/column targeting when you need them
- A downloadable CSV log of every run
- WP-CLI and multisite
- User and login tables are skipped by default, so a bad replace can't lock you out

## Install

Plugins > Add New, search "Database Search & Replace". Or clone into `wp-content/plugins/` and run `composer install && npm install && npm run build`.

## Development

```sh
composer install
npm install
npm run build          # admin app
composer test:unit     # unit tests
npm run test:php       # integration tests (needs Docker + wp-env)
```

## License

GPL-2.0-or-later.
