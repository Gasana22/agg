<?php

namespace App\Modules\Access\Application;

/**
 * Default farm role templates (docs/04-roles-and-permissions.md §3).
 * Copied into every new farm; the owner can adjust non-owner roles.
 * Grants are `permission => scope`.
 */
final class RoleTemplates
{
    public const OWNER = 'owner';

    /** @return array<string, array{name:string, description:string, grants:array<string,string>}> */
    public static function all(): array
    {
        $all = fn (array $keys) => array_fill_keys($keys, 'all');

        return [
            self::OWNER => [
                'name' => 'Farm Owner',
                'description' => 'Highest authority in the farm. Always holds every permission.',
                'grants' => PermissionRegistry::ownerGrants(),
            ],
            'manager' => [
                'name' => 'Farm Manager',
                'description' => 'Runs daily operations, workers, tasks and approvals.',
                'grants' => $all([
                    'farm.profile.view', 'structure.view', 'structure.manage', 'members.view', 'members.invite_workers', 'roles.view',
                    'dashboard.manager.view',
                    'crops.plans.view', 'crops.plans.manage', 'crops.operations.view', 'crops.operations.record', 'crops.operations.approve',
                    'crops.harvest.view', 'crops.harvest.record',
                    'livestock.animals.view', 'livestock.animals.manage', 'livestock.records.record', 'livestock.records.approve', 'livestock.sales.request',
                    'workers.view', 'workers.manage', 'tasks.view', 'tasks.manage', 'tasks.verify',
                    'attendance.view', 'attendance.approve', 'leave.approve', 'worker.gps.view',
                    'inventory.view', 'inventory.stock.approve', 'inventory.requests.create',
                    'procurement.requests.create', 'procurement.requests.approve', 'suppliers.view',
                    'finance.expenses.request', 'finance.payroll.view_hours',
                    'sales.view', 'sales.orders.create', 'sales.fulfil', 'customers.view',
                    'assets.view', 'assets.manage', 'assets.maintenance.record',
                    'trace.batches.view', 'trace.batches.create', 'trace.events.create', 'trace.publish',
                    'maps.view', 'reports.view', 'reports.export', 'documents.view', 'documents.manage', 'audit.view',
                ]),
            ],
            'agronomist' => [
                'name' => 'Agronomist',
                'description' => 'Crop planning, field operations, pests and disease, harvest.',
                'grants' => $all([
                    'farm.profile.view', 'structure.view', 'structure.soil.manage', 'dashboard.agronomist.view',
                    'crops.plans.view', 'crops.plans.manage', 'crops.operations.view', 'crops.operations.record', 'crops.operations.approve',
                    'crops.harvest.view', 'crops.harvest.record',
                    'workers.view', 'tasks.view', 'tasks.manage', 'tasks.verify', 'attendance.view', 'worker.gps.view',
                    'inventory.view', 'inventory.requests.create', 'procurement.requests.create',
                    'trace.batches.view', 'trace.batches.create', 'trace.events.create', 'trace.publish',
                    'maps.view', 'reports.view', 'documents.view', 'documents.manage',
                ]),
            ],
            'livestock_manager' => [
                'name' => 'Livestock Manager',
                'description' => 'Animals, breeding, feeding, health, production and movements.',
                'grants' => $all([
                    'farm.profile.view', 'structure.view', 'dashboard.livestock.view',
                    'livestock.animals.view', 'livestock.animals.manage', 'livestock.records.record', 'livestock.records.approve',
                    'livestock.sales.request',
                    'workers.view', 'tasks.view', 'tasks.manage', 'tasks.verify', 'attendance.view', 'worker.gps.view',
                    'inventory.view', 'inventory.requests.create', 'procurement.requests.create',
                    'trace.batches.view', 'trace.batches.create', 'trace.events.create', 'trace.publish',
                    'maps.view', 'reports.view', 'documents.view', 'documents.manage',
                ]),
            ],
            'store_manager' => [
                'name' => 'Store Manager',
                'description' => 'Stock, stores, issues, transfers, deliveries. Requests purchases; does not buy or set prices.',
                'grants' => $all([
                    'farm.profile.view', 'structure.view', 'dashboard.store.view',
                    'inventory.view', 'inventory.manage', 'inventory.stock.move', 'inventory.stock.adjust', 'inventory.requests.create',
                    'inventory.values.view',
                    'crops.harvest.view', 'procurement.requests.create', 'procurement.deliveries.receive', 'suppliers.view',
                    'sales.fulfil', 'assets.view',
                    'trace.batches.view', 'trace.batches.create', 'trace.events.create',
                    'maps.view', 'reports.view', 'documents.view', 'documents.manage',
                ]) + ['tasks.view' => 'assigned', 'tasks.execute' => 'assigned'],
            ],
            'accountant' => [
                'name' => 'Accountant',
                'description' => 'Income, expenses, invoices, payments, payroll and financial reports. Cannot change operational records.',
                'grants' => $all([
                    'farm.profile.view', 'structure.view', 'dashboard.accountant.view',
                    'crops.plans.view', 'crops.operations.view', 'crops.harvest.view', 'livestock.animals.view',
                    'workers.view', 'attendance.view', 'inventory.view', 'inventory.values.view',
                    'procurement.orders.manage', 'suppliers.view', 'suppliers.manage',
                    'finance.view', 'finance.manage', 'finance.budgets.manage', 'finance.payroll.manage', 'finance.values.view',
                    'sales.view', 'sales.invoice', 'customers.view', 'customers.manage', 'assets.view',
                    'trace.batches.view', 'reports.view', 'reports.export', 'reports.finance.view',
                    'documents.view', 'documents.manage', 'audit.view',
                ]),
            ],
            'field_worker' => [
                'name' => 'Field Worker',
                'description' => 'Executes assigned tasks on mobile. No access to money, stock values or other workers.',
                'grants' => [
                    'worker.self' => 'all',
                    'dashboard.worker.view' => 'all',
                    'structure.view' => 'assigned',
                    'workers.view' => 'own',
                    'tasks.view' => 'assigned',
                    'tasks.execute' => 'assigned',
                    'attendance.view' => 'own',
                    'attendance.record' => 'own',
                    'leave.request' => 'own',
                    'crops.operations.record' => 'assigned',
                    'crops.harvest.record' => 'assigned',
                    'livestock.records.record' => 'assigned',
                    'inventory.requests.create' => 'own',
                    'assets.maintenance.record' => 'assigned',
                    'maps.view' => 'assigned',
                    'documents.view' => 'own',
                ],
            ],
        ];
    }
}
