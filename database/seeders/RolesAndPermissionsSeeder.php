<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ──────────────────────────────────────────────────────────
        // 1. Define permissions
        // ──────────────────────────────────────────────────────────
        $permissions = [
            // Adjustment workflow (maker-checker)
            'request-adjustments',
            'approve-adjustments',
            'reject-adjustments',
            'view-adjustments',

            // Account controls
            'freeze-accounts',
            'unfreeze-accounts',
            'view-accounts',
            'view-balances',

            // KYC / Compliance
            'approve-kyc',
            'reject-kyc',
            'view-kyc-documents',

            // Fraud / Risk
            'view-anomalies',
            'resolve-anomalies',
            'flag-fraud',

            // Support
            'create-support-cases',
            'assign-support-cases',
            'resolve-support-cases',
            'view-support-cases',

            // Users
            'view-users',
            'freeze-users',
            'resend-otp',
            'reset-user-password',
            'view-pii',

            // Transactions (read-only for most roles)
            'view-transactions',
            'view-transaction-payload',

            // Platform / DevOps
            'view-audit-logs',
            'manage-webhooks',
            'manage-api-keys',
            'manage-feature-flags',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // ──────────────────────────────────────────────────────────
        // 2. Create roles and assign permissions
        // ──────────────────────────────────────────────────────────

        // Super admin — all permissions (Filament super_admin gate still applies separately)
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $superAdmin->syncPermissions(Permission::all());

        // Compliance manager — KYC review, account freezes, read-only transactions
        $complianceManager = Role::firstOrCreate(['name' => 'compliance-manager']);
        $complianceManager->syncPermissions([
            'approve-kyc',
            'reject-kyc',
            'view-kyc-documents',
            'freeze-accounts',
            'unfreeze-accounts',
            'view-accounts',
            'view-users',
            'freeze-users',
            'view-pii',
            'view-transactions',
            'view-audit-logs',
        ]);

        // Finance lead — maker-checker approver role; cannot initiate adjustments
        $financeLead = Role::firstOrCreate(['name' => 'finance-lead']);
        $financeLead->syncPermissions([
            'approve-adjustments',
            'reject-adjustments',
            'view-adjustments',
            'view-accounts',
            'view-balances',
            'view-transactions',
            'view-transaction-payload',
            'view-audit-logs',
        ]);

        // Operations L2 — maker role; can initiate adjustments but NOT approve
        $opsL2 = Role::firstOrCreate(['name' => 'operations-l2']);
        $opsL2->syncPermissions([
            'request-adjustments',
            'view-adjustments',
            'view-accounts',
            'view-balances',
            'view-users',
            'resend-otp',
            'reset-user-password',
            'view-transactions',
            'view-kyc-documents',
            'create-support-cases',
            'view-support-cases',
        ]);

        // Support L1 — frontline; cannot see PII, payloads, or balances
        $supportL1 = Role::firstOrCreate(['name' => 'support-l1']);
        $supportL1->syncPermissions([
            'view-users',
            'view-transactions',
            'view-accounts',
            'view-support-cases',
            'create-support-cases',
            // NOTE: deliberately excludes view-pii, view-transaction-payload,
            // view-balances for data minimisation compliance
        ]);

        // Fraud analyst — risk monitoring; read access to anomalies and full tx context
        $fraudAnalyst = Role::firstOrCreate(['name' => 'fraud-analyst']);
        $fraudAnalyst->syncPermissions([
            'view-anomalies',
            'resolve-anomalies',
            'flag-fraud',
            'view-transactions',
            'view-transaction-payload',
            'view-users',
            'view-accounts',
            'view-audit-logs',
        ]);
    }
}
