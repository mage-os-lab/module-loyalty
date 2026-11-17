# Changelog

All notable changes to this module are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-17

### Added

- Points earned on invoiced orders and spent in the cart, at rates set under *Stores > Configuration > Customers > Loyalty Points*, protected by its own ACL resource `MageOS_Loyalty::config`.
- Points spent atomically when the order is placed, returned on cancellation or credit memo; earned points are taken back on refunds.
- Balance and history under *My Account > Loyalty Points*.
- Loyalty line in cart totals, the checkout summary, order views, the order e-mail and admin invoices / credit memos.
- Storefront: Hyvä default theme and Luma; the module registers itself for Hyvä's Tailwind compilation.
- Admin and storefront texts translated into French, German, Spanish and Italian.
- Supports Mage-OS 3.6 and 4.x / Magento Open Source 2.4.9 (`magento/framework` ^103.0.9), PHP 8.3 to 8.5.
