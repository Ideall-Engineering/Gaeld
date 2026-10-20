<?php

namespace App\Http\Middleware\Api;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\JournalEntryRevision;
use App\Domains\Api\Contracts\AbilityCatalog;
use App\Domains\Api\Models\Webhook;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoiceCatalogItem;
use App\Domains\Invoicing\Models\Quote;
use App\Domains\Invoicing\Models\RecurringInvoice;
use App\Domains\Organizations\Enums\Permission;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Projects\Models\Project;

/**
 * Maps model class + policy ability to the required Spatie permission.
 *
 * Used by EnsureApiOrganization to evaluate organization token access
 * without going through full policy org-membership checks.
 */
final class TokenPermissionMap
{
    /**
     * Return the canonical abilities exposed to API token clients.
     *
     * @return array<int, string>
     */
    public static function abilities(): array
    {
        $abilities = [];

        foreach (self::get() as $modelAbilities) {
            foreach ($modelAbilities as $permission) {
                $abilities[$permission->value] = true;
            }
        }

        ksort($abilities);

        return array_keys($abilities);
    }

    /**
     * Return all ability names accepted when creating a token.
     *
     * @return array<int, string>
     */
    public static function acceptedAbilities(): array
    {
        return array_values(array_unique([
            ...self::abilities(),
            ...self::legacyAbilities(),
            '*',
        ]));
    }

    /**
     * Expand the legacy resource abilities used before the canonical permission
     * names were introduced. Unknown abilities remain restricted.
     *
     * @param  array<int, string>  $abilities
     * @return array<int, string>
     */
    public static function normalize(array $abilities): array
    {
        $normalized = [];

        foreach ($abilities as $ability) {
            if ($ability === '*') {
                return ['*'];
            }

            $mapped = match ($ability) {
                'customers:read' => [Permission::ContactsView->value],
                'customers:write' => [
                    Permission::ContactsCreate->value,
                    Permission::ContactsEdit->value,
                    Permission::ContactsDelete->value,
                ],
                'invoices:read' => [Permission::InvoicingView->value],
                'invoices:write' => [
                    Permission::InvoicingCreate->value,
                    Permission::InvoicingEdit->value,
                    Permission::InvoicingDelete->value,
                    Permission::InvoicingSendReminder->value,
                ],
                'expenses:read' => [Permission::ExpensesView->value],
                'expenses:write' => [
                    Permission::ExpensesCreate->value,
                    Permission::ExpensesEdit->value,
                    Permission::ExpensesDelete->value,
                ],
                'accounts:read' => [Permission::AccountingView->value],
                'bank-accounts:read' => [Permission::BankingView->value],
                'webhooks:read' => [Permission::OrganizationEdit->value],
                'webhooks:write' => [Permission::OrganizationEdit->value],
                default => in_array($ability, self::abilities(), true) ? [$ability] : [],
            };

            $normalized = array_merge($normalized, $mapped);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<int, string>
     */
    private static function legacyAbilities(): array
    {
        return [
            'customers:read',
            'customers:write',
            'invoices:read',
            'invoices:write',
            'expenses:read',
            'expenses:write',
            'accounts:read',
            'bank-accounts:read',
            'webhooks:read',
            'webhooks:write',
        ];
    }

    /**
     * @return array<string, array<string, Permission>>
     */
    public static function get(): array
    {
        return AbilityCatalog::mergeInto([
            Contact::class => [
                'viewAny' => Permission::ContactsView,
                'view' => Permission::ContactsView,
                'create' => Permission::ContactsCreate,
                'update' => Permission::ContactsEdit,
                'delete' => Permission::ContactsDelete,
                'restore' => Permission::ContactsDelete,
            ],
            Invoice::class => [
                'viewAny' => Permission::InvoicingView,
                'view' => Permission::InvoicingView,
                'create' => Permission::InvoicingCreate,
                'update' => Permission::InvoicingEdit,
                'delete' => Permission::InvoicingDelete,
                'finalize' => Permission::InvoicingFinalize,
                'recordPayment' => Permission::InvoicingRecordPayment,
                'send' => Permission::InvoicingEdit,
                'sendReminder' => Permission::InvoicingSendReminder,
                'cancel' => Permission::InvoicingEdit,
                'creditNote' => Permission::InvoicingCreate,
                'revertToDraft' => Permission::InvoicingEdit,
                'duplicate' => Permission::InvoicingCreate,
                'updatePayment' => Permission::InvoicingRecordPayment,
                'refreshCustomerSnapshot' => Permission::InvoicingEdit,
                'forceDelete' => Permission::InvoicingDelete,
            ],
            RecurringInvoice::class => [
                'viewAny' => Permission::InvoicingView,
                'view' => Permission::InvoicingView,
                'create' => Permission::InvoicingCreate,
                'update' => Permission::InvoicingEdit,
                'delete' => Permission::InvoicingDelete,
            ],
            InvoiceCatalogItem::class => [
                'viewAny' => Permission::InvoicingView,
                'view' => Permission::InvoicingView,
            ],
            Quote::class => [
                'viewAny' => Permission::InvoicingView,
                'view' => Permission::InvoicingView,
                'create' => Permission::InvoicingCreate,
                'update' => Permission::InvoicingEdit,
                'changeStatus' => Permission::InvoicingEdit,
                'convert' => Permission::InvoicingCreate,
                'delete' => Permission::InvoicingDelete,
            ],
            Expense::class => [
                'viewAny' => Permission::ExpensesView,
                'view' => Permission::ExpensesView,
                'create' => Permission::ExpensesCreate,
                'update' => Permission::ExpensesEdit,
                'delete' => Permission::ExpensesDelete,
                'approve' => Permission::ExpensesApprove,
                'cancel' => Permission::ExpensesEdit,
            ],
            Account::class => [
                'viewAny' => Permission::AccountingView,
                'view' => Permission::AccountingView,
            ],
            JournalEntry::class => [
                'viewAny' => Permission::AccountingView,
                'view' => Permission::AccountingView,
                'create' => Permission::AccountingCreate,
                'post' => Permission::AccountingEdit,
                'reverse' => Permission::AccountingEdit,
                'update' => Permission::AccountingEdit,
                'linkDocument' => Permission::AccountingEdit,
                'delete' => Permission::AccountingDelete,
                'attemptAmend' => Permission::AccountingAmendPosted,
                'amend' => Permission::AccountingAmendPosted,
                'deletePosted' => Permission::AccountingAmendPosted,
            ],
            JournalEntryRevision::class => [
                'viewAny' => Permission::AccountingView,
                'view' => Permission::AccountingView,
            ],
            Project::class => [
                'viewAny' => Permission::ProjectsView,
                'view' => Permission::ProjectsView,
                'create' => Permission::ProjectsManage,
                'update' => Permission::ProjectsManage,
                'delete' => Permission::ProjectsManage,
                'allocate' => Permission::ProjectsAllocate,
                // Steering is for owners: no other role holds this permission.
                'steer' => Permission::OrganizationDelete,
            ],
            BankAccount::class => [
                'viewAny' => Permission::BankingView,
                'view' => Permission::BankingView,
                'create' => Permission::BankingCreate,
                'import' => Permission::BankingImport,
            ],
            Organization::class => [
                'view' => Permission::OrganizationView,
                'update' => Permission::OrganizationEdit,
                'delete' => Permission::OrganizationDelete,
                'manageUsers' => Permission::OrganizationManageUsers,
                'viewAuditLog' => Permission::OrganizationViewAuditLog,
            ],
            Webhook::class => [
                'viewAny' => Permission::OrganizationEdit,
                'view' => Permission::OrganizationEdit,
                'create' => Permission::OrganizationEdit,
                'update' => Permission::OrganizationEdit,
                'delete' => Permission::OrganizationEdit,
                'regenerateSecret' => Permission::OrganizationEdit,
            ],
        ]);
    }
}
