<?php

declare(strict_types=1);

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
            'triage-anomalies',      // assign / escalate / resolve triage workflow

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
            'manage-invitations',    // create & manage user invitations

            // Transactions (read-only for most roles)
            'view-transactions',
            'view-transaction-payload',

            // Platform / DevOps
            'view-audit-logs',
            'manage-webhooks',
            'manage-api-keys',
            'manage-feature-flags',
            'manage-exchange-rates', // dedicated permission for exchange rate management

            // Compliance surfaces
            'view-aml-screenings',          // AmlScreeningResource access
            'view-data-subject-requests',   // DataSubjectRequestResource access
            'view-referrals',               // ReferralResource access (fraud flag surface)

            // Cards
            'manage-cards',

            // Growth & Content
            'manage-banners',

            // Group Savings
            'manage-group-savings',

            // Social
            'moderate-social',

            // Missing test permissions
            'manage-social-money',
            'manage-filing-schedules',
            'manage-projector-health',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // ──────────────────────────────────────────────────────────
        // 2. Create roles and assign permissions
        // ──────────────────────────────────────────────────────────

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $superAdmin->syncPermissions(Permission::all());

        // Compliance manager — KYC review, account freezes, AML, GDPR, referral fraud
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
            'manage-cards',
            'moderate-social',
            'view-aml-screenings',
            'view-data-subject-requests',
            'view-referrals',
        ]);

        // Finance lead — maker-checker approver; manages exchange rates; cannot initiate adjustments
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
            'manage-exchange-rates',
        ]);

        // Operations L2 — maker role; can initiate adjustments but NOT approve them
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
            'manage-cards',
            'manage-banners',
            'manage-group-savings',
        ]);

        // Support L1 — frontline read-only; cannot see PII, payloads, or balances
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
            'triage-anomalies',
            'view-transactions',
            'view-transaction-payload',
            'view-users',
            'view-accounts',
            'view-audit-logs',
        ]);

        // Admin — manages invitations and user management
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions([
            'manage-invitations',
            'view-users',
            'view-accounts',
            'view-transactions',
            'view-audit-logs',
        ]);
    }
}
