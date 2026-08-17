DB::table('roles')->where('name', 'faculty')->update(['name' => 'Faculty']);
DB::table('roles')->where('name', 'superadmin')->update(['name' => 'SuperAdmin']);
DB::table('roles')->where('name', 'program-chair')->update(['name' => 'ProgramChair']);
DB::table('roles')->where('name', 'program Chair')->update(['name' => 'ProgramChair']);
DB::table('roles')->where('name', 'qa')->update(['name' => 'QA']);
DB::table('roles')->where('name', 'vpaa/di')->update(['name' => 'VPAA']);
DB::table('roles')->where('name', 'area-in-charge')->update(['name' => 'AreaInCharge']);
DB::table('roles')->get(['id', 'name'])->each(fn($r) => print("{\->id}: {\->name}\n"));
