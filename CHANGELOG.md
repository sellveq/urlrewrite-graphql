# Changelog

## 2.0.0

Forked from `scandipwa/urlrewrite-graphql` 1.3.9. Module name and namespace are unchanged, and the package replaces `scandipwa/urlrewrite-graphql` at every version, so it installs as a drop-in replacement.

- The redirect walk terminates: a `url_rewrite` cycle is detected by row id, logged once and answered with `null`, where it used to spin until the request died.
- A rewrite whose row names no entity, a `custom` rewrite for instance, resolves to the entity its target path points at, as Magento 2.4.9 does, where it answered `Internal server error`.
- A rewrite whose target path several rows share resolves to the row naming an entity, whichever was inserted first.
- A url with a query string resolves, and the query string is carried back in `relative_url`.
- `entity_uid`, `relative_url` and `redirectCode` are answered; they were declared and always null.
- `urlResolver` answers `null` for anything it cannot resolve and never an exception, the contract the storefront's 404 depends on.
- A category's `is_active` is checked before its `display_mode` and `sort_by` are read.
- The GraphQL schema declares only what this module adds; core's `urlResolver` declaration, cache identity and deprecation govern the rest.
