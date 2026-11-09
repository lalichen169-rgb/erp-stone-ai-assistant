<?php

namespace App\Enums;

/**
 * Purchase order lifecycle statuses stored as strings in purchase_orders.status.
 *
 * Transition rules are enforced in application services (not in Phase 3.1).
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Received = 'received';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
