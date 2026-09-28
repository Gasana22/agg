<?php

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Domain\Models\MemberNotification;
use App\Modules\Tenancy\Domain\Models\Farm;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An email copy of an inbox notice. */
class NoticeMail extends Mailable
{
    public function __construct(public readonly MemberNotification $notice, public readonly Farm $farm) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->farm->name}: {$this->notice->title}");
    }

    public function content(): Content
    {
        $url = $this->notice->link ? rtrim(config('sfmtp.web_url'), '/').$this->notice->link : rtrim(config('sfmtp.web_url'), '/');
        $body = trim(($this->notice->body ?? '')."\n\nOpen in SFMTP: {$url}\n\nYou get these emails because they are turned on in your notification settings.");

        return new Content(htmlString: nl2br(e($this->notice->title."\n\n".$body)), text: null);
    }
}
