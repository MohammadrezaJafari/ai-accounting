<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An agent report delivered by email (the report's Markdown already rendered to safe HTML).
 */
class AgentReportMail extends Mailable
{
    use Queueable;

    public function __construct(public string $title, public string $reportHtml) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<div dir="rtl" style="font-family:Tahoma,sans-serif;line-height:1.9">'
            .'<h2>'.e($this->title).'</h2>'.$this->reportHtml.'</div>');
    }
}
