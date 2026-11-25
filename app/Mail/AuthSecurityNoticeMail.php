<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuthSecurityNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $event) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Origin Wallet account security notice');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.auth-security-notice');
    }
}
