# Task Notification System - Complete Documentation

## System Overview

The Task Notification System allows deans to assign tasks to program chairs with an automatic badge system that:
- Shows a visual badge (numbered) on the dashboard
- Displays count of active notifications
- Marks notifications as viewed when clicked
- Auto-clears badges after a configurable time period (default: 48 hours)

## Database Schema

### task_notifications Table

```sql
CREATE TABLE task_notifications (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    assigned_by_id BIGINT NOT NULL,          -- Dean user ID
    assigned_to_id BIGINT NOT NULL,          -- Program Chair user ID
    title VARCHAR(255) NOT NULL,             -- Task title
    description TEXT,                        -- Detailed description
    type VARCHAR(255) DEFAULT 'document_upload', -- Task type
    status VARCHAR(255) DEFAULT 'pending',   -- pending, viewed, completed, dismissed
    related_id BIGINT,                       -- ID of related model (document, review, etc)
    related_model VARCHAR(255),              -- Model class name
    viewed_at TIMESTAMP NULL,                -- When first viewed
    badge_clear_at TIMESTAMP NULL,           -- When badge should auto-clear
    badge_clear_hours INT DEFAULT 48,        -- Hours before badge auto-clears
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (assigned_by_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX (assigned_to_id),
    INDEX (status),
    INDEX (badge_clear_at)
);
```

## Task Notification Statuses

| Status | Meaning | Badge Visible |
|--------|---------|---------------|
| `pending` | Task just assigned, not yet viewed | ✅ Yes |
| `viewed` | User has seen the notification | ✅ Yes (until badge_clear_at time) |
| `completed` | Task marked as done | ❌ No |
| `dismissed` | User dismissed the notification | ❌ No |

## API Endpoints

### 1. Get All Active Task Notifications
```
GET /api/task-notifications
Authorization: Bearer {token}

Response:
{
  "success": true,
  "data": [
    {
      "id": 1,
      "assigned_by_id": 2,
      "assigned_to_id": 5,
      "title": "Submit Accreditation Report",
      "description": "Please review and submit...",
      "type": "document_upload",
      "status": "pending",
      "related_id": null,
      "related_model": null,
      "viewed_at": null,
      "badge_clear_at": null,
      "badge_clear_hours": 48,
      "created_at": "2026-08-17T10:30:00Z",
      "updated_at": "2026-08-17T10:30:00Z",
      "assigned_by": {
        "id": 2,
        "first_name": "John",
        "last_name": "Doe",
        "email": "john@example.com"
      }
    }
  ],
  "badge_count": 3
}
```

### 2. Get Pending Tasks Only
```
GET /api/task-notifications/pending
Authorization: Bearer {token}

Response:
{
  "success": true,
  "data": [...],
  "count": 2
}
```

### 3. Get Badge Count (for Dashboard)
```
GET /api/task-notifications/badge-count
Authorization: Bearer {token}

Response:
{
  "success": true,
  "badge_count": 3
}
```

### 4. Dean Assigns Task to Program Chair (MOST IMPORTANT)
```
POST /api/task-notifications
Authorization: Bearer {token}
Content-Type: application/json

Request Body:
{
  "assigned_to_id": 5,                    // Program Chair user ID (required)
  "title": "Submit revised documents",    // Task title (required)
  "description": "Please revise the...",  // Detailed description (optional)
  "type": "document_upload",              // Type: document_upload, review, approval, assignment, other
  "badge_clear_hours": 48,                // Hours before badge auto-clears (1-720)
  "related_id": 123,                      // ID of related document/review (optional)
  "related_model": "Document"             // Model class name (optional)
}

Response (201 Created):
{
  "success": true,
  "message": "Task assigned successfully",
  "data": {
    "id": 1,
    "assigned_by_id": 2,
    "assigned_to_id": 5,
    "title": "Submit revised documents",
    ...
  }
}

Error Cases:
- 403: User is not a dean
- 422: Assigned user is not a program chair
```

### 5. Mark Notification as Viewed
```
POST /api/task-notifications/{id}/mark-viewed
Authorization: Bearer {token}

Response:
{
  "success": true,
  "message": "Task marked as viewed",
  "data": {
    "id": 1,
    "status": "viewed",
    "viewed_at": "2026-08-17T11:00:00Z",
    "badge_clear_at": "2026-08-19T11:00:00Z",  // Now + 48 hours
    ...
  },
  "badge_count": 2  // Reduced from 3
}
```

### 6. Mark as Completed
```
POST /api/task-notifications/{id}/mark-completed
Authorization: Bearer {token}

Response:
{
  "success": true,
  "message": "Task marked as completed",
  "data": {
    "id": 1,
    "status": "completed",
    ...
  }
}
```

### 7. Dismiss Notification
```
POST /api/task-notifications/{id}/dismiss
Authorization: Bearer {token}

Response:
{
  "success": true,
  "message": "Task dismissed",
  "badge_count": 2  // Updated count
}
```

## Badge Behavior

### Badge Shows When:
1. **Status is "pending"** - Task just assigned, not yet viewed
2. **Status is "viewed" AND badge_clear_at is in the future** - Recently viewed but not yet timed out

### Badge Clears When:
1. **User marks as completed** - Task is done
2. **User dismisses** - User explicitly dismissed it
3. **badge_clear_at time passes** - Automatic timeout (default 48 hours)
4. **Status becomes "completed"** - Task marked complete

## Database Queries

### Get Active Badge Count for User
```sql
SELECT COUNT(*) as badge_count FROM task_notifications
WHERE assigned_to_id = :user_id
AND (
  status = 'pending'
  OR (status = 'viewed' AND badge_clear_at > NOW())
)
```

### Get All Active Notifications for User
```sql
SELECT * FROM task_notifications
WHERE assigned_to_id = :user_id
AND (
  status = 'pending'
  OR (status = 'viewed' AND badge_clear_at > NOW())
)
ORDER BY created_at DESC
```

## Example Workflows

### Workflow 1: Dean Assigns Task
```
1. Dean clicks "Assign Task" button
2. Dean selects Program Chair, enters task details
3. Dean clicks "Submit"
   → POST /api/task-notifications
   → Task created with status: "pending"
   
4. Program Chair sees badge on dashboard (e.g., "3")
5. Program Chair clicks bell icon → sees new notification in panel
6. Program Chair clicks notification
   → POST /api/task-notifications/{id}/mark-viewed
   → Status changes to "viewed"
   → viewed_at = NOW
   → badge_clear_at = NOW + 48 hours
   → badge_count decreases (if other pending tasks)
   
7. Badge auto-clears after 48 hours OR when Program Chair:
   - Marks as completed
   - Dismisses notification
```

### Workflow 2: Frontend Badge Display
```
1. Dashboard loads
2. NotificationBell component mounts
3. Fetch badge count: GET /api/task-notifications/badge-count
   → Response: badge_count = 3
4. Display bell icon with red badge "3"
5. Poll for updates every 30 seconds
6. If user clicks bell:
   - GET /api/task-notifications (with latest data)
   - Show popup with all notifications
7. When user clicks notification:
   - POST /api/task-notifications/{id}/mark-viewed
   - Update badge count in real-time
   - Update UI to show "viewed" status
```

## Implementation Checklist

### Backend (✅ Completed)
- [x] Create migration: `2026_08_17_create_task_notifications_table.php`
- [x] Create model: `TaskNotification.php`
- [x] Create controller: `TaskNotificationController.php`
- [x] Create API routes in `routes/api.php`
- [x] Run migrations: `php artisan migrate`
- [x] Test via Postman/API client

### Frontend (📋 To Do)
- [ ] Create API service: `src/lib/taskNotificationAPI.ts`
- [ ] Create store: `src/stores/taskNotificationStore.ts`
- [ ] Create component: `src/components/NotificationBell.vue`
- [ ] Add NotificationBell to header/layout
- [ ] Create DeanTaskAssignment component (optional)
- [ ] Add polling for real-time updates
- [ ] Test in browser

## Testing with cURL

### Assign Task
```bash
curl -X POST http://localhost:8000/api/task-notifications \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "assigned_to_id": 5,
    "title": "Submit Accreditation Report",
    "description": "Please submit the revised accreditation report",
    "type": "document_upload",
    "badge_clear_hours": 48
  }'
```

### Get Badge Count
```bash
curl -X GET http://localhost:8000/api/task-notifications/badge-count \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Mark as Viewed
```bash
curl -X POST http://localhost:8000/api/task-notifications/1/mark-viewed \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Performance Considerations

1. **Badge Count Polling**: Every 30 seconds is reasonable for most applications
2. **Indexes**: Database has indexes on:
   - `assigned_to_id` (for quick lookups)
   - `status` (for filtering)
   - `badge_clear_at` (for auto-cleanup)

3. **Auto-cleanup**: Consider adding a scheduled job to mark expired notifications:
```php
// In App\Console\Kernel.php
$schedule->call(function () {
    TaskNotification::where('status', 'viewed')
        ->where('badge_clear_at', '<=', now())
        ->delete();
})->everyFiveMinutes();
```

## Security

- ✅ User authorization: Only assigned recipient can view/modify
- ✅ Role-based: Only deans can assign tasks
- ✅ Recipient validation: Tasks can only be assigned to program chairs
- ✅ Soft delete: No hard delete, preserves audit trail
- ✅ Audit logging: All changes logged via audit middleware

## Troubleshooting

### Badge not showing?
1. Check task status: `SELECT status FROM task_notifications WHERE id = 1`
2. Check badge_clear_at: `SELECT badge_clear_at, NOW() FROM task_notifications WHERE id = 1`
3. Verify assigned_to_id matches current user

### Badge not clearing?
1. Confirm badge_clear_at < NOW() if status is "viewed"
2. Check browser cache - force refresh
3. Verify polling interval in NotificationBell component

### Task not appearing for recipient?
1. Verify assigned_to_id is correct
2. Check user role is program chair
3. Verify status is not "dismissed" or "completed"

## Future Enhancements

1. **Real-time Notifications**: WebSocket or Server-Sent Events (SSE)
2. **Email Notifications**: Send email when task assigned
3. **Task Dependencies**: Link multiple tasks together
4. **Recurring Tasks**: Auto-assign tasks on schedule
5. **Analytics**: Track task completion rates
6. **Reminders**: Send reminder before badge_clear_at expires
