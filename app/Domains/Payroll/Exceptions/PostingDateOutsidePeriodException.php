<?php

namespace App\Domains\Payroll\Exceptions;

use DomainException;

/**
 * A salary slip may only be posted within the month it settles.
 *
 * Request validation says the same thing, but the ledger does not stop an
 * entry from landing in a VAT period that has already been settled — only a
 * closed fiscal year is refused. A slip dated into a settled quarter therefore
 * costs a further settlement version to put right, so the rule is enforced
 * where posting actually happens rather than only at the form.
 */
final class PostingDateOutsidePeriodException extends DomainException {}
