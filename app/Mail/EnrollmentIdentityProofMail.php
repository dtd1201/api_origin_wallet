<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Synchronous after-commit delivery; never persist plaintext proof in a queue. */
class EnrollmentIdentityProofMail extends Mailable
{
    public function __construct(#[\SensitiveParameter] public readonly string $proof) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Origin Wallet one-time authenticator enrollment');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.enrollment-identity-proof');
    }
}
