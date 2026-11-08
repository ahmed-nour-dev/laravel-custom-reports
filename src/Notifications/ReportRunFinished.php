<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Notifications;

use AhmedNour\CustomReports\Enums\ReportRunStatus;
use AhmedNour\CustomReports\Models\ReportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the viewer their background run or export finished (or failed),
 * in the app and over Reverb. The payload mirrors ActionNotification so the
 * bell and the notifications page render it alike; it links by run code.
 * A run a scheduled send queued is also emailed when done — a link, never
 * rows (SPEC §14.5).
 */
class ReportRunFinished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ReportRun $run)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->run->report_subscription_id !== null && $this->run->status === ReportRunStatus::DONE
            ? ['database', 'broadcast', 'mail']
            : ['database', 'broadcast'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->name();

        return (new MailMessage)
            ->subject("Scheduled report: {$name}")
            ->line("Your scheduled report \"{$name}\" is ready. It shows the records you can see.")
            ->action('Open the report', url($this->link()))
            ->line('The result is kept for '.max(1, (int) config('custom-reports.run_ttl_hours', 24)).' hours.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $name = $this->name();
        $done = $this->run->status === ReportRunStatus::DONE;

        return [
            'type' => $done ? 'report_run_finished' : 'report_run_failed',
            'record_type' => 'report_run',
            'record_id' => null,
            'title' => $done ? ($this->run->kind->isExport() ? 'Export Ready' : 'Report Ready') : 'Report Failed',
            'message' => $done ? "\"{$name}\" is ready." : "\"{$name}\" couldn't finish.",
            'link' => $this->link(),
        ];
    }

    /** Where the run opens in the app (`custom-reports.run_link`, {code} = RRN-####). */
    private function link(): string
    {
        return str_replace('{code}', (string) $this->run->code, (string) config('custom-reports.run_link', '/reports/runs/{code}'));
    }

    private function name(): string
    {
        return $this->run->report->name ?? $this->run->reportType->name ?? (string) $this->run->code;
    }
}
