# Kjaiu API

## Conventions

API routes are mounted at the application root without Laravel's default `/api` prefix. Unless noted otherwise, v1 routes require one of these headers:

```http
Authorization: JWT <token>
Authorization: Bearer <token>
Accept: application/json
```

Successful responses use the IDCsmart-compatible envelope:

```json
{
    "status": 200,
    "msg": "请求成功",
    "data": {}
}
```

For compatibility, authentication and validation failures are represented by the JSON `status` value. Consumers must inspect the response body instead of relying only on the HTTP status code.

JWTs use HS256, expire after `KJAIU_JWT_TTL` seconds, and validate the token type, algorithm, issuer, audience, subject, and account token version. Password changes, API logout, and account suspension revoke existing tokens.

## Login

`GET /v1/login` returns enabled login methods.

`POST /v1/login` accepts the nested form used by IDCsmart Finance:

```text
email[email]=client@example.com
email[password]=secret
```

It also accepts `phone[phone]`, `phone[password]`, `id[id]`, and `id[password]`. A successful response returns the token at the top level:

```json
{
    "status": 200,
    "msg": "login successful",
    "jwt": "eyJ..."
}
```

## Public Catalog

| Method | Path                                 | Purpose                          |
| ------ | ------------------------------------ | -------------------------------- |
| `GET`  | `/common_list`                       | Legacy theme configuration       |
| `GET`  | `/cart/index`                        | Legacy catalog page data         |
| `GET`  | `/cart/all`                          | Legacy product catalog           |
| `GET`  | `/v1/products`                       | Product tree and currency        |
| `GET`  | `/v1/productsconfig?product_id={id}` | Product configuration and prices |
| `GET`  | `/v1/products/{id}`                  | Kjaiu product-detail alias       |

`/v1/products` supports `first_group_id`, `group_id`, `product_id`, and `keywords` filters.

## Client Account

| Method | Path                | Purpose                                    |
| ------ | ------------------- | ------------------------------------------ |
| `GET`  | `/v1/user`          | Client and country data                    |
| `PUT`  | `/v1/user`          | Update supported profile fields            |
| `PUT`  | `/v1/user/password` | Change password and revoke existing tokens |
| `POST` | `/v1/logout`        | Revoke all current API tokens              |

`GET /v1/user` returns the compatible `data.client` and `data.country` keys.

## Cart

| Method   | Path                           | Purpose                                               |
| -------- | ------------------------------ | ----------------------------------------------------- |
| `POST`   | `/v1/products/total`           | Calculate product, setup, and total prices            |
| `GET`    | `/v1/cart`                     | Return cart products, gateway list, credit, and total |
| `POST`   | `/v1/cart/products`            | Add a product                                         |
| `POST`   | `/v1/cart`                     | Kjaiu add-product alias                               |
| `DELETE` | `/v1/cart/products/{position}` | Delete a zero-based cart position                     |
| `DELETE` | `/v1/cart/{position}`          | Kjaiu delete alias                                    |
| `DELETE` | `/v1/cart`                     | Clear the cart                                        |
| `POST`   | `/v1/cart/checkout`            | Create an order and invoice                           |
| `POST`   | `/v1/cart/settlement`          | Checkout alias                                        |

Add-product fields:

```json
{
    "product_id": 1,
    "billingcycle": "monthly",
    "qty": 1,
    "configoption": {}
}
```

Checkout fields:

```json
{
    "position": [0, 2],
    "payment": "Credit",
    "idempotency_key": "checkout-4e84d2"
}
```

`position` is optional; omitting it checks out the complete cart. Positions are zero-based indexes from the latest `GET /v1/cart` response. `payment` is required. `idempotency_key` may instead be supplied as the `Idempotency-Key` header and should be reused only when retrying the same request.

Checkout locks the selected cart rows and products, validates current prices and stock, snapshots stock reservations, removes only selected rows, and returns both `invoice_id`/`invoiceid` and `order_id`/`orderid`.

## Services

| Method | Path                           | Purpose                                      |
| ------ | ------------------------------ | -------------------------------------------- |
| `GET`  | `/v1/hosts`                    | Paginated client services                    |
| `GET`  | `/v1/hosts/{id}`               | Service details                              |
| `GET`  | `/v1/hosts/{id}/renew`         | Available renewal cycles                     |
| `POST` | `/v1/hosts/{id}/renew`         | Create or return the current renewal invoice |
| `PUT`  | `/v1/hosts/{id}/renew`         | Toggle credit-based automatic renewal        |
| `PUT`  | `/v1/hosts/{id}/renew/auto`    | Automatic-renewal alias                      |
| `GET`  | `/v1/hosts/{id}/module`        | Provisioning status                          |
| `GET`  | `/v1/hosts/{id}/module/status` | Provisioning-status alias                    |

Renewal creation accepts `billingcycle`. One invoice is allowed for each service due-date generation. `free` and `onetime` services cannot renew. Monthly, quarterly, and annual renewals preserve the original billing day where the target month permits it.

## Invoices And Payments

| Method | Path                            | Purpose                                              |
| ------ | ------------------------------- | ---------------------------------------------------- |
| `GET`  | `/v1/invoices`                  | Kjaiu invoice list extension                         |
| `GET`  | `/v1/invoices/{id}`             | Compatible invoice detail                            |
| `GET`  | `/v1/invoices/{id}/status`      | Invoice payment status                               |
| `POST` | `/v1/pay`                       | Pay with credit or initiate an external gateway flow |
| `POST` | `/v1/credit`                    | Pay an invoice from account credit                   |
| `POST` | `/v1/pay/credit`                | Credit-payment alias                                 |
| `GET`  | `/v1/pay/status?invoiceid={id}` | Payment-status alias                                 |

`POST /v1/pay` accepts either `invoiceid` or `invoice_id` and a `payment` channel. `Credit` settles transactionally from account credit. Any external channel returns `requires_gateway: true`; selecting a channel is not proof of payment and does not settle the invoice.

Recharge invoices cannot be paid from account credit.

## Funds And Transactions

| Method | Path                     | Purpose                                                 |
| ------ | ------------------------ | ------------------------------------------------------- |
| `GET`  | `/v1/funds`              | Credit, recharge limits, gateways, and recent movements |
| `POST` | `/v1/funds`              | Create a recharge invoice                               |
| `GET`  | `/v1/transactions/funds` | Compatible account transaction list                     |
| `GET`  | `/v1/transactions`       | Kjaiu transaction alias                                 |
| `GET`  | `/v1/accounts`           | Kjaiu transaction alias                                 |

Recharge creation fields:

```json
{
    "amount": "100.00",
    "payment": "BankTransfer",
    "idempotency_key": "funds-90d7e1"
}
```

Amounts must use a plain decimal representation with at most two fractional digits. Scientific notation is rejected.

## Payment Adapter Contract

There is intentionally no unsigned public callback route. A payment adapter must:

1. Read the gateway-specific secret from encrypted `payment_gateways.configuration`.
2. Verify the provider signature and callback freshness.
3. Resolve the local invoice from the merchant order reference.
4. Require the invoice to be `Unpaid` and the configured channel to match.
5. Compare the provider amount and currency with the invoice snapshots exactly.
6. Pass the provider's stable external transaction number to `BillingService::recordPayment()`.
7. Return the provider's required acknowledgement only after the database transaction commits.

The adapter must treat repeated callbacks as idempotent. A transaction number cannot settle two invoices. Never call `recordPayment()` from a browser-controlled request without completing these checks.

## Compatibility Scope

The routes in this document cover Kjaiu's finance and hosting core. The following IDCsmart Finance areas are not implemented: tickets, messages, referrals, identity verification, news, downloads, marketing campaigns, OAuth providers, and arbitrary server-module actions. Consumers that need these modules must add explicit contracts and tests rather than assuming passthrough behavior.
