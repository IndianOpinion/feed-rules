=== Feed Rules ===
Contributors: indianopinion
Tags: feeds, import, rules, filter, categories
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Include and exclude rules, category, tag and author mapping for imported items, with a test screen over the last hundred items.

== Description ==

Written from scratch; not derived from any third-party plugin.

Feed Rules puts the keep and drop rules for imported items in one place and applies them to any importer through one filter and one action. Keyword lists, regular expressions and URL prefixes decide whether an item is kept; mapping rules add categories, tags and an author.

* Whole-word matching that works in every script, including Hindi and Tamil.
* A test state: new rules are logged, never acting, until you set them live.
* A test screen that runs a rule, a draft or the whole set over the last hundred items, or a pasted sample, and shows what would change.
* A "keep" rule that beats every exclude rule, for trusted sources.
* A minimal reference importer built on WordPress core feeds. It creates drafts.
* WP-CLI commands for status, testing, export and import.

Nothing is sent off the site.

== Installation ==

1. Upload the `feed-rules` folder to `wp-content/plugins/`.
2. Activate the plugin.
3. Go to Tools > Feed rules.

To run as a must-use plugin, see the README.

== Frequently Asked Questions ==

= Does a broken rule stop imports? =

No. Every failure keeps the item and records why.

= Does it change existing posts? =

No. Rules apply to new items only.

== Changelog ==

= 0.1.0 =
* First release.
