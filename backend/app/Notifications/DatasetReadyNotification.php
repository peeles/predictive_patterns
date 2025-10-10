<?php

namespace App\Notifications;

use App\Models\Dataset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DatasetReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Dataset $dataset)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage())
            ->subject(sprintf('Dataset "%s" is ready', $this->dataset->name))
            ->greeting('Hello!')
            ->line(sprintf('Your dataset "%s" has finished processing and is ready to explore.', $this->dataset->name));

        if ($this->dataset->description !== null) {
            $message->line($this->dataset->description);
        }

        return $message
            ->action('View dataset', url(sprintf('/datasets/%s', $this->dataset->getKey())))
            ->line('Thank you for using Predictive Patterns.');
    }
}
