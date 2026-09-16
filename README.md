# ScandiPWA UrlrewriteGraphQl

Fork of [scandipwa/urlrewrite-graphql](https://github.com/scandipwa/urlrewrite-graphql) 1.3.9, maintained by Selveq for Magento 2.4.9 and PHP 8.3. Module name and namespace are unchanged, and the package replaces `scandipwa/urlrewrite-graphql` at every version, so it installs as a drop-in replacement. Selveq is not affiliated with or endorsed by Scandiweb.

## What it does

- Answers `urlResolver` with `sku`, `display_mode` and `sort_by` beside core's fields, and with `null` for anything it cannot resolve, which the storefront renders as its 404 page.
- Follows redirect chains and custom rewrites to the entity they point at, and stops on a cycle instead of spinning.
- Answers core's `entity_uid`, `relative_url` — the query string included — and `redirectCode`.
- Resolves a category-scoped product path, `catalog/product/view/id/N/category/M`, to the product.

## Install

```sh
composer require selveq/urlrewrite-graphql
bin/magento setup:upgrade
```

## License

[OSL-3.0](LICENSE), the license of the original work. Scandiweb's copyright notices are kept in every file, and each file Selveq changed carries a `Modifications © Selveq` notice.
