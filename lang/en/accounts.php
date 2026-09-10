<?php

/**
 * Translations for the seeded Swiss SME chart of accounts.
 *
 * Keys are the account `code` from SwissChartOfAccountsSeeder. The Account
 * model exposes a translated `display_name` accessor that falls back to the
 * stored `name` when no translation exists for the code (e.g. for accounts
 * created by the user).
 */
return [
    // Class 1: Assets
    '1000' => 'Cash',
    '1010' => 'Post Office Account',
    '1020' => 'Raiffeisen Bank Account',
    '1021' => 'Bank Account EUR',
    '1100' => 'Receivables',
    '1109' => 'Allowance for Doubtful Accounts',
    '1170' => 'VAT Input Tax',
    '1200' => 'Inventory',
    '1300' => 'Prepaid Expenses',
    '1500' => 'Business Equipment',
    '1510' => 'Office Equipment',
    '1520' => 'IT Equipment',
    '1530' => 'Vehicles',
    '1540' => 'Tools',

    // Class 2: Liabilities
    '2000' => 'Accounts Payable',
    '2100' => 'Bank Loan Short-term',
    '2200' => 'VAT Output Tax',
    '2201' => 'VAT Settlement (FTA)',
    '2270' => 'Social Security Liabilities',
    '2271' => 'Pension Fund Liabilities',
    '2272' => 'Personal Insurance Liabilities (AXA UVG/KTG)',
    '2300' => 'Outstanding Expenses',
    '2400' => 'Bank Loan Long-term',

    // Class 2.8: Equity
    '2800' => 'Share Capital',
    '2900' => 'Retained Earnings',
    '2950' => 'Current Year Profit/Loss',
    '2970' => 'Owner Drawings',

    // Class 3: Revenue
    '3000' => 'Gross Revenue from Goods',
    '3200' => 'Financial Income',
    '3400' => 'Other Revenue',
    '3800' => 'Discounts Given',
    '3900' => 'Revenue Corrections',

    // Class 4: Cost of Goods/Services
    '4000' => 'Production Material Costs',
    '4200' => 'Cost of Services',
    '4400' => 'Subcontractor Costs',

    // Class 5: Personnel Expenses
    '5000' => 'Salaries',
    '5700' => 'OASI, DI, IC and Unemployment Insurance',
    '5800' => 'Other Personnel Expenses',
    '5900' => 'Temporary Staff',

    // Class 6: Other Operating Expenses
    '6000' => 'Rent',
    '6100' => 'Maintenance and Repairs',
    '6200' => 'Direct Taxes',
    '6300' => 'Insurance',
    '6400' => 'Energy and Utilities',
    '6500' => 'Office Supplies',
    '6510' => 'Telephone and Internet',
    '6520' => 'Postage and Shipping',
    '6530' => 'Secretarial, Accounting and Audit Expenses',
    '6570' => 'Accounting and Legal Fees',
    '6600' => 'Advertising and Marketing',
    '6700' => 'Travel Expenses',
    '6800' => 'Depreciation',
    '6900' => 'Financial Expenses',
    '6950' => 'Bank Fees',

    // Class 7-8: Non-operating
    '7000' => 'Non-operating Revenue',
    '7500' => 'Non-operating Expenses',
    '8000' => 'Extraordinary Revenue',
    '8500' => 'Extraordinary One-time Expenses',

    // Class 9: Closing
    '9000' => 'Opening Balance',
    '9100' => 'Profit and Loss Summary',
];
