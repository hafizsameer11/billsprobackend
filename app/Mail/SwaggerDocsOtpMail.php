<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SwaggerDocsOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'API documentation login code',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Your one-time code for API documentation access is: <strong>'.$this->code.'</strong></p><p>This code expires in 15 minutes.</p>',
        );
    }
}
