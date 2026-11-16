<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** In-app (database) notification shown in the header bell. */
class SecurityNotice extends Notification
{
    public function __construct(public string $title, public string $body) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}
