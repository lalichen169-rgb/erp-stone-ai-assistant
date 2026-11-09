<?php

namespace App\Services\AI\Context;

enum FollowUpThreadType: string
{
    case Stock = 'stock';
    case BankDetails = 'bank_details';
    case LandedCost = 'landed_cost';
    case OverduePurchaseOrders = 'overdue_purchase_orders';
    case PurchaseOrderSearch = 'purchase_order_search';
    case SupplierOriented = 'supplier_oriented';
}
