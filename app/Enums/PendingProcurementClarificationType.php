<?php

namespace App\Enums;

enum PendingProcurementClarificationType: string
{
    case WarehouseDestination = 'warehouse_destination';
    case SupplierSelection = 'supplier_selection';
}
