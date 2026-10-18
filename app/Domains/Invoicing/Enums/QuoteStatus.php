<?php

namespace App\Domains\Invoicing\Enums;

/**
 * Quote lifecycle: draft → sent → accepted / declined.
 *
 * "Expired" is not a status: a sent quote past its validity date is shown as
 * expired but can still be accepted, as customers often answer late.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Sent, self::Accepted, self::Declined],
            self::Sent => [self::Accepted, self::Declined, self::Draft],
            self::Accepted => [self::Sent],
            self::Declined => [self::Sent, self::Draft],
        };
    }

    public function label(): string
    {
        return __('app.quote_status_'.$this->value);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isDeletable(): bool
    {
        return in_array($this, [self::Draft, self::Declined], true);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
