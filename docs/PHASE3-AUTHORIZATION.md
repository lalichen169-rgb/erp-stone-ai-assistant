# Phase 3.2 — Roles, Gates, and Sanctum foundation

Authorization for the Stone ERP challenge: two human roles (**Sales** and **Purchasing**) enforced in **Laravel**, not in AI prompts. API authentication will use **Laravel Sanctum** bearer tokens in a later phase.

## Why two roles

| Role | Business need |
|------|----------------|
| **Sales** | See catalog, inventory levels, and PO **status** to answer customer questions — without cost or supplier banking data. |
| **Purchasing** | Full ERP visibility including unit/freight/landed costs, supplier bank details, and ability to **create draft purchase orders**. |

## UserRole enum

`App\Enums\UserRole` is a backed string enum:

- `sales`
- `purchasing`

Stored in `users.role`. The `User` model casts `role` to the enum and exposes `isSales()` and `isPurchasing()`.

### Safe default for existing users

Migration `2026_11_06_130000_add_role_to_users_table.php`:

- Adds `role` with default **`sales`** (least privilege).
- Runs `UPDATE users SET role = 'sales'` so every existing row is explicitly sales.

New users created through ordinary registration/factory also get **`sales`** via the database default unless an admin path calls `assignRole()`.

### Role assignment

`role` is **not** in `$fillable`. Users cannot elevate themselves via `User::create([..., 'role' => 'purchasing'])`. Use `User::assignRole(UserRole::Purchasing)` from trusted code (seeders, admin actions) in later phases.

## Gate authorization

Ability names live in `App\Auth\ErpAbilities`. Definitions are registered in `App\Auth\ErpGateRegistrar` from `AppServiceProvider::boot()`.

| Ability | Sales | Purchasing |
|---------|-------|------------|
| `viewProducts` | Yes | Yes |
| `viewStock` | Yes | Yes |
| `viewPurchaseOrderStatus` | Yes | Yes |
| `viewCosts` | No | Yes |
| `viewFreightCosts` | No | Yes |
| `viewLandedCosts` | No | Yes |
| `viewSupplierBankDetails` | No | Yes |
| `createDraftPurchaseOrders` | No | Yes |

Undefined abilities are **denied** (Laravel Gate default).

### Usage in future code

```php
Gate::authorize(ErpAbilities::VIEW_COSTS);
// or
if (! Gate::check(ErpAbilities::VIEW_SUPPLIER_BANK_DETAILS)) {
    abort(403);
}
```

**AI tools and controllers** must call the same Gates before reading or returning data. Prompt text must never be the only control.

## Supplier bank_details

`Supplier` keeps `bank_details` in `$hidden` and uses the `encrypted` cast. Purchasing users who pass `viewSupplierBankDetails` may receive bank data through an **explicit** API Resource or tool response that includes the field after authorization — not by removing `$hidden` globally.

## Laravel Sanctum (foundation)

- Package: **laravel/sanctum** ^4.3 (installed in Phase 3.2).
- `User` uses `HasApiTokens`.
- Config: `config/sanctum.php`.
- Migration: `personal_access_tokens` table.
- API route file: `routes/api.php` (placeholder; no login/assistant routes yet).

Future protected routes:

```php
Route::middleware('auth:sanctum')->group(function () {
    // ERP endpoints
});
```

Clients send `Authorization: Bearer {token}`. Token creation endpoints will be added later; Phase 3.2 does not ship login/logout APIs.

CSRF and session settings are unchanged for web; API token auth does not disable CSRF globally.

## Testing

See `tests/Feature/Auth/` and `tests/Unit/UserRoleTest.php`. Tests use `RefreshDatabase` with MySQL transactions where supported — they do not wipe `stone_erp_ai` destructively.

## Related docs

- `docs/PHASE3-ERP-SCHEMA.md` — ERP tables
- `docs/PHASE2-REDIS.md` — queue / Memurai
