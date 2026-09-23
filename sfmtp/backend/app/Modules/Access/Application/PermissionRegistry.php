<?php

namespace App\Modules\Access\Application;

/**
 * The single source of permission keys (`module.resource.action`).
 * Policies and middleware check these keys, never role names.
 *
 * Flags:
 *  - owner_only: grantable only to the farm's owner role (docs/04 §4)
 *  - money:      exposes or changes money; never grantable to a role holding worker.self
 *  - scopes:     scopes this permission supports (first = default)
 */
final class PermissionRegistry
{
    private const S_ALL = ['all'];

    private const S_ANY = ['all', 'assigned', 'own'];

    /** @return array<string, array{module:string, description:string, scopes:array<int,string>, owner_only:bool, money:bool}> */
    public static function all(): array
    {
        $p = [];
        $add = function (string $key, string $description, array $scopes = self::S_ALL, bool $ownerOnly = false, bool $money = false) use (&$p) {
            $p[$key] = [
                'module' => explode('.', $key)[0],
                'description' => $description,
                'scopes' => $scopes,
                'owner_only' => $ownerOnly,
                'money' => $money,
            ];
        };

        // Farm & membership
        $add('farm.profile.view', 'View the farm profile');
        $add('farm.profile.manage', 'Edit the farm profile');
        $add('farm.settings.manage', 'Change farm settings and approval thresholds');
        $add('farm.delete', 'Close (archive) the farm', ownerOnly: true);
        $add('farm.transfer', 'Transfer farm ownership', ownerOnly: true);
        $add('billing.manage', 'Manage the subscription and billing', ownerOnly: true, money: true);
        $add('members.view', 'View farm members');
        $add('members.manage', 'Invite, suspend and assign roles to members');
        $add('members.invite_workers', 'Invite field workers');
        $add('roles.view', 'View roles and their permissions');
        $add('roles.manage', 'Create and edit custom roles', ownerOnly: true);

        // Dashboards
        foreach (['owner', 'manager', 'agronomist', 'livestock', 'store', 'accountant', 'worker'] as $d) {
            $add("dashboard.{$d}.view", "Open the {$d} dashboard");
        }

        // Farm structure
        $add('structure.view', 'View blocks, sections and plots', self::S_ANY);
        $add('structure.manage', 'Create and edit blocks, sections and plots');
        $add('structure.soil.manage', 'Record soil information on plots');

        // Crops
        $add('crops.plans.view', 'View crop plans and cycles');
        $add('crops.plans.manage', 'Create and edit crop plans and cycles');
        $add('crops.plans.approve', 'Approve crop plans and budgets');
        $add('crops.operations.view', 'View crop operations and observations', self::S_ANY);
        $add('crops.operations.record', 'Record crop operations and observations', self::S_ANY);
        $add('crops.operations.approve', 'Verify crop operations');
        $add('crops.harvest.view', 'View harvests');
        $add('crops.harvest.record', 'Record harvests', self::S_ANY);

        // Livestock
        $add('livestock.animals.view', 'View animals and their records', self::S_ANY);
        $add('livestock.animals.manage', 'Register and edit animals');
        $add('livestock.records.record', 'Record feeding, health, weight, production', self::S_ANY);
        $add('livestock.records.approve', 'Verify livestock records');
        $add('livestock.sales.request', 'Request an animal sale');
        $add('livestock.sales.approve', 'Approve animal sales', money: true);

        // Workforce
        $add('workers.view', 'View worker profiles', self::S_ANY);
        $add('workers.manage', 'Create and edit worker profiles');
        $add('tasks.view', 'View tasks and activities', self::S_ANY);
        $add('tasks.manage', 'Create and assign tasks');
        $add('tasks.execute', 'Start, pause and complete tasks', ['assigned', 'own']);
        $add('tasks.verify', 'Verify or reject submitted tasks');
        $add('attendance.view', 'View attendance', self::S_ANY);
        $add('attendance.record', 'Check in and out', ['own']);
        $add('attendance.approve', 'Approve attendance corrections');
        $add('leave.request', 'Request leave', ['own']);
        $add('leave.approve', 'Approve leave');
        $add('worker.gps.view', 'View worker GPS tracks and photos');
        $add('worker.self', 'Marks a field-worker role: can never hold money permissions');

        // Inventory
        $add('inventory.view', 'View inventory items and quantities');
        $add('inventory.manage', 'Create and edit inventory items and stores');
        $add('inventory.stock.move', 'Record stock in, out, issue and transfer');
        $add('inventory.stock.adjust', 'Propose stock adjustments');
        $add('inventory.stock.approve', 'Approve stock issues and adjustments');
        $add('inventory.requests.create', 'Request inventory items', self::S_ANY);
        $add('inventory.values.view', 'See stock costs and values', money: true);

        // Procurement
        $add('procurement.requests.create', 'Create purchase requests');
        $add('procurement.requests.approve', 'Approve purchase requests');
        $add('procurement.orders.manage', 'Create and send purchase orders', money: true);
        $add('procurement.orders.approve', 'Approve purchase orders', money: true);
        $add('procurement.deliveries.receive', 'Receive deliveries into stores');
        $add('suppliers.view', 'View suppliers');
        $add('suppliers.manage', 'Create and edit suppliers');

        // Finance
        $add('finance.view', 'View income, expenses, invoices and payments', money: true);
        $add('finance.manage', 'Record income, expenses, invoices and payments', money: true);
        $add('finance.approve', 'Approve expenses and payments above threshold', money: true);
        $add('finance.expenses.request', 'Request an expense', money: true);
        $add('finance.budgets.manage', 'Manage budgets', money: true);
        $add('finance.payroll.view_hours', 'View payroll hours (no wages)');
        $add('finance.payroll.manage', 'Prepare payroll', money: true);
        $add('finance.payroll.approve', 'Approve payroll', money: true);
        $add('finance.values.view', 'See money values on operational records', money: true);

        // Sales
        $add('sales.view', 'View sales orders');
        $add('sales.pricing.manage', 'Manage products and selling prices', money: true);
        $add('sales.orders.create', 'Record sales orders');
        $add('sales.orders.approve', 'Approve sales orders above threshold', money: true);
        $add('sales.fulfil', 'Pick, dispatch and deliver sales orders');
        $add('sales.invoice', 'Invoice customers and record payments', money: true);
        $add('customers.view', 'View customers');
        $add('customers.manage', 'Create and edit customers');

        // Assets
        $add('assets.view', 'View assets');
        $add('assets.manage', 'Create and edit assets and schedules');
        $add('assets.maintenance.record', 'Record maintenance', self::S_ANY);

        // Traceability
        $add('trace.batches.view', 'View batches, events and journeys');
        $add('trace.batches.create', 'Create and link batches');
        $add('trace.events.create', 'Record and correct trace events');
        $add('trace.publish', 'Approve public traceability fields');
        $add('trace.qr.manage', 'Issue and revoke QR codes');

        // Maps, reports, documents, audit
        $add('maps.view', 'View farm maps', self::S_ANY);
        $add('reports.view', 'View operational reports');
        $add('reports.export', 'Export reports to PDF / Excel');
        $add('reports.finance.view', 'View financial reports', money: true);
        $add('documents.view', 'View documents', self::S_ANY);
        $add('documents.manage', 'Upload and manage documents');
        $add('audit.view', 'View the farm audit log');

        return $p;
    }

    /** The broadest scope a permission supports (what the owner holds). */
    public static function widestScope(string $key): string
    {
        $scopes = self::all()[$key]['scopes'];
        foreach (['all', 'assigned', 'own'] as $scope) {
            if (in_array($scope, $scopes, true)) {
                return $scope;
            }
        }

        return $scopes[0];
    }

    /**
     * The owner's grants: every permission except the worker marker, each at
     * its widest supported scope.
     *
     * @return array<string,string>
     */
    public static function ownerGrants(): array
    {
        $grants = [];
        foreach (self::keys() as $key) {
            if ($key !== 'worker.self') {
                $grants[$key] = self::widestScope($key);
            }
        }

        return $grants;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
