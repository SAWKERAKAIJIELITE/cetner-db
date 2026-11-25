<?php

namespace App\Modules\Notifications\Notifications;

use Illuminate\Notifications\Notification;

/**
 * An in-system notice to a staff user (D-046), in the hiring system's shape: a kind, a title and a message in
 * Arabic, and the details the screen links from. Written in the database, in the transaction that caused it.
 */
abstract class StaffNotice extends Notification
{
    abstract public function kind(): string;

    abstract public function title(): string;

    abstract public function message(): string;

    /** @return array<string, mixed> */
    abstract public function details(): array;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return ['kind' => $this->kind(), 'title' => $this->title(), 'message' => $this->message(), 'details' => $this->details()];
    }
}
