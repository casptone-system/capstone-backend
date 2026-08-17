<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$college = \App\Models\College::firstOrCreate(["code" => "CAS"], ["name" => "College of Arts and Sciences"]);
echo "College: " . $college->name . " (ID: " . $college->id . ")\n";

$dean = \App\Models\User::where("email", "testdean@example.com")->first();
if (!$dean) {
    $dean = \App\Models\User::create([
      "first_name" => "Test",
      "last_name" => "Dean",
      "email" => "testdean@example.com",
      "password" => bcrypt("password123"),
      "college_id" => $college->id
    ]);
}
echo "Dean User: " . $dean->name . " (ID: " . $dean->id . ", College ID: " . $dean->college_id . ")\n";

$deanRole = \Spatie\Permission\Models\Role::where("name", "Dean")->first();
$dean->assignRole($deanRole);
echo "Dean role assigned\n";

$perms = $dean->getPermissionNames()->toArray();
echo "Dean permissions: " . implode(", ", $perms) . "\n";
echo "Total: " . count($perms) . " permissions\n";

if (in_array("access-college-dashboard", $perms)) {
  echo "? access-college-dashboard is PRESENT\n";
} else {
  echo "? access-college-dashboard is MISSING\n";
}

