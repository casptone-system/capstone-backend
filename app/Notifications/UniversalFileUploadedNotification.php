<?php

namespace App\Notifications;

use App\Models\UniversalFile;
use App\Support\UniversalFileRules;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class UniversalFileUploadedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, UniversalFile>  $files
     */
    public function __construct(public Collection $files) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->files->count();
        $label = $count === 1 ? 'a universal file' : $count.' universal files';
        $area = UniversalFileRules::nameFor($this->files->first()?->area_code);

        return (new MailMessage)
            ->subject('Universal accreditation files uploaded')
            ->greeting('Hello '.($notifiable->first_name ?: $notifiable->name).',')
            ->line('Quality Assurance uploaded '.$label.' under '.$area.'. Every program can use them.')
            ->action('View universal files', url('/user/dashboard/program-chair?section=universal-files'))
            ->line('Sign in to preview or download the PDFs. These files stay shared and are not counted as your program\'s area evidence.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $count = $this->files->count();
        $area = UniversalFileRules::nameFor($this->files->first()?->area_code);

        return [
            'type' => 'universal_file_uploaded',
            'title' => 'Universal files uploaded',
            'message' => $count === 1
                ? 'A shared file is available under '.$area.': '.$this->files->first()?->title
                : $count.' shared files are available under '.$area.'.',
            'action_url' => '/user/dashboard/program-chair?section=universal-files',
        ];
    }
}
