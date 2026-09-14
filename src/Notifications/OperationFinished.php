<?php

namespace Nexus\ImportExport\Notifications;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OperationFinished extends Notification
{
    final public function __construct(public readonly string $kind, public readonly string $operationId, public readonly string $status, public readonly array $counters) {}

    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? config('import-export.notifications.guest_channels', ['mail']) : config('import-export.notifications.channels', ['database']);
    }

    public function toArray(object $notifiable): array
    {
        return ['operation' => $this->kind, 'id' => $this->operationId, 'status' => $this->status, 'counters' => $this->counters];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(ucfirst($this->kind).' '.$this->status)->line('Operation '.$this->operationId.' is '.$this->status.'.');
    }
}
