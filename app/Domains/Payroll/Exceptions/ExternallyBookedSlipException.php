<?php

namespace App\Domains\Payroll\Exceptions;

use DomainException;

/**
 * A slip that records a month booked by hand must never reach the ledger.
 *
 * Posting one would put the salary in the books a second time — the same shape
 * of mistake as a net payment booked to the salary expense account instead of
 * the payroll clearing account, which is expensive to unwind once a VAT period
 * has been settled on top of it.
 */
final class ExternallyBookedSlipException extends DomainException {}
