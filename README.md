# Loyalty Points

`mage-os/module-loyalty` (`MageOS_Loyalty`) — a module for Mage-OS / Magento Open Source.

Customers earn points on invoiced orders and spend them in the cart at a rate set in the admin.

- Configuration: *Stores > Configuration > Customers > Loyalty Points* (points earned per unit of base currency invoiced, and the base currency value of one point).
- Logged-in customers choose how many points to use, in the cart or in the order summary of Hyvä Checkout and Loki Checkout. The amount is deducted from the grand total, capped by their balance and by the total, and rounded down to cents.
- Points are spent atomically when the order is placed. They are given back when the order cannot be placed, is canceled (the part not invoiced) or has its payment denied after review, and on credit memos — never more than the order spent.
- A credit memo takes back the points the refunded money earned, at the rate of the day they were earned, and never more than the order earned.
- Customers see their balance and history under *My Account > Loyalty Points*, in their own language.
- Admins see every entry under *Customers > Loyalty Points*, export them, and adjust a balance with a comment.
- The loyalty line is shown in cart totals, the checkout summary, order views, the order e-mail and admin invoices / credit memos.

Limitations: no multi-address checkout (points chosen in the cart are not deducted there, and not spent), no expiry of points, one rate for all websites and customer groups, no REST or GraphQL API.

A refund can leave a negative balance: when a customer already spent the points an order earned and that order is refunded, the points are still taken back. The customer cannot use points until the next ones earned bring the balance above zero; an admin can also adjust it.

## Compatibility

Mage-OS 3.6 and 4.x, and Magento Open Source 2.4.9: all share the Magento 2.4.9 base (`magento/framework` ^103.0.9). PHP 8.3 to 8.5. CI runs the check-extension workflow on the currently supported Mage-OS versions (today Mage-OS 3.6.0 with PHP 8.4; Mage-OS 4.0 joins once the check-extension actions know it); developed on Mage-OS 4.0.0 with PHP 8.5. GraphQL is not supported.

- **Luma** (theme and checkout): supported; 1.0 was run in a browser on Mage-OS 4.0.0, the changes of 1.1 were not run in a store. Its *Check Out with Multiple Addresses* does not use points: the orders are placed at their full total and no point is spent.
- **Hyvä Theme**: supported; 1.0 was run on the Hyvä default theme 1.5, the changes of 1.1 were not run in a store. The cart total line follows the default theme's own templates (inline Alpine expressions): it was not run on the Hyvä CSP theme.
- **Hyvä Checkout**: written from the layouts used by `mage-os/module-gift-card`, not run (no licensed install): check it on yours. See [Checkouts](#checkouts).
- **Loki Checkout**: written from the layouts `mage-os/module-gift-card` runs on Loki Checkout 2.8, not run with this module: check it on yours. See [Checkouts](#checkouts).
- **Elasticsuite**: nothing to integrate. The module touches neither search, product lists nor product attributes.

## Installation

```bash
composer config repositories.mageos-loyalty vcs https://github.com/mage-os-lab/module-loyalty
composer require mage-os/module-loyalty
bin/magento module:enable MageOS_Loyalty
bin/magento setup:upgrade
```

## Configuration

### Stores > Configuration > Customers > Loyalty Points > Loyalty Points

| Setting | Default | Scope | What it does |
|---------|---------|-------|--------------|
| Points Earned per Currency Unit | 1 | Global | Points per unit of base currency invoiced, on the invoice grand total, rounded down. 0 stops earning. |
| Value of One Point | 0.01 | Global | Base currency amount one point is worth when spent in the cart. |

Access to this section needs the ACL resource *Stores > Settings > Configuration > Loyalty Points*.

![Loyalty points configuration](docs/images/config.png)

## Admin

### Customers > Loyalty Points

The grid lists every entry of the points ledger: date, customer, type (earned on an invoice, taken back on a refund, spent on an order, returned on a cancellation or a refund, adjustment), points, order number, comment and admin user. It can be filtered and exported (CSV, Excel XML). A customer's balance is the sum of their entries.

*Adjust a Balance*, above the grid, adds points to a customer or takes some away:

| Field | What it does |
|-------|--------------|
| Customer | E-mail address, or customer ID when several accounts (one per website) use the same address. |
| Points | Whole number. A negative number takes points away, and is refused when it exceeds the balance. |
| Comment | Required. The customer sees it in *My Account > Loyalty Points*. |

ACL resources: *Customers > Loyalty Points* to see the grid and export it, and under it *Adjust Balances* to use the form.

## Checkouts

Points chosen in the cart are kept through any checkout, and spent when the order is placed, whatever the checkout.

- **Luma checkout**: the loyalty line is in the order summary; points are chosen in the cart.
- **Hyvä Checkout**: the loyalty line is in the price summary, with the *Use Loyalty Points* form under it.
- **Loki Checkout**: the loyalty line is in the sidebar totals, with the *Use Loyalty Points* form after the coupon code.

In Hyvä Checkout and Loki Checkout the form is the cart's, posted as a plain form: using or removing points reloads the checkout, on the same step. Neither checkout is required by the module. Both integrations were written from their layouts and not run: check on yours that the line shows in the totals, that the form applies and removes points and comes back to the same step, and that an order placed with points spends them.

On Hyvä, the module's templates use Tailwind classes: rebuild the theme's styles after installing or updating it (the module registers itself for Hyvä's Tailwind compilation).

## Getting started

1. Keep the defaults: 1 point per currency unit, 1 point = 0.01.
2. As a logged-in customer, place an order of 100 and invoice it: the customer gets 100 points, shown under *My Account > Loyalty Points*.
3. In the next cart the customer enters the points to use; 100 points take 1.00 off the grand total.

Guests neither earn nor spend points.

## Troubleshooting

- **No points after an order**: points are earned on the invoice, not on order placement, and only for logged-in customers.
- **"Your loyalty points balance has changed" at checkout**: the balance was spent in another order meanwhile; the customer must choose the points again.
- **Negative balance**: a refund took back points the customer had already spent. See the note under the feature list.
- **Entries of type *Adjustment* with a comment like "Order #000000012" after updating from 1.0**: the entry's text did not match the module's translations (the store changed them), so it kept its text. Refunds and cancellations of such an order give back prorated points, as 1.0 did.

## More

- [CHANGELOG](CHANGELOG.md)
- [Security policy](SECURITY.md)
- [License](LICENSE.txt): Open Software License 3.0. This module contains no code from Adobe Commerce.
