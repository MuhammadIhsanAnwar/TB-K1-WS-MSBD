<?php

namespace Tests\Unit;

use Database\Seeders\Wisatawan500Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class Wisatawan500SeederTest extends TestCase
{
    public function test_builds_10000_unique_complete_wisatawan_accounts_deterministically(): void
    {
        Hash::shouldReceive('make')
            ->with('test-password-placeholder')
            ->twice()
            ->andReturn('hashed-password');

        $seeder = new Wisatawan500Seeder;
        $rows = $this->buildRows($seeder);
        $again = $this->buildRows($seeder);

        $this->assertCount(10000, $rows);
        $this->assertCount(10000, array_unique(array_column($rows, 'email')));
        $this->assertCount(10000, array_unique(array_column($rows, 'name')));
        $this->assertSame(array_column($rows, 'email'), array_column($again, 'email'));
        $this->assertSame(array_column($rows, 'tanggal_lahir'), array_column($again, 'tanggal_lahir'));
        $this->assertSame(array_column($rows, 'email'), Wisatawan500Seeder::emailAkun());

        foreach ($rows as $row) {
            $this->assertSame(Str::slug($row['name'], '.').'@medanvisit.neoverse.my.id', $row['email']);
            $this->assertSame('081234567890', $row['no_hp']);
            $this->assertSame('wisatawan', $row['peran']);
            $this->assertSame('hashed-password', $row['password']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['tanggal_lahir']);
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function buildRows(Wisatawan500Seeder $seeder): array
    {
        $method = new ReflectionMethod($seeder, 'buatAkun');
        $method->setAccessible(true);

        return $method->invoke($seeder, 'test-password-placeholder');
    }
}
