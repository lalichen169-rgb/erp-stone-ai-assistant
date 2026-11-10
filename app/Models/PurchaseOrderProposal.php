<?php

namespace App\Models;

use App\Enums\PurchaseOrderProposalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderProposal extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (PurchaseOrderProposal $proposal): void {
            if ($proposal->isDirty(['payload', 'payload_hash'])) {
                throw new \LogicException('Proposal payload cannot be modified after creation.');
            }
        });
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'user_id',
        'conversation_key',
        'idempotency_key',
        'status',
        'payload',
        'payload_hash',
        'expires_at',
        'confirmed_at',
        'confirmed_purchase_order_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderProposalStatus::class,
            'payload' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function confirmedPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'confirmed_purchase_order_id');
    }
}
