<?php

namespace App\Domains\Payroll\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Raised when a calculated deduction has no liability account to be credited to.
 *
 * Without it the amount would be carried in the employer total but never
 * booked, and the payroll entry would fail the balance check with a message
 * that says nothing about the cause.
 */
class UnmappedDeductionException extends DomainException {}
