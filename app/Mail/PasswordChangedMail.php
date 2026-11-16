<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordChangedMail extends Mailable
{
    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your KBT RUVIA BioTech password was changed');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-changed');
    }
}
