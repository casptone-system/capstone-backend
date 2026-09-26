<?php

namespace App\Notifications;

use App\Models\AccreditationArea;
use App\Models\ParameterContentRow;
use App\Models\ParameterRowComment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ParameterRowCommentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ParameterContentRow $row,
        private readonly ParameterRowComment $comment,
        private readonly User $author,
        private readonly AccreditationArea $area,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $areaLabel = $this->area->sidebarLabel();
        $preview = Str::limit($this->comment->body, 140);
        $isRevisionRequest = $this->comment->source === ParameterRowComment::SOURCE_REVISION_REQUEST;

        return [
            'type' => 'parameter_row_comment',
            'title' => $isRevisionRequest
                ? "Revision requested on {$areaLabel}"
                : "New comment on {$areaLabel}",
            'message' => "{$this->author->name} ".($isRevisionRequest ? 'requested revisions' : 'commented').": \"{$preview}\"",
            'area_id' => $this->area->id,
            'area_name' => $this->area->name,
            'parameter_id' => $this->row->parameter_id,
            'content_row_id' => $this->row->id,
            'comment_id' => $this->comment->id,
            'author_name' => $this->author->name,
            'author_role' => $this->comment->author_role,
            'source' => $this->comment->source,
            'action_url' => $this->actionUrl(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $areaLabel = $this->area->sidebarLabel();
        $isRevisionRequest = $this->comment->source === ParameterRowComment::SOURCE_REVISION_REQUEST;

        return (new MailMessage)
            ->subject($isRevisionRequest ? "Revision requested on {$areaLabel}" : "New comment on {$areaLabel}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("{$this->author->name} ({$this->comment->author_role}) left a new comment on a content row in {$areaLabel}.")
            ->line('"'.$this->comment->body.'"')
            ->action('View Comment Thread', url($this->actionUrl()))
            ->line('Please log in to your ADAMS dashboard to respond.');
    }

    private function actionUrl(): string
    {
        return '/user/dashboard?areaId='.$this->area->id.'&rowId='.$this->row->id;
    }
}
