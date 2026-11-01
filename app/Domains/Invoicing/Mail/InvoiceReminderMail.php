<?php

namespace App\Domains\Invoicing\Mail;

use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InstallmentPlanService;
use App\Domains\Organizations\Models\Organization;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public readonly int $daysOverdue;

    public readonly string $amountDue;

    /**
     * Spec 013: the overdue rates of a payment plan, which the reminder asks
     * for instead of the whole open amount.
     *
     * @var list<array{position: int, count: int, due_date: string, open: string}>
     */
    public readonly array $overdueRates;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly Organization $organization,
        public readonly int $reminderNumber,
    ) {
        $this->daysOverdue = (int) ($invoice->due_date?->diffInDays(now()) ?? 0);
        $states = app(InstallmentPlanService::class)->states($invoice);
        $overdue = array_values(array_filter($states, fn (array $state) => $state['overdue']));
        $this->overdueRates = array_map(fn (array $state) => [
            'position' => $state['installment']->position,
            'count' => count($states),
            'due_date' => $state['installment']->due_date->format('d.m.Y'),
            'open' => $state['open'],
        ], $overdue);
        $this->amountDue = $overdue === []
            ? $invoice->amountDue()
            : array_reduce($overdue, fn (string $sum, array $state) => Money::add($sum, $state['open']), '0.00');
    }

    public function envelope(): Envelope
    {
        $subject = match (true) {
            $this->reminderNumber <= 1 => __('mail.reminder_subject_first', ['number' => $this->invoice->number, 'organization' => $this->organization->name]),
            $this->reminderNumber === 2 => __('mail.reminder_subject_second', ['number' => $this->invoice->number, 'organization' => $this->organization->name]),
            default => __('mail.reminder_subject_final', ['number' => $this->invoice->number, 'organization' => $this->organization->name]),
        };

        return new Envelope(
            from: $this->fromAddress(),
            replyTo: $this->replyToAddresses(),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invoice-reminder',
            with: [
                'organization' => $this->organization,
                'amountDue' => $this->amountDue,
                'overdueRates' => $this->overdueRates,
            ],
        );
    }

    private function fromAddress(): ?Address
    {
        $address = config('mail.from.address');

        return $address ? new Address($address, $this->organization->name) : null;
    }

    /**
     * @return array<int, Address>
     */
    private function replyToAddresses(): array
    {
        return $this->organization->contact_email
            ? [new Address($this->organization->contact_email, $this->organization->name)]
            : [];
    }
}
