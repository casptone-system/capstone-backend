# Task Notifications with Invitations & User Management

This guide explains how to integrate task notifications into the user invitation and management workflows.

## Overview

Tasks can now be automatically assigned to users in three ways:

1. **Welcome Tasks** - Auto-assigned when users accept invitations
2. **Admin Tasks** - Assigned when admin creates users in user management
3. **Manual Tasks** - Assigned by deans to program chairs anytime

---

## Part 1: Invitations with Welcome Tasks

### How It Works

When you send an invitation:
1. Admin/Program Chair sends invitation with `send_welcome_task: true`
2. User joins via invitation code/link
3. System automatically creates a welcome task based on user's role
4. Welcome task appears with badge notification
5. Badge auto-clears after 3 days

### Sending Invitation with Welcome Task

**Endpoint:** `POST /api/programs/{program_id}/invitations`

```bash
curl -X POST http://localhost:8000/api/programs/1/invitations \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "jane@example.com",
    "role": "faculty",
    "expires_in_hours": 72,
    "send_welcome_task": true
  }'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "program_id": 1,
    "email": "jane@example.com",
    "role": "faculty",
    "token": "abc123def456...",
    "invited_by": 2,
    "send_welcome_task": true,
    "status": "pending",
    "expires_at": "2026-08-20T14:30:00Z",
    "created_at": "2026-08-17T14:30:00Z"
  }
}
```

### User Accepts Invitation

When user accepts the invitation via the token:

**Endpoint:** `POST /api/invitations/{token}/accept`

```bash
curl -X POST http://localhost:8000/api/invitations/abc123def456/accept \
  -H "Authorization: Bearer USER_TOKEN"
```

**What Happens:**
1. User is added to program
2. Welcome task is automatically created with role-specific title/description
3. Badge appears (count = 1)
4. Badge clears after 72 hours OR user dismisses it

### Welcome Task Template

Role-specific welcome tasks include custom titles and descriptions:

| Role | Welcome Task Title | Auto-Clear Hours |
|------|-------------------|-----------------|
| Faculty | Complete Your Faculty Profile | 72 |
| Program Chair | Program Chair Onboarding Setup | 72 |
| Dean | Dean Dashboard Overview | 72 |
| Area In-Charge | Area In-Charge Setup | 72 |
| QA | QA Dashboard Orientation | 72 |
| VPAA | VPAA Portal Setup | 72 |
| Accreditor | Accreditor Access Guide | 72 |

---

## Part 2: User Management with Tasks

### How It Works

When admin creates a user:
1. Admin creates user via "Create User" form
2. System assigns default welcome task automatically (configurable)
3. Admin can optionally assign additional specific tasks
4. User logs in and sees all assigned tasks with badge

### Create User with Welcome Task (Default)

**Endpoint:** `POST /api/admin/users`

**Minimal Request (Welcome Task Auto-Created):**
```bash
curl -X POST http://localhost:8000/api/admin/users \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "role": "faculty",
    "program_id": 1,
    "send_welcome_task": true
  }'
```

**Response:**
```json
{
  "success": true,
  "message": "User created successfully.",
  "data": {
    "id": 10,
    "name": "John Doe",
    "first_name": "John",
    "last_name": "Doe",
    "email": "john@example.com",
    "role": ["faculty"],
    "program_id": 1,
    "college_id": null
  }
}
```

### Create User with Welcome Task + Additional Tasks

**Request with Custom Tasks:**
```bash
curl -X POST http://localhost:8000/api/admin/users \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Jane",
    "last_name": "Smith",
    "email": "jane@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "role": "program chair",
    "program_id": 1,
    "send_welcome_task": true,
    "additional_tasks": [
      {
        "title": "Set up accreditation timeline",
        "description": "Please review and set up the accreditation timeline for your program",
        "type": "assignment"
      },
      {
        "title": "Review quality standards",
        "description": "Familiarize yourself with our quality standards",
        "type": "review"
      }
    ]
  }'
```

**Result:**
- Welcome task created: "Program Chair Onboarding Setup"
- Additional task 1: "Set up accreditation timeline"
- Additional task 2: "Review quality standards"
- Total badge count: 3

### Disable Welcome Task

To create a user WITHOUT automatic welcome task:

```bash
curl -X POST http://localhost:8000/api/admin/users \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Bob",
    "last_name": "Wilson",
    "email": "bob@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "role": "faculty",
    "program_id": 1,
    "send_welcome_task": false
  }'
```

---

## Part 3: Task Notification Actions

### Program Chair Viewing Tasks

```bash
# Get badge count
curl -X GET http://localhost:8000/api/task-notifications/badge-count \
  -H "Authorization: Bearer CHAIR_TOKEN"

# Get all active tasks
curl -X GET http://localhost:8000/api/task-notifications \
  -H "Authorization: Bearer CHAIR_TOKEN"

# Get pending only
curl -X GET http://localhost:8000/api/task-notifications/pending \
  -H "Authorization: Bearer CHAIR_TOKEN"
```

### Program Chair Managing Tasks

```bash
# Mark as viewed (badge still shows for 3 days)
curl -X POST http://localhost:8000/api/task-notifications/1/mark-viewed \
  -H "Authorization: Bearer CHAIR_TOKEN"

# Mark as completed (badge goes away)
curl -X POST http://localhost:8000/api/task-notifications/1/mark-completed \
  -H "Authorization: Bearer CHAIR_TOKEN"

# Dismiss (badge goes away)
curl -X POST http://localhost:8000/api/task-notifications/1/dismiss \
  -H "Authorization: Bearer CHAIR_TOKEN"
```

---

## Part 4: Dean Assigning Tasks

Deans can assign tasks to program chairs anytime (not just invitations).

**Endpoint:** `POST /api/task-notifications`

```bash
curl -X POST http://localhost:8000/api/task-notifications \
  -H "Authorization: Bearer DEAN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "assigned_to_id": 5,
    "title": "Submit revised accreditation report",
    "description": "Please submit the revised report by Friday",
    "type": "document_upload",
    "badge_clear_hours": 48
  }'
```

---

## Database Schema

### task_notifications Table

```
id                  BIGINT PRIMARY KEY
assigned_by_id      BIGINT (User who assigned)
assigned_to_id      BIGINT (User who receives)
title              VARCHAR(255)
description        TEXT
type               VARCHAR (document_upload|review|approval|assignment|onboarding|other)
is_welcome_task    BOOLEAN (true if from welcome)
invitation_id      BIGINT (link to invitation if applicable)
status             VARCHAR (pending|viewed|completed|dismissed)
related_id         BIGINT (ID of related model)
related_model      VARCHAR (Model class)
viewed_at          TIMESTAMP
badge_clear_at     TIMESTAMP
badge_clear_hours  INT (default: 48 for normal, 72 for welcome)
created_at         TIMESTAMP
updated_at         TIMESTAMP
```

### invitations Table (Updated)

```
id                  BIGINT PRIMARY KEY
program_id          BIGINT
team_id             BIGINT
email              VARCHAR(255)
role               VARCHAR(255)
token              VARCHAR(64) UNIQUE
invited_by         BIGINT
used_by            BIGINT
send_welcome_task  BOOLEAN (default: true)
welcome_task_id    BIGINT (FK to task_notifications)
status             ENUM (pending|requested|accepted|expired|revoked)
expires_at         TIMESTAMP
accepted_at        TIMESTAMP
created_at         TIMESTAMP
updated_at         TIMESTAMP
```

---

## Complete Workflow Example

### Scenario: Admin creates Program Chair with tasks

```bash
# Step 1: Admin creates program chair
curl -X POST http://localhost:8000/api/admin/users \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -d '{
    "first_name": "Sarah",
    "last_name": "Johnson",
    "email": "sarah@example.com",
    "password": "SecurePass123!",
    "password_confirmation": "SecurePass123!",
    "role": "program chair",
    "program_id": 1,
    "send_welcome_task": true,
    "additional_tasks": [
      {
        "title": "Complete chair profile",
        "description": "Add your office hours and contact information",
        "type": "assignment"
      },
      {
        "title": "Review accreditation standards",
        "description": "Review the program accreditation standards",
        "type": "review"
      }
    ]
  }'

# Response includes user ID: 15

# Step 2: Program chair logs in and checks badge
curl -X GET http://localhost:8000/api/task-notifications/badge-count \
  -H "Authorization: Bearer SARAH_TOKEN"

# Response: badge_count = 3 (welcome + 2 additional)

# Step 3: Program chair views notifications
curl -X GET http://localhost:8000/api/task-notifications \
  -H "Authorization: Bearer SARAH_TOKEN"

# Response: Array of 3 tasks

# Step 4: Program chair marks welcome task as viewed
curl -X POST http://localhost:8000/api/task-notifications/1/mark-viewed \
  -H "Authorization: Bearer SARAH_TOKEN"

# Step 5: Badge count still 3 (welcome shows for 72 hours)
curl -X GET http://localhost:8000/api/task-notifications/badge-count \
  -H "Authorization: Bearer SARAH_TOKEN"

# Response: badge_count = 3

# Step 6: Program chair dismisses a task
curl -X POST http://localhost:8000/api/task-notifications/2/dismiss \
  -H "Authorization: Bearer SARAH_TOKEN"

# Step 7: Badge count now 2
curl -X GET http://localhost:8000/api/task-notifications/badge-count \
  -H "Authorization: Bearer SARAH_TOKEN"

# Response: badge_count = 2
```

---

## Frontend Integration

### Display Badge in Dashboard Header

```vue
<template>
  <div class="header">
    <NotificationBell /> <!-- Shows badge count -->
  </div>
</template>
```

### In NotificationBell Component

```typescript
onMounted(() => {
  // Get badge count
  taskStore.fetchBadgeCount();
  
  // Poll every 30 seconds
  setInterval(() => {
    taskStore.fetchBadgeCount();
  }, 30000);
});
```

---

## Testing

### Quick Test Script

```php
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Create test invitation with welcome task
$user = \App\Models\User::where('email', 'dean@example.com')->first();
$program = \App\Models\Program::first();

$invitation = \App\Models\Invitation::create([
    'program_id' => $program->id,
    'email' => 'test@example.com',
    'role' => 'faculty',
    'token' => bin2hex(random_bytes(24)),
    'invited_by' => $user->id,
    'send_welcome_task' => true,
    'status' => 'pending',
]);

echo "✓ Invitation created with ID: {$invitation->id}\n";
echo "✓ send_welcome_task: " . ($invitation->send_welcome_task ? 'YES' : 'NO') . "\n";

// Simulate user accepting invitation
$newUser = \App\Models\User::create([
    'first_name' => 'Test',
    'last_name' => 'User',
    'email' => 'test@example.com',
    'password' => bcrypt('password123'),
]);

// Accept invitation (triggers welcome task)
if ($invitation->send_welcome_task) {
    try {
        $invitation->createWelcomeTask($newUser, $user);
        echo "✓ Welcome task created\n";
    } catch (\Exception $e) {
        echo "✗ Failed to create welcome task: " . $e->getMessage() . "\n";
    }
}

// Verify badge count
$badgeCount = \App\Models\TaskNotification::getActiveBadgeCount($newUser);
echo "✓ Badge count for new user: {$badgeCount}\n";
?>
```

---

## Error Handling

### Common Errors & Solutions

| Error | Cause | Solution |
|-------|-------|----------|
| "Only deans can assign tasks" | Non-dean trying to assign | Check user role |
| "Tasks can only be assigned to program chairs" | Assigning to non-chair | Verify recipient role |
| 403 Forbidden on create user | User not super admin | Authenticate as super admin |
| Welcome task not created | send_welcome_task=false | Set to true in request |
| Badge not showing | Task dismissed or timed out | Check badge_clear_at timestamp |

---

## Best Practices

1. **Welcome Tasks**: Always use them when sending invitations - helps users understand what to do
2. **Additional Tasks**: Be specific with titles and descriptions
3. **Badge Timing**: Default 48 hours for regular tasks, 72 hours for welcome tasks
4. **Dismiss vs Complete**: Complete when done, dismiss to remove from inbox
5. **Role-Specific**: Welcome tasks auto-generate based on role - no need to customize

---

## FAQ

**Q: Can I disable welcome tasks for all new invitations?**
A: Yes, use `"send_welcome_task": false` when creating invitation

**Q: How long does badge show after being marked viewed?**
A: 48-72 hours depending on badge_clear_hours (configurable)

**Q: Can users manually clear badges?**
A: Yes, by dismissing or marking as completed

**Q: Are welcome tasks included in invitation email?**
A: No, task appears after user joins. Customize invitation email to mention it.

**Q: Can I edit a task after assigning?**
A: No, create new task and dismiss the old one

**Q: Do welcome tasks differ by role?**
A: Yes, they're automatically customized by the invitee's role
