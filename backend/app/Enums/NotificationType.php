<?php

namespace App\Enums;

enum NotificationType: string
{
    case FarmMembershipAdded = 'farm_membership_added';
    case AttendancePendingApproval = 'attendance_pending_approval';
    case InventoryLowStock = 'inventory_low_stock';
    case PurchaseOrderDelivered = 'purchase_order_delivered';
    case PayrollPaid = 'payroll_paid';
    case TraceBatchRecalled = 'trace_batch_recalled';
}
