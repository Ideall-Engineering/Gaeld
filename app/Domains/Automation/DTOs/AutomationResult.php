<?php

namespace App\Domains\Automation\DTOs;

/**
 * What an automation did, in a form the run log can store and a person can read.
 *
 * `summary` is free-form per automation; `message` is the one line shown in the
 * list. Handlers that found nothing to do still succeed — an automation that
 * has nothing to do is working correctly.
 */
readonly class AutomationResult
{
    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, string>  $findings  Things a person should look at.
     */
    public function __construct(
        public string $message,
        public array $summary = [],
        public array $findings = [],
    ) {}

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function of(string $message, array $summary = []): self
    {
        return new self($message, $summary);
    }

    /**
     * @param  array<int, string>  $findings
     * @param  array<string, mixed>  $summary
     */
    public static function withFindings(string $message, array $findings, array $summary = []): self
    {
        return new self($message, $summary, $findings);
    }

    public function hasFindings(): bool
    {
        return $this->findings !== [];
    }
}
