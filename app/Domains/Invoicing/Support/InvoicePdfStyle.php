<?php

namespace App\Domains\Invoicing\Support;

/**
 * Layout and style constants for the invoice and quote PDF (spec 011): the
 * sender top right, the recipient in a left address window, the document
 * data beside it on the right.
 */
final class InvoicePdfStyle
{
    // Margins
    public const MARGIN_LEFT = 15;

    public const MARGIN_TOP = 15;

    public const MARGIN_RIGHT = 15;

    // Below this line the content moves to the next page (A4 is 297 mm).
    public const CONTENT_BOTTOM = 272;

    // Column widths (in mm)
    public const COL_DESCRIPTION = 88;

    public const COL_VAT = 17;

    public const COL_QUANTITY = 15;

    public const COL_UNIT_PRICE = 37;

    public const COL_AMOUNT = 23;

    public const COL_TOTAL_WIDTH = self::COL_DESCRIPTION + self::COL_VAT + self::COL_QUANTITY + self::COL_UNIT_PRICE + self::COL_AMOUNT; // 180

    // Sender, top right
    public const SENDER_X = 125;

    public const SENDER_WIDTH = 70;

    // Recipient in the left window of a C5/C6 envelope (SN 010130).
    public const CUSTOMER_X = 22;

    public const CUSTOMER_INFO_Y = 55;

    public const CUSTOMER_WIDTH = 85;

    // Document data beside the recipient
    public const META_X = 125;

    public const META_Y = 72;

    public const META_LABEL_WIDTH = 32;

    public const META_VALUE_WIDTH = 38;

    public const INVOICE_TITLE_Y = 105;

    public const FOLD_MARK_X = 5;

    public const FOLD_MARK_LENGTH = 3;

    public const FOLD_MARK_TOP_Y = 105;

    public const PUNCH_MARK_Y = 148.5;

    public const FOLD_MARK_BOTTOM_Y = 210;

    // Totals: labels from here, amounts right-aligned to the margin.
    public const TOTALS_X = 120;

    public const TOTALS_LABEL_WIDTH = 45;

    public const TOTALS_AMOUNT_WIDTH = 30;

    // Bank details left of the totals on an invoice
    public const BANK_WIDTH = 95;

    // Font sizes
    public const FONT_BODY = 9;

    public const FONT_INVOICE_TITLE = 14;

    public const FONT_SMALL = 7;

    // Colors (RGB)
    public const COLOR_GRAY = [100, 100, 100];

    public const COLOR_BLACK = [0, 0, 0];

    public const COLOR_RULE = [190, 190, 190];

    public const COLOR_LIGHT = [150, 150, 150];

    // Logo
    public const LOGO_X = 15;

    public const LOGO_Y = 15;

    public const LOGO_WIDTH = 40;

    public const LOGO_MAX_HEIGHT = 18;

    // Language mapping for QR bill
    public const QR_LANGUAGE_MAP = ['en' => 'en', 'de' => 'de', 'fr' => 'fr', 'it' => 'it'];
}
