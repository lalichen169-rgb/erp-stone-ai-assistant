# Phase 4.2 — Draft purchase order proposals and confirmation

Purchasing users prepare **proposals** first; real `purchase_orders` rows are created **only** after explicit confirmation. Sales users cannot create, view, or confirm proposals.

## Why a separate table

- **No accidental POs** — AI or UI “preview” steps must not write to `purchase_orders`.
- **Snapshot** — `payload` JSON stores supplier name, SKUs, costs, and dates at creation time; later catalog changes do not alter the proposal.
- **Idempotency & expiry** — Keys and TTL support safe retries and time-bound approval.

## Lifecycle

| Status | Meaning |
|--------|---------|
| `pending` | Awaiting confirmation before `expires_at` |
| `confirmed` | Linked to `confirmed_purchase_order_id` |
| `cancelled` | User cancelled; cannot confirm |
| `expired` | Past TTL while still pending (set on read/confirm when detected) |

Transitions:

- `pending` → `confirmed` (confirm)
- `pending` → `cancelled` (cancel)
- `pending` → `expired` (TTL elapsed)
- `confirmed` / `cancelled` / `expired` cannot become `confirmed` again

## Ownership and conversation scope

Each proposal belongs to:

- **`user_id`** — the Purchasing user who created it
- **`conversation_key`** — caller-supplied scope (until conversation tables exist)

Retrieve, confirm, and cancel require **both** the authenticated owner and the matching `conversation_key`. Wrong user or key → **“Proposal not found.”** (no leak that another user’s proposal exists).

## Expiry

Default TTL: **30 minutes** (`config('erp.purchase_order_proposal_ttl_minutes')`, env `ERP_PO_PROPOSAL_TTL_MINUTES`).

Expired pending proposals are marked `expired` when accessed and **cannot** be confirmed.

## Idempotency

**Creation:** Unique `(user_id, idempotency_key)`.

- Same key + same payload hash → return existing proposal.
- Same key + different payload → error (no silent overwrite).

**Confirmation:** Under `lockForUpdate()` inside a transaction.

- First confirm creates one **draft** PO and lines from the snapshot.
- Repeat confirm for same proposal → same `purchase_order_id`, no second PO.

## Transaction and locking

`confirmProposal` uses `DB::transaction()` and row lock on the proposal to prevent double PO creation under concurrency.

On failure, the transaction rolls back — no partial PO/lines.

## Service: `DraftPurchaseOrderService`

| Method | Action |
|--------|--------|
| `createProposal` | Validate, snapshot, persist proposal only |
| `getProposal` | Owner + conversation scoped preview |
| `confirmProposal` | Create draft PO + lines, mark confirmed |
| `cancelProposal` | Pending → cancelled |

Authorization: `createDraftPurchaseOrders` (Purchasing only).

## Future API / AI usage

```php
$service = app(DraftPurchaseOrderService::class);

$preview = $service->createProposal(
    $user,
    conversationKey: $conversationId,
    idempotencyKey: $clientRequestId,
    input: [
        'supplier_id' => 1,
        'order_date' => '2026-11-06',
        'eta' => '2026-12-01',
        'freight_cost' => '500.00',
        'lines' => [
            ['product_id' => 10, 'quantity' => 20, 'unit_cost' => '200.00'],
        ],
    ],
);

// Human reviews $preview, then:
$result = $service->confirmProposal($user, $preview['proposal_id'], $conversationId);
```

Never trust model-supplied roles, IDs, or prices — the service re-validates and enforces Gates server-side.

## Related docs

- `docs/PHASE3-AUTHORIZATION.md`
- `docs/PHASE4-ERP-SERVICES.md`
