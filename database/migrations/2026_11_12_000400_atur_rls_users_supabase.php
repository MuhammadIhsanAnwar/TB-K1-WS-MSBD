<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'katalog';

    public function up(): void
    {
        $connection = Schema::connection($this->connection)->getConnection();

        $connection->statement('ALTER TABLE users ENABLE ROW LEVEL SECURITY');
        $connection->statement('DROP POLICY IF EXISTS "server can insert users" ON users');
        $connection->statement('DROP POLICY IF EXISTS "server can read users by email" ON users');
        $connection->statement('DROP POLICY IF EXISTS "server can update users" ON users');
    }

    public function down(): void
    {
        $connection = Schema::connection($this->connection)->getConnection();
        $connection->statement('DROP POLICY IF EXISTS "server can insert users" ON users');
        $connection->statement('DROP POLICY IF EXISTS "server can read users by email" ON users');
        $connection->statement('DROP POLICY IF EXISTS "server can update users" ON users');
    }
};
