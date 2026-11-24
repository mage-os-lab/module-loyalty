# Changelog

All notable changes to this module are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-11-24

Closes several ways points were created or lost around orders, refunds and multi-address checkout, and gives the admin a points ledger with manual adjustments.

Upgrade: grant the new ACL resource *Customers > Loyalty Points* (and *Adjust Balances* under it) to the roles that should see the points ledger or adjust balances. On Hyvä, rebuild the theme's styles. Entries written by 1.0 are given their type and order number by a data patch.

### Added

- *Customers > Loyalty Points*: grid of every ledger entry, with filters and export, and a form to add points to a customer or take some away, with a comment the customer sees and the admin user's name.
- Hyvä Checkout and Loki Checkout: loyalty line in the totals and the *Use Loyalty Points* form in the order summary. Written from their layouts, not run: see *Checkouts* in the README.

### Changed

- A credit memo takes back the refunded share of the points the order earned, at the rate of the day they were earned, and all that is left once the order is fully refunded. It used the rate of the day of the refund, and could take back more than was earned.
- The amount paid with points is rounded down to cents.
- The points history shows each entry in the reader's language; entries stored the text in the language of whoever triggered them.
- Earned points are written when the invoice is saved, and a credit memo's points when it is saved: a document that fails to save no longer moves points.

### Fixed

- Multi-address checkout: points chosen in the cart reduced each order without being spent. They are no longer deducted there.
- With one point worth less than a cent, the order asked for more points than the customer had, and was refused at each attempt.
- A negative balance (points taken back after being spent) increased the grand total of a cart where points were asked for.
- Points stayed spent, with no order, when another module refused the order right before it was placed.
- Points were not given back when a payment in review was denied.
- Several credit memos could give back more points than the order had spent, and a checkout error reported after the order was saved gave the points back while the order stayed.

### Compatibility

- Hyvä Checkout, Loki Checkout: the totals line and the points form are written from their layouts, not run on an install.
- Multi-address checkout: loyalty points cannot be used there.

## [1.0.0] - 2026-11-17

### Added

- Points earned on invoiced orders and spent in the cart, at rates set under *Stores > Configuration > Customers > Loyalty Points*, protected by its own ACL resource `MageOS_Loyalty::config`.
- Points spent atomically when the order is placed, returned on cancellation or credit memo; earned points are taken back on refunds.
- Balance and history under *My Account > Loyalty Points*.
- Loyalty line in cart totals, the checkout summary, order views, the order e-mail and admin invoices / credit memos.
- Storefront: Hyvä default theme and Luma; the module registers itself for Hyvä's Tailwind compilation.
- Admin and storefront texts translated into French, German, Spanish and Italian.
- Supports Mage-OS 3.6 and 4.x / Magento Open Source 2.4.9 (`magento/framework` ^103.0.9), PHP 8.3 to 8.5.
