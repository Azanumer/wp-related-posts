# Related Posts

A dependency-free WordPress plugin that shows related posts under single posts, matched by shared categories and tags.

## Features

- Relevance scoring — posts sharing more terms rank higher (not just newest-first)
- Transient cache (24h) so the term lookups don't run on every page view
- Auto-appends to single posts, or place manually with `[related_posts count="4"]`
- Cache invalidated automatically when a post is saved or deleted
- Filters: `wrp_count` (default 4), `wrp_auto_append` (disable auto-append)

## Install

Upload the plugin folder to `wp-content/plugins/` and activate it.

## Uninstall

Deletes all `wrp_related_*` transients. Nothing is written into posts, so deactivating leaves content untouched.

## License

MIT
