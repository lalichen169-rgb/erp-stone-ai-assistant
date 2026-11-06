<?php

namespace App\Services\Erp;

use App\Enums\PurchaseOrderProposalStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseOrderProposal;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Erp\Concerns\AuthorizesErpUser;
use App\Services\Erp\Exceptions\PurchaseOrderProposalException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DraftPurchaseOrderService
{
    use AuthorizesErpUser;

    /**
     * @param  array{
     *     supplier_id: int,
     *     order_date: string,
     *     eta?: string|null,
     *     freight_cost: string|int|float,
     *     lines: list<array{product_id: int, quantity: int, unit_cost: string|int|float}>
     * }  $input
     * @return array<string, mixed>
     */
    public function createProposal(
        User $user,
        string $conversationKey,
        string $idempotencyKey,
        array $input
    ): array {
        $this->authorizeCreateDraftPurchaseOrders($user);

        $conversationKey = $this->normalizeKey($conversationKey, 'conversation_key');
        $idempotencyKey = $this->normalizeKey($idempotencyKey, 'idempotency_key');

        $payload = $this->buildValidatedPayload($input);
        $payloadHash = $this->hashPayload($payload);

        $existing = PurchaseOrderProposal::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ($existing->payload_hash !== $payloadHash) {
                throw new PurchaseOrderProposalException(
                    'Idempotency key already used with different proposal data.'
                );
            }

            $this->expireIfNeeded($existing);

            return $this->formatProposal($existing);
        }

        $ttlMinutes = (int) config('erp.purchase_order_proposal_ttl_minutes', 30);
        $expiresAt = Carbon::now()->addMinutes(max($ttlMinutes, 1));

        try {
            $proposal = PurchaseOrderProposal::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'conversation_key' => $conversationKey,
                'idempotency_key' => $idempotencyKey,
                'status' => PurchaseOrderProposalStatus::Pending,
                'payload' => $payload,
                'payload_hash' => $payloadHash,
                'expires_at' => $expiresAt,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueIdempotencyViolation($exception)) {
                throw $exception;
            }

            return $this->resolveIdempotentCreateConflict($user, $idempotencyKey, $payloadHash);
        }

        return $this->formatProposal($proposal);
    }

    /**
     * @return array<string, mixed>
     */
    public function getProposal(User $user, string $proposalId, string $conversationKey): array
    {
        $this->authorizeCreateDraftPurchaseOrders($user);

        $proposal = $this->findOwnedProposal(
            $user,
            $proposalId,
            $this->normalizeKey($conversationKey, 'conversation_key')
        );

        $this->expireIfNeeded($proposal);

        return $this->formatProposal($proposal->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmProposal(User $user, string $proposalId, string $conversationKey): array
    {
        $this->authorizeCreateDraftPurchaseOrders($user);

        $conversationKey = $this->normalizeKey($conversationKey, 'conversation_key');

        return DB::transaction(function () use ($user, $proposalId, $conversationKey) {
            $proposal = PurchaseOrderProposal::query()
                ->where('id', $proposalId)
                ->where('user_id', $user->id)
                ->where('conversation_key', $conversationKey)
                ->lockForUpdate()
                ->first();

            if ($proposal === null) {
                throw new PurchaseOrderProposalException('Proposal not found.');
            }

            $this->expireIfNeeded($proposal);
            $proposal->refresh();

            if ($proposal->status === PurchaseOrderProposalStatus::Confirmed) {
                return $this->formatConfirmationResult($proposal);
            }

            if ($proposal->status === PurchaseOrderProposalStatus::Cancelled) {
                throw new PurchaseOrderProposalException('Proposal has been cancelled.');
            }

            if ($proposal->status === PurchaseOrderProposalStatus::Expired) {
                throw new PurchaseOrderProposalException('Proposal has expired.');
            }

            if ($proposal->status !== PurchaseOrderProposalStatus::Pending) {
                throw new PurchaseOrderProposalException('Proposal cannot be confirmed in its current state.');
            }

            if ($proposal->expires_at->isPast()) {
                $proposal->status = PurchaseOrderProposalStatus::Expired;
                $proposal->save();

                throw new PurchaseOrderProposalException('Proposal has expired.');
            }

            $this->assertPayloadIntegrity($proposal);

            $payload = $proposal->payload;

            $order = PurchaseOrder::query()->create([
                'supplier_id' => $payload['supplier_id'],
                'status' => PurchaseOrderStatus::Draft,
                'order_date' => $payload['order_date'],
                'eta' => $payload['eta'],
                'freight_cost' => $payload['freight_cost'],
            ]);

            foreach ($payload['lines'] as $line) {
                PurchaseOrderLine::query()->create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                ]);
            }

            $proposal->status = PurchaseOrderProposalStatus::Confirmed;
            $proposal->confirmed_at = Carbon::now();
            $proposal->confirmed_purchase_order_id = $order->id;
            $proposal->save();

            return $this->formatConfirmationResult($proposal->fresh());
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelProposal(User $user, string $proposalId, string $conversationKey): array
    {
        $this->authorizeCreateDraftPurchaseOrders($user);

        return DB::transaction(function () use ($user, $proposalId, $conversationKey) {
            $proposal = PurchaseOrderProposal::query()
                ->where('id', $proposalId)
                ->where('user_id', $user->id)
                ->where('conversation_key', $this->normalizeKey($conversationKey, 'conversation_key'))
                ->lockForUpdate()
                ->first();

            if ($proposal === null) {
                throw new PurchaseOrderProposalException('Proposal not found.');
            }

            $this->expireIfNeeded($proposal);
            $proposal->refresh();

            if ($proposal->status === PurchaseOrderProposalStatus::Confirmed) {
                throw new PurchaseOrderProposalException('Confirmed proposals cannot be cancelled.');
            }

            if ($proposal->status === PurchaseOrderProposalStatus::Cancelled) {
                return $this->formatProposal($proposal);
            }

            if ($proposal->status === PurchaseOrderProposalStatus::Expired) {
                throw new PurchaseOrderProposalException('Expired proposals cannot be cancelled.');
            }

            if ($proposal->status !== PurchaseOrderProposalStatus::Pending) {
                throw new PurchaseOrderProposalException('Proposal cannot be cancelled in its current state.');
            }

            $proposal->status = PurchaseOrderProposalStatus::Cancelled;
            $proposal->save();

            return $this->formatProposal($proposal->fresh());
        });
    }

    private function normalizeKey(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new PurchaseOrderProposalException("The {$field} is required.");
        }

        if (strlen($value) > 191) {
            throw new PurchaseOrderProposalException("The {$field} is too long.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function buildValidatedPayload(array $input): array
    {
        if (empty($input['supplier_id'])) {
            throw new PurchaseOrderProposalException('Supplier is required.');
        }

        $supplier = Supplier::query()->find((int) $input['supplier_id']);

        if ($supplier === null) {
            throw new PurchaseOrderProposalException('Supplier not found.');
        }

        if (empty($input['order_date'])) {
            throw new PurchaseOrderProposalException('Order date is required.');
        }

        try {
            $orderDate = Carbon::parse((string) $input['order_date'])->toDateString();
        } catch (\Throwable) {
            throw new PurchaseOrderProposalException('Order date is invalid.');
        }

        $eta = null;

        if (array_key_exists('eta', $input) && $input['eta'] !== null && $input['eta'] !== '') {
            try {
                $eta = Carbon::parse((string) $input['eta'])->toDateString();
            } catch (\Throwable) {
                throw new PurchaseOrderProposalException('ETA is invalid.');
            }

            if ($eta < $orderDate) {
                throw new PurchaseOrderProposalException('ETA cannot be before the order date.');
            }
        }

        $freightCost = ErpMoney::formatCurrency(ErpMoney::fromDecimal($input['freight_cost'] ?? '0'));

        if (ErpMoney::compare($freightCost, '0') < 0) {
            throw new PurchaseOrderProposalException('Freight cost cannot be negative.');
        }

        $linesInput = $input['lines'] ?? [];

        if (! is_array($linesInput) || count($linesInput) === 0) {
            throw new PurchaseOrderProposalException('At least one line is required.');
        }

        $lines = [];

        foreach ($linesInput as $line) {
            if (! is_array($line)) {
                throw new PurchaseOrderProposalException('Each line must be an object.');
            }

            $productId = (int) ($line['product_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);
            $unitCost = ErpMoney::formatCurrency(ErpMoney::fromDecimal($line['unit_cost'] ?? '0'));

            if ($productId <= 0) {
                throw new PurchaseOrderProposalException('Each line requires a valid product.');
            }

            if ($quantity <= 0) {
                throw new PurchaseOrderProposalException('Line quantity must be a positive integer.');
            }

            if (ErpMoney::compare($unitCost, '0') < 0) {
                throw new PurchaseOrderProposalException('Line unit cost cannot be negative.');
            }

            $product = Product::query()->find($productId);

            if ($product === null) {
                throw new PurchaseOrderProposalException('Product not found.');
            }

            $lineTotal = ErpMoney::roundCurrency(ErpMoney::mul((string) $quantity, $unitCost));

            $lines[] = [
                'product_id' => $product->id,
                'product_sku' => $product->sku,
                'product_name' => $product->name,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'line_total' => $lineTotal,
            ];
        }

        $subtotal = array_reduce(
            $lines,
            fn (string $carry, array $line) => ErpMoney::add($carry, $line['line_total']),
            '0'
        );

        return [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'order_date' => $orderDate,
            'eta' => $eta,
            'freight_cost' => $freightCost,
            'lines' => $lines,
            'subtotal' => ErpMoney::roundCurrency($subtotal),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->normalizePayloadForHash($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ));
    }

    /**
     * Stable representation for hashing after JSON storage round-trips.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayloadForHash(array $payload): array
    {
        $lines = array_map(function (array $line): array {
            return [
                'product_id' => (int) $line['product_id'],
                'product_sku' => (string) $line['product_sku'],
                'product_name' => (string) $line['product_name'],
                'quantity' => (int) $line['quantity'],
                'unit_cost' => ErpMoney::formatCurrency(ErpMoney::fromDecimal($line['unit_cost'])),
                'line_total' => ErpMoney::formatCurrency(ErpMoney::fromDecimal($line['line_total'])),
            ];
        }, $payload['lines']);

        return [
            'supplier_id' => (int) $payload['supplier_id'],
            'supplier_name' => (string) $payload['supplier_name'],
            'order_date' => (string) $payload['order_date'],
            'eta' => array_key_exists('eta', $payload) && $payload['eta'] !== null
                ? (string) $payload['eta']
                : null,
            'freight_cost' => ErpMoney::formatCurrency(ErpMoney::fromDecimal($payload['freight_cost'])),
            'lines' => $lines,
            'subtotal' => ErpMoney::formatCurrency(ErpMoney::fromDecimal($payload['subtotal'])),
        ];
    }

    private function assertPayloadIntegrity(PurchaseOrderProposal $proposal): void
    {
        $payload = $proposal->payload;

        if (! is_array($payload)) {
            throw new PurchaseOrderProposalException('Proposal data is corrupted.');
        }

        $expectedHash = $this->hashPayload($payload);

        if (! hash_equals($proposal->payload_hash, $expectedHash)) {
            throw new PurchaseOrderProposalException('Proposal data integrity check failed.');
        }
    }

    private function isUniqueIdempotencyViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? '';
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveIdempotentCreateConflict(User $user, string $idempotencyKey, string $payloadHash): array
    {
        $existing = PurchaseOrderProposal::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing === null) {
            throw new PurchaseOrderProposalException('Proposal could not be created. Please retry.');
        }

        if ($existing->payload_hash !== $payloadHash) {
            throw new PurchaseOrderProposalException(
                'Idempotency key already used with different proposal data.'
            );
        }

        $this->expireIfNeeded($existing);

        return $this->formatProposal($existing);
    }

    private function findOwnedProposal(User $user, string $proposalId, string $conversationKey): PurchaseOrderProposal
    {
        $proposal = PurchaseOrderProposal::query()
            ->where('id', $proposalId)
            ->where('user_id', $user->id)
            ->where('conversation_key', $conversationKey)
            ->first();

        if ($proposal === null) {
            throw new PurchaseOrderProposalException('Proposal not found.');
        }

        return $proposal;
    }

    private function expireIfNeeded(PurchaseOrderProposal $proposal): void
    {
        if ($proposal->status !== PurchaseOrderProposalStatus::Pending) {
            return;
        }

        if ($proposal->expires_at->isPast()) {
            $proposal->status = PurchaseOrderProposalStatus::Expired;
            $proposal->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatProposal(PurchaseOrderProposal $proposal): array
    {
        $payload = $proposal->payload;

        return [
            'proposal_id' => $proposal->id,
            'status' => $proposal->status->value,
            'conversation_key' => $proposal->conversation_key,
            'expires_at' => $proposal->expires_at->toIso8601String(),
            'confirmed_at' => $proposal->confirmed_at?->toIso8601String(),
            'confirmed_purchase_order_id' => $proposal->confirmed_purchase_order_id,
            'supplier_id' => $payload['supplier_id'],
            'supplier_name' => $payload['supplier_name'],
            'order_date' => $payload['order_date'],
            'eta' => $payload['eta'],
            'freight_cost' => $payload['freight_cost'],
            'subtotal' => $payload['subtotal'],
            'lines' => $payload['lines'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatConfirmationResult(PurchaseOrderProposal $proposal): array
    {
        return [
            'proposal_id' => $proposal->id,
            'status' => $proposal->status->value,
            'purchase_order_id' => $proposal->confirmed_purchase_order_id,
            'purchase_order_status' => PurchaseOrderStatus::Draft->value,
            'confirmed_at' => $proposal->confirmed_at?->toIso8601String(),
        ];
    }
}
