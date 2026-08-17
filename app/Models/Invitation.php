<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'program_id', 'team_id', 'email', 'role', 'token', 'invited_by', 'used_by', 'expires_at', 'accepted_at', 'status',
        'send_welcome_task', 'welcome_task_id'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'send_welcome_task' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    /**
     * Get the welcome task associated with this invitation
     */
    public function welcomeTask(): HasOne
    {
        return $this->hasOne(TaskNotification::class, 'id', 'welcome_task_id');
    }

    /**
     * Create a welcome task when user accepts invitation
     */
    public function createWelcomeTask(User $user, User $admin = null): TaskNotification
    {
        // Determine who creates the task (admin or inviter)
        $assignedById = $admin?->id ?? $this->invited_by ?? 1; // Fallback to super admin

        $title = $this->getWelcomeTaskTitle();
        $description = $this->getWelcomeTaskDescription($user);

        $task = TaskNotification::create([
            'assigned_by_id' => $assignedById,
            'assigned_to_id' => $user->id,
            'title' => $title,
            'description' => $description,
            'type' => 'onboarding',
            'is_welcome_task' => true,
            'invitation_id' => $this->id,
            'badge_clear_hours' => 72, // 3 days for welcome tasks
            'status' => 'pending',
        ]);

        // Update invitation with task reference
        $this->update(['welcome_task_id' => $task->id]);

        return $task;
    }

    /**
     * Get the welcome task title based on user role
     */
    private function getWelcomeTaskTitle(): string
    {
        return match (strtolower($this->role ?? 'faculty')) {
            'faculty' => 'Complete Your Faculty Profile',
            'program chair' => 'Program Chair Onboarding Setup',
            'dean' => 'Dean Dashboard Overview',
            'area in-charge' => 'Area In-Charge Setup',
            'qa' => 'QA Dashboard Orientation',
            'vpaa' => 'VPAA Portal Setup',
            'accreditor' => 'Accreditor Access Guide',
            default => 'Welcome to the System',
        };
    }

    /**
     * Get the welcome task description based on role
     */
    private function getWelcomeTaskDescription(User $user): string
    {
        $role = strtolower($this->role ?? 'faculty');

        return match ($role) {
            'faculty' => "Welcome {$user->first_name}! Please complete your faculty profile with your contact information and qualifications. This helps us keep your records up to date.",
            
            'program chair' => "Welcome {$user->first_name}! As a Program Chair, please review the accreditation dashboard, familiarize yourself with the accreditation structure for your program, and contact your dean if you have any questions.",
            
            'dean' => "Welcome {$user->first_name}! Your dean dashboard is now ready. Please review the college structure, connect with your program chairs, and set up your college profile to get started.",
            
            'area in-charge' => "Welcome {$user->first_name}! You are now assigned as an Area In-Charge. Please review the accreditation areas assigned to you and familiarize yourself with the review process.",
            
            'qa' => "Welcome {$user->first_name}! As a Quality Assurance coordinator, you now have access to monitor all accreditation activities. Please review the QA dashboard to get started.",
            
            'vpaa' => "Welcome {$user->first_name}! As VPAA, you have full oversight of all accreditation cycles. Please review the academic administration portal and set up accreditation parameters.",
            
            'accreditor' => "Welcome {$user->first_name}! You now have access to review accreditation submissions. Please review the accreditor guide and familiarize yourself with the evaluation process.",
            
            default => "Welcome {$user->first_name}! Your account has been created successfully. Please explore the system and familiarize yourself with the available features.",
        };
    }
}
