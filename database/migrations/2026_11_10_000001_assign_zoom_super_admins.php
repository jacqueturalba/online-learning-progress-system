<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private array $emails = ['jacqueturalba@gmail.com', 'ministryofpublicity.ph@gmail.com'];

    public function up(): void
    {
        DB::table('users')->whereIn('email', $this->emails)->update(['role' => 'super_admin', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('users')->whereIn('email', $this->emails)->where('role', 'super_admin')->update(['role' => 'admin', 'updated_at' => now()]);
    }
};
