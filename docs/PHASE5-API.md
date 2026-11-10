# Phase 5 — Sanctum authentication and ERP API

Authenticated JSON API over Phase 4.1 read-only services and Phase 4.2 draft purchase order proposals. No assistant or AI endpoints.

## Authentication flow

1. `POST /api/auth/token` with email, password, and device name.
2. Receive a Bearer token and safe user payload (id, name, email, role).
3. Send `Authorization: Bearer {token}` on protected routes.
4. `DELETE /api/auth/token` revokes **only** the current token.
5. `GET /api/user` returns the authenticated user (safe fields only).

Login is rate limited (**5 attempts per minute** per email + IP). Invalid credentials return **401** with a generic message (no email enumeration).

## Example: obtain a token

```http
POST /api/auth/token
Content-Type: application/json

{
  "email": "purchasing@example.test",
  "password": "your-local-password",
  "device_name": "postman"
}
```

Success (**200**):

```json
{
  "token": "1|…",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "Demo Purchasing User",
    "email": "purchasing@example.test",
    "role": "purchasing"
  }
}
```

Failure (**401**): invalid credentials.

## Protected headers

```http
Authorization: Bearer {token}
Accept: application/json
```

## ERP read endpoints

| Method | Path | Sales | Purchasing |
|--------|------|-------|------------|
| GET | `/api/products` | Yes | Yes |
| GET | `/api/stock` | Yes | Yes |
| GET | `/api/purchase-orders` | Yes | Yes |
| GET | `/api/purchase-orders/overdue` | Yes | Yes |
| GET | `/api/purchase-orders/{id}/landed-cost` | No (**403**) | Yes |

### Products — `GET /api/products`

Query (all optional): `sku`, `name`, `material`, `color`, `thickness_cm`, `limit` (max 200).

### Stock — `GET /api/stock`

Query (optional):

- By IDs: `product_id`, `warehouse_id`
- Product attributes: `sku`, `name`, `material`, `color`, `thickness_cm`
- Warehouse: `warehouse_name`, `warehouse_city`
- `limit` (max 200)

Returns slab counts using existing service semantics (0 slabs when no stock row).

### Purchase orders — `GET /api/purchase-orders`

Query: `supplier_id`, `supplier_name`, `status` (`draft|ordered|received|cancelled`), date ranges, `product_sku`, `product_name`, `page`, `limit`.

**Sales responses never include unit costs or freight costs.**

### Overdue — `GET /api/purchase-orders/overdue`

Query: `limit` (max 500), optional `as_of` date (YYYY-MM-DD).

Uses `OverduePurchaseOrderService` (ordered + non-null ETA before reference date).

### Landed cost — `GET /api/purchase-orders/{purchaseOrder}/landed-cost`

Purchasing only. Uses `LandedCostService`. **404** if PO missing or not calculable.

## Authorization layers

1. **Sanctum** — valid token required.
2. **API Resources** — explicit fields only; no bank details or password hashes.
3. **Services + Gates** — same rules as Phase 4.1 (`viewProducts`, `viewStock`, `viewPurchaseOrderStatus`, `viewLandedCosts`).

The authenticated **database user** is always the authority; never trust client-supplied roles.

## HTTP status codes

| Code | When |
|------|------|
| 200 | Success |
| 204 | Token revoked |
| 401 | Missing/invalid token or login failure |
| 403 | Valid token but insufficient role (e.g. Sales landed cost) |
| 404 | Missing PO (landed cost) |
| 422 | Validation errors |
| 429 | Login rate limit exceeded |

## Draft purchase order proposals (Phase 5.2)

**Purchasing only.** All routes require Sanctum. Proposals never create a real purchase order until **explicit confirmation**.

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/purchase-order-proposals` | Create proposal preview |
| GET | `/api/purchase-order-proposals/{proposalId}` | Retrieve snapshot |
| POST | `/api/purchase-order-proposals/{proposalId}/confirm` | Create draft PO + lines |
| DELETE | `/api/purchase-order-proposals/{proposalId}` | Cancel pending proposal |

Sales users receive **403** on all proposal routes.

### Headers (create)

```http
Authorization: Bearer {token}
Content-Type: application/json
Idempotency-Key: client-unique-key-001
Accept: application/json
```

`Idempotency-Key` is required, non-empty, max 191 characters. Unique per user: same key + same payload returns the existing proposal (**200**); same key + different payload → **422**.

### Create — `POST /api/purchase-order-proposals`

Body:

```json
{
  "conversation_key": "chat-session-abc",
  "supplier_id": 1,
  "order_date": "2026-11-06",
  "eta": "2026-12-01",
  "freight_cost": "500.00",
  "lines": [
    { "product_id": 10, "quantity": 20, "unit_cost": "200.00" }
  ]
}
```

Success: **201** (new) or **200** (idempotent replay). Response includes proposal id, status (`pending`), expiry, supplier name, line SKUs/names, quantities, unit costs, subtotal. **No** supplier bank details or payload hashes.

### Retrieve — `GET /api/purchase-order-proposals/{proposalId}?conversation_key=...`

Required query: `conversation_key`. Returns the **stored snapshot** (not live catalog prices).

### Confirm — `POST /api/purchase-order-proposals/{proposalId}/confirm?conversation_key=...`

Only this step creates `purchase_orders` and `purchase_order_lines` (status **draft**). Repeat confirm returns the same `purchase_order_id` without a second PO.

### Cancel — `DELETE /api/purchase-order-proposals/{proposalId}?conversation_key=...`

Marks proposal `cancelled`; record is retained. Confirmed proposals cannot be cancelled.

### Conversation scoping

`conversation_key` must match on get, confirm, and cancel. Wrong key or another user → **404** (`Proposal not found.`) with no leak that a proposal exists elsewhere.

### Proposal errors (API)

| Code | Examples |
|------|----------|
| 401 | Missing/invalid token |
| 403 | Sales role |
| 404 | Proposal not found (wrong owner/key/id) |
| 409 | Expired, cancelled, or invalid lifecycle for confirm/cancel |
| 422 | Form validation, idempotency conflict, domain validation from service |

Authorization uses Form Request gates plus `DraftPurchaseOrderService` (`createDraftPurchaseOrders` ability).

## Testing database

PHPUnit sets `DB_DATABASE=stone_erp_ai_test`. Tests refuse any other database name. Create and migrate the test database separately from development (`stone_erp_ai`).

## Related docs

- `docs/PHASE3-AUTHORIZATION.md`
- `docs/PHASE4-ERP-SERVICES.md`
- `docs/PHASE4-ERP-SERVICE-AUDIT.md`
- `docs/PHASE4-DRAFT-PO-WORKFLOW.md`
