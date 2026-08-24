<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];
        $type = $data['type'] ?? Str::snake(class_basename((string) $this->type));
        $title = $this->resolveTitle($type, $data);
        $message = $this->resolveMessage($type, $data);
        $actionUrl = $data['action_url'] ?? $this->fallbackActionUrl($type, $data);
        $isRead = $this->read_at !== null;
        $createdAt = $this->created_at?->toDateTimeString();
        $readAt = $this->read_at?->toDateTimeString();

        return [
            'id' => $this->id,
            'type' => $type,
            'title' => $title,
            'subject' => $title,
            'message' => $message,
            'body' => $message,
            'data' => $data,
            'actionUrl' => $actionUrl,
            'action_url' => $actionUrl,
            'readAt' => $readAt,
            'read_at' => $readAt,
            'createdAt' => $createdAt,
            'created_at' => $createdAt,
            'updatedAt' => $this->updated_at?->toDateTimeString(),
            'isRead' => $isRead,
            'read' => $isRead,
            'hasInstrument' => ! empty($data['instrument_file_name']),
            'instrumentFileName' => $data['instrument_file_name'] ?? null,
        ];
    }

    private function resolveTitle(string $type, array $data): string
    {
        if (! empty($data['title'])) {
            return (string) $data['title'];
        }

        if (! empty($data['subject'])) {
            return (string) $data['subject'];
        }

        return match ($type) {
            'faculty_area_assignment' => 'Assigned to '.($data['area_name'] ?? 'an accreditation area'),
            'accreditation_area_assigned' => 'Accreditation area assigned',
            'dean_task_assignment' => 'Accreditation task from '.($data['dean_name'] ?? 'the Dean'),
            'faculty_submission' => 'New area submission',
            'dean_assigned' => 'Assigned as Dean',
            'program_chair_assigned' => 'Assigned as Program Chair',
            'program_chair_handover' => 'Program Chair update',
            'accreditation_cycle_notice' => 'Accreditation notice',
            'task_assigned' => 'New task assigned',
            'document_uploaded' => 'New document uploaded',
            'review_requested' => 'Review requested',
            'review_approved' => 'Review approved',
            'review_rejected' => 'Review rejected',
            'deadline_near' => 'Deadline approaching',
            'task_approved' => 'Task approved',
            'task_returned' => 'Task returned for revision',
            default => Str::headline(str_replace('_', ' ', $type ?: 'Notification')),
        };
    }

    private function resolveMessage(string $type, array $data): string
    {
        if (! empty($data['message'])) {
            return (string) $data['message'];
        }

        if (! empty($data['body'])) {
            return (string) $data['body'];
        }

        if (! empty($data['description'])) {
            return (string) $data['description'];
        }

        return match ($type) {
            'faculty_area_assignment' => trim(sprintf(
                'You have been assigned to %s for %s.%s',
                $data['area_name'] ?? 'an accreditation area',
                $data['program_name'] ?? 'your program',
                ! empty($data['deadline']) ? ' Deadline: '.$data['deadline'].'.' : ''
            )),
            'accreditation_area_assigned' => 'You are assigned as Area In-Charge for '.($data['area_name'] ?? 'an accreditation area').'.',
            'dean_task_assignment' => ($data['dean_name'] ?? 'The Dean').' assigned accreditation work for '.($data['program_name'] ?? 'your program').'.',
            'faculty_submission' => ($data['faculty_name'] ?? 'A faculty member').' submitted evidence for '.($data['area_name'] ?? 'an accreditation area').'.',
            default => 'You have a new notification.',
        };
    }

    private function fallbackActionUrl(string $type, array $data): ?string
    {
        return match ($type) {
            'faculty_area_assignment', 'accreditation_area_assigned' => '/user/dashboard/faculty?section=areas',
            'dean_task_assignment' => '/user/dashboard/program-chair?section=notifications',
            'faculty_submission' => '/user/dashboard/program-chair?section=review',
            'dean_assigned' => '/user/dashboard/dean',
            'program_chair_assigned', 'program_chair_handover' => '/user/dashboard/program-chair',
            'accreditation_cycle_notice' => '/user/dashboard/dean?section=notifications',
            'task_assigned', 'deadline_near', 'task_approved', 'task_returned' => '/user/dashboard/faculty?section=revisions',
            'document_uploaded' => '/user/dashboard/faculty?section=documents',
            'review_requested' => '/user/dashboard/program-chair?section=review',
            'review_approved', 'review_rejected' => '/user/dashboard/faculty?section=revisions',
            default => $data['action_url'] ?? null,
        };
    }
}
