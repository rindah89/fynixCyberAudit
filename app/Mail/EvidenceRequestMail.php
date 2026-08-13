<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Blade;

class EvidenceRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $email;

    public string $name;

    public string $url;

    /**
     * Create a new message instance.
     */
    public function __construct($email, $name)
    {
        $this->email = $email;
        $this->name = $name;
        $this->url = setting('general.url') ?: config('app.url');
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $viewString = setting('mail.templates.evidence_request_body')
            ?: 'Hello {{ $name }}, an evidence request has been assigned to you.';

        $renderedView = Blade::render($viewString, [
            'url' => $this->url,
            'name' => $this->name,
            'email' => $this->email,
        ]);

        $from = setting('mail.from') ?: config('mail.from.address');
        $subject = setting('mail.templates.evidence_request_subject') ?: 'Evidence Request';

        return $this->from($from)
            ->to($this->email)
            ->subject($subject)
            ->html($renderedView);
    }
}
