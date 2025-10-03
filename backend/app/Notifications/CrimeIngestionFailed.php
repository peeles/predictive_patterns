<?php

namespace App\Notifications;

use App\Models\CrimeIngestionRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackAttachment;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;
use Throwable;

class CrimeIngestionFailed extends Notification
{
    use Queueable;

    /**
     * @param array<int, string> $channels
     */
    public function __construct(private readonly CrimeIngestionRun $run, private readonly Throwable $exception, private readonly array $channels = ['mail'])
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(sprintf('Crime ingestion failed for %s', $this->run->month))
            ->line('The police crime ingestion process failed.')
            ->line(sprintf('Run ID: %d', $this->run->id))
            ->line(sprintf('Month: %s', $this->run->month))
            ->line(sprintf('Dry run: %s', $this->run->dry_run ? 'yes' : 'no'))
            ->line(sprintf('Error: %s', $this->exception->getMessage()))
            ->line('Please review the application logs for additional details.');
    }

    public function toSlack(mixed $notifiable): SlackMessage
    {
        return (new SlackMessage())
            ->error()
            ->content(sprintf('Crime ingestion failed for %s', $this->run->month))
            ->attachment(function (SlackAttachment $attachment): void {
                $attachment
                    ->title('Ingestion failure details')
                    ->fields([
                        'Run ID' => (string) $this->run->id,
                        'Month' => $this->run->month,
                        'Dry run' => $this->run->dry_run ? 'yes' : 'no',
                        'Error' => $this->exception->getMessage(),
                    ]);
            });
    }
}
