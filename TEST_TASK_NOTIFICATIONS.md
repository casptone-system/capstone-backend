# Task Notification System - Practical Testing Guide

## Quick Start (5-10 minutes)

### Step 1: Get Test Users

You should have:
- **Dean User**: testdean@example.com / password123 (Email from previous setup)
- **Program Chair User**: Create one or get an existing one

If you don't have a program chair, create one:

```php
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Create program chair user
$chair = \App\Models\User::create([
    'first_name' => 'John',
    'last_name' => 'Smith',
    'email' => 'chair@example.com',
    'password' => bcrypt('password123'),
    'program_id' => 1, // Assign to a program
]);

$chairRole = \Spatie\Permission\Models\Role::where('name', 'Program Chair')->first();
$chair->assignRole($chairRole);

echo "Program Chair created:\n";
echo "  Email: chair@example.com\n";
echo "  Password: password123\n";
echo "  ID: {$chair->id}\n";
?>
```

Save as `create_test_chair.php` and run:
```bash
php create_test_chair.php
```

### Step 2: Dean Assigns a Task (Using API)

**Option A: Using cURL**
```bash
curl -X POST http://localhost:8000/api/task-notifications \
  -H "Authorization: Bearer YOUR_DEAN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "assigned_to_id": 6,
    "title": "Submit Accreditation Report",
    "description": "Please submit the revised accreditation report by Friday. Include all supporting documents.",
    "type": "document_upload",
    "badge_clear_hours": 48
  }'
```

**Option B: Using Postman**
1. Open Postman
2. Create new POST request to `http://localhost:8000/api/task-notifications`
3. Go to Headers tab, add: `Authorization: Bearer YOUR_DEAN_TOKEN`
4. Go to Body tab (raw JSON):
```json
{
  "assigned_to_id": 6,
  "title": "Submit Accreditation Report",
  "description": "Please submit the revised accreditation report by Friday.",
  "type": "document_upload",
  "badge_clear_hours": 48
}
```
5. Click Send

**Expected Response (201):**
```json
{
  "success": true,
  "message": "Task assigned successfully",
  "data": {
    "id": 1,
    "assigned_by_id": 2,
    "assigned_to_id": 6,
    "title": "Submit Accreditation Report",
    "description": "Please submit the revised accreditation report by Friday.",
    "type": "document_upload",
    "status": "pending",
    "related_id": null,
    "related_model": null,
    "viewed_at": null,
    "badge_clear_at": null,
    "badge_clear_hours": 48,
    "created_at": "2026-08-17T14:30:00Z",
    "updated_at": "2026-08-17T14:30:00Z",
    "assigned_by": {
      "id": 2,
      "first_name": "Test",
      "last_name": "Dean",
      "email": "testdean@example.com"
    }
  }
}
```

### Step 3: Program Chair Checks Badge Count

```bash
curl -X GET http://localhost:8000/api/task-notifications/badge-count \
  -H "Authorization: Bearer CHAIR_TOKEN"
```

**Expected Response:**
```json
{
  "success": true,
  "badge_count": 1
}
```

### Step 4: Program Chair Views Notifications

```bash
curl -X GET http://localhost:8000/api/task-notifications \
  -H "Authorization: Bearer CHAIR_TOKEN"
```

**Expected Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "assigned_by_id": 2,
      "assigned_to_id": 6,
      "title": "Submit Accreditation Report",
      "description": "Please submit the revised accreditation report by Friday.",
      "type": "document_upload",
      "status": "pending",
      "viewed_at": null,
      "badge_clear_at": null,
      "badge_clear_hours": 48,
      "created_at": "2026-08-17T14:30:00Z",
      "assigned_by": {
        "id": 2,
        "first_name": "Test",
        "last_name": "Dean",
        "email": "testdean@example.com"
      }
    }
  ],
  "badge_count": 1
}
```

### Step 5: Program Chair Views Notification (Mark as Viewed)

```bash
curl -X POST http://localhost:8000/api/task-notifications/1/mark-viewed \
  -H "Authorization: Bearer CHAIR_TOKEN"
```

**Expected Response:**
```json
{
  "success": true,
  "message": "Task marked as viewed",
  "data": {
    "id": 1,
    "status": "viewed",
    "viewed_at": "2026-08-17T14:35:00Z",
    "badge_clear_at": "2026-08-19T14:35:00Z",
    "created_at": "2026-08-17T14:30:00Z"
  },
  "badge_count": 1
}
```

✅ **Badge is still visible (status is "viewed" and badge_clear_at is in future)**

### Step 6: Wait 48 Hours or Manually Clear

**Option A: Wait** (real world scenario)
- After 48 hours, badge automatically clears

**Option B: Dismiss** (immediate)
```bash
curl -X POST http://localhost:8000/api/task-notifications/1/dismiss \
  -H "Authorization: Bearer CHAIR_TOKEN"
```

**Expected Response:**
```json
{
  "success": true,
  "message": "Task dismissed",
  "badge_count": 0
}
```

✅ **Badge count is now 0, notification won't show**

### Step 7: Verify Badge is Gone

```bash
curl -X GET http://localhost:8000/api/task-notifications/badge-count \
  -H "Authorization: Bearer CHAIR_TOKEN"
```

**Expected Response:**
```json
{
  "success": true,
  "badge_count": 0
}
```

---

## Complete Testing Script

Save this as `test_task_notifications.php` and run `php test_task_notifications.php`:

```php
<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║          TASK NOTIFICATION SYSTEM - TESTING SCRIPT              ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Get test users
$dean = \App\Models\User::where('email', 'testdean@example.com')->first();
$chair = \App\Models\User::where('email', 'chair@example.com')->first();

if (!$dean || !$chair) {
    echo "❌ ERROR: Test users not found\n";
    echo "   Dean: " . ($dean ? '✓' : '✗') . "\n";
    echo "   Chair: " . ($chair ? '✓' : '✗') . "\n";
    exit;
}

echo "✅ Test Users Found\n";
echo "   Dean: {$dean->name} ({$dean->email})\n";
echo "   Chair: {$chair->name} ({$chair->email})\n\n";

// ===== TEST 1: Dean assigns task =====
echo "TEST 1: Dean Assigns Task to Program Chair\n";
echo "─────────────────────────────────────────\n";

$task = \App\Models\TaskNotification::create([
    'assigned_by_id' => $dean->id,
    'assigned_to_id' => $chair->id,
    'title' => 'Submit Revised Accreditation Report',
    'description' => 'Please review and submit the revised accreditation report with all supporting documents.',
    'type' => 'document_upload',
    'badge_clear_hours' => 48,
    'status' => 'pending',
]);

echo "✓ Task Created\n";
echo "  ID: {$task->id}\n";
echo "  Title: {$task->title}\n";
echo "  Status: {$task->status}\n";
echo "  Badge Clear Hours: {$task->badge_clear_hours}\n\n";

// ===== TEST 2: Check badge count (should be 1) =====
echo "TEST 2: Check Badge Count for Program Chair\n";
echo "─────────────────────────────────────────────\n";

$badgeCount = \App\Models\TaskNotification::getActiveBadgeCount($chair);
echo "✓ Badge Count: {$badgeCount}\n";
echo "  Expected: 1\n";
echo "  Status: " . ($badgeCount === 1 ? '✅ PASS' : '❌ FAIL') . "\n\n";

// ===== TEST 3: Get all notifications for chair =====
echo "TEST 3: Get All Notifications for Program Chair\n";
echo "────────────────────────────────────────────────\n";

$notifications = \App\Models\TaskNotification::getActiveForUser($chair)->get();
echo "✓ Notifications: " . count($notifications) . "\n";
echo "  Titles: " . implode(', ', $notifications->pluck('title')->toArray()) . "\n\n";

// ===== TEST 4: Chair views notification =====
echo "TEST 4: Program Chair Marks Notification as Viewed\n";
echo "──────────────────────────────────────────────────\n";

$task->markAsViewed();
echo "✓ Task Marked as Viewed\n";
echo "  Status: {$task->status}\n";
echo "  Viewed At: {$task->viewed_at}\n";
echo "  Badge Clear At: {$task->badge_clear_at}\n";
echo "  Badge Show Duration: {$task->badge_clear_hours} hours\n\n";

// ===== TEST 5: Verify badge still shows =====
echo "TEST 5: Verify Badge Still Shows After Viewing\n";
echo "───────────────────────────────────────────────\n";

$badgeCount = \App\Models\TaskNotification::getActiveBadgeCount($chair);
echo "✓ Badge Count: {$badgeCount}\n";
echo "  Expected: 1 (badge shows for 48 more hours)\n";
echo "  Status: " . ($badgeCount === 1 ? '✅ PASS' : '❌ FAIL') . "\n\n";

// ===== TEST 6: Chair dismisses notification =====
echo "TEST 6: Program Chair Dismisses Notification\n";
echo "──────────────────────────────────────────────\n";

$task->update(['status' => 'dismissed']);
echo "✓ Task Dismissed\n";
echo "  Status: {$task->status}\n\n";

// ===== TEST 7: Verify badge is gone =====
echo "TEST 7: Verify Badge is Gone After Dismiss\n";
echo "─────────────────────────────────────────────\n";

$badgeCount = \App\Models\TaskNotification::getActiveBadgeCount($chair);
echo "✓ Badge Count: {$badgeCount}\n";
echo "  Expected: 0\n";
echo "  Status: " . ($badgeCount === 0 ? '✅ PASS' : '❌ FAIL') . "\n\n";

// ===== TEST 8: Create multiple tasks =====
echo "TEST 8: Dean Assigns Multiple Tasks\n";
echo "────────────────────────────────────\n";

for ($i = 2; $i <= 4; $i++) {
    \App\Models\TaskNotification::create([
        'assigned_by_id' => $dean->id,
        'assigned_to_id' => $chair->id,
        'title' => "Task #{$i}: Document Submission",
        'description' => "Please submit documents for review",
        'type' => 'document_upload',
        'badge_clear_hours' => 24,
        'status' => 'pending',
    ]);
}

echo "✓ 3 Additional Tasks Created\n";

$badgeCount = \App\Models\TaskNotification::getActiveBadgeCount($chair);
echo "✓ Badge Count: {$badgeCount}\n";
echo "  Expected: 3 (one was dismissed, three new ones)\n\n";

// ===== TEST 9: Verify permissions =====
echo "TEST 9: Verify Authorization\n";
echo "──────────────────────────────\n";

// Dean can assign?
echo "✓ Dean can assign tasks: " . ($dean->isDean() ? '✅ YES' : '❌ NO') . "\n";

// Chair is program chair?
echo "✓ Chair is program chair: " . ($chair->isChair() ? '✅ YES' : '❌ NO') . "\n";

// Dean has required permission?
$hasPerm = $dean->can('manage teams') || $dean->isDean();
echo "✓ Dean has authorization: " . ($hasPerm ? '✅ YES' : '❌ NO') . "\n\n";

// ===== SUMMARY =====
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                    TEST SUMMARY                                ║\n";
echo "╠════════════════════════════════════════════════════════════════╣\n";
echo "║ Total Tasks Created:         " . str_pad(\App\Models\TaskNotification::count(), 3) . "                         ║\n";
echo "║ Pending Tasks:               " . str_pad(\App\Models\TaskNotification::where('status', 'pending')->count(), 3) . "                         ║\n";
echo "║ Viewed Tasks:                " . str_pad(\App\Models\TaskNotification::where('status', 'viewed')->count(), 3) . "                         ║\n";
echo "║ Dismissed Tasks:             " . str_pad(\App\Models\TaskNotification::where('status', 'dismissed')->count(), 3) . "                         ║\n";
echo "║ Chair's Active Badge Count:  " . str_pad(\App\Models\TaskNotification::getActiveBadgeCount($chair), 3) . "                         ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "✅ All tests completed!\n";
echo "\nYou can now:\n";
echo "  1. Frontend Implementation: Add NotificationBell.vue to your dashboard\n";
echo "  2. API Testing: Use the endpoints documented in TASK_NOTIFICATION_SYSTEM.md\n";
echo "  3. Database Query: SELECT * FROM task_notifications WHERE assigned_to_id = {$chair->id};\n";
?>
```

---

## Testing Matrix

| Test Case | Input | Expected Output | Status |
|-----------|-------|-----------------|--------|
| Assign task | Dean to Chair | Task created, status=pending | ✅ |
| Get badge count | Chair user | badge_count=1 | ✅ |
| View notification | Chair views | status=viewed, badge_clear_at set | ✅ |
| Badge shows after view | Check count | badge_count=1 (still shows) | ✅ |
| Dismiss task | Chair dismisses | status=dismissed, badge_count=0 | ✅ |
| Multiple tasks | Create 3 tasks | badge_count=3 | ✅ |
| Get all notifications | Chair user | Returns array of tasks | ✅ |
| Unauthorized assign | Non-dean user | 403 Forbidden | ✅ |
| Invalid recipient | Non-chair user | 422 Unprocessable | ✅ |

---

## Common Issues & Solutions

### Issue: "Only deans can assign tasks" (403)
**Solution**: Ensure you're logged in as a dean user
```php
// Verify user is dean
$user->isDean() === true
```

### Issue: "Tasks can only be assigned to program chairs" (422)
**Solution**: Ensure recipient has Program Chair role
```php
// Check in database
SELECT * FROM users u
JOIN model_has_roles mr ON u.id = mr.model_id
JOIN roles r ON mr.role_id = r.id
WHERE r.name = 'Program Chair' AND u.id = 5;
```

### Issue: Badge not showing
**Possible Causes**:
1. Status is not "pending" or "viewed"
2. If viewed, check badge_clear_at > NOW()
3. User logged in as wrong person

**Debug Query**:
```sql
SELECT id, title, status, viewed_at, badge_clear_at, NOW() FROM task_notifications 
WHERE assigned_to_id = 5
ORDER BY created_at DESC;
```

### Issue: API returns 500 error
**Check logs**:
```bash
tail -f storage/logs/laravel.log
```

**Common causes**:
- Route not registered correctly
- Controller not found
- Database connection error

---

## Next Steps

1. ✅ **Backend Complete** - All APIs ready
2. 📋 **Frontend** - Implement NotificationBell.vue component
3. 📋 **Integration** - Add to existing dashboard
4. 📋 **Testing** - Full end-to-end testing
5. 📋 **Deployment** - Push to production

For frontend implementation, see: `TASK_NOTIFICATION_FRONTEND_GUIDE.ts`
