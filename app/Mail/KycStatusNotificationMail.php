<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class KycStatusNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientType,
        public readonly string $eventType,
        public readonly array $details,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->eventType) {
            'approved' => 'Origin Wallet KYC approved',
            'rejected' => 'Origin Wallet KYC update required',
            'rfi' => 'Origin Wallet KYC information request',
            default => 'Origin Wallet KYC status update',
        });
    }

    public function content(): Content
    {
        return new Content(view: 'emails.kyc-status-notification');
    }
}
