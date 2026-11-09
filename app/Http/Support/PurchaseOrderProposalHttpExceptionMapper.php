<?php

namespace App\Http\Support;

use App\Services\Erp\Exceptions\PurchaseOrderProposalException;

final class PurchaseOrderProposalHttpExceptionMapper
{
    /**
     * @return array{0: int, 1: string}
     */
    public static function map(PurchaseOrderProposalException $exception): array
    {
        $message = $exception->getMessage();

        return match ($message) {
            'Proposal not found.' => [404, $message],
            'Proposal has been cancelled.',
            'Proposal has expired.',
            'Proposal cannot be confirmed in its current state.',
            'Confirmed proposals cannot be cancelled.',
            'Expired proposals cannot be cancelled.',
            'Proposal cannot be cancelled in its current state.' => [409, $message],
            'Proposal data is corrupted.',
            'Proposal data integrity check failed.' => [422, 'Proposal data is invalid.'],
            default => self::mapDefault($message),
        };
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function mapDefault(string $message): array
    {
        if (str_starts_with($message, 'The ') && str_contains($message, ' is ')) {
            return [422, $message];
        }

        if (str_contains($message, 'Idempotency key')) {
            return [422, $message];
        }

        if ($message === 'Proposal could not be created. Please retry.') {
            return [422, $message];
        }

        $validationHints = [
            'Supplier',
            'Order date',
            'ETA',
            'Freight cost',
            'At least one line',
            'Each line',
            'Line quantity',
            'Line unit cost',
            'Product not found',
        ];

        foreach ($validationHints as $hint) {
            if (str_starts_with($message, $hint)) {
                return [422, $message];
            }
        }

        return [422, 'The request could not be processed.'];
    }
}
