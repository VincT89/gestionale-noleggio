<?php

namespace Tests\Feature;

use App\Livewire\Rentals\RentalsBoard;
use App\Models\Rental;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RentalSearchTest extends TestCase
{
    private const CONNECTION = 'rental_search_test';

    protected function setUp(): void
    {
        parent::setUp();

        // Use a separate, disposable database without running application migrations.
        config()->set('database.connections.'.self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $connection = DB::connection(self::CONNECTION);
        $this->assertSame(':memory:', $connection->getDatabaseName());
        $schema = $connection->getSchemaBuilder();

        $schema->create('rentals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('number_id')->nullable();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->string('status');
            $table->softDeletes();
        });

        foreach (['customers' => 'name', 'vehicles' => 'plate'] as $tableName => $field) {
            $schema->create($tableName, function (Blueprint $table) use ($field) {
                $table->id();
                $table->string($field);
                $table->softDeletes();
            });
        }

        $connection->table('customers')->insert([
            ['id' => 1, 'name' => 'Cliente Ricerca', 'deleted_at' => null],
            ['id' => 2, 'name' => 'Cliente Secondario', 'deleted_at' => null],
            ['id' => 3, 'name' => 'Cliente Eliminato', 'deleted_at' => '2026-01-01 00:00:00'],
        ]);
        $connection->table('vehicles')->insert([
            ['id' => 1, 'plate' => 'AA001AA', 'deleted_at' => null],
            ['id' => 2, 'plate' => 'BB002BB', 'deleted_at' => null],
            ['id' => 3, 'plate' => 'CC003CC', 'deleted_at' => '2026-01-01 00:00:00'],
        ]);

        $columns = ['id', 'number_id', 'organization_id', 'customer_id', 'vehicle_id', 'status', 'deleted_at'];
        $connection->table('rentals')->insert(array_map(
            fn (array $row) => array_combine($columns, $row),
            [
                [737, 152, 1, 1, 1, 'draft', null],
                [738, 1152, 1, 1, 1, 'reserved', null],
                [739, 152, 2, 1, 1, 'draft', null],
                [740, 154, 1, 3, 3, 'draft', null],
                [741, 2152, 1, 1, 1, 'draft', '2026-01-01 00:00:00'],
                [742, 156, 1, 2, 2, 'draft', null],
                [900, null, 1, 2, 2, 'draft', null],
            ],
        ));
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);
        parent::tearDown();
    }

    public static function searchCases(): array
    {
        return [
            'visible contract number' => ['152', [737]],
            'copied contract label' => ['#152', [737]],
            'label with whitespace' => ['  # 152  ', [737]],
            'internal identifier remains searchable' => ['737', [737]],
            'customer name' => ['Ricerca', [737]],
            'vehicle plate' => ['AA001AA', [737]],
            'legacy label without number' => ['#900', [900]],
            'deleted customer excluded' => ['Eliminato', []],
            'deleted vehicle excluded' => ['CC003CC', []],
            'empty search keeps existing filters' => ['   ', [737, 740, 742, 900]],
            'unknown number' => ['#99999', []],
            'quotes remain search text' => ["' OR 1=1 --", []],
        ];
    }

    #[DataProvider('searchCases')]
    public function test_search_respects_visible_numbers_and_existing_filters(string $term, array $expected): void
    {
        $board = new class extends RentalsBoard
        {
            public function searchQuery(Builder $query): Builder
            {
                return $this->applySearch($query);
            }
        };
        $board->q = $term;

        $query = (new Rental)->setConnection(self::CONNECTION)->newQuery()
            ->where('organization_id', 1)
            ->where('status', 'draft');

        $this->assertSame($expected, $board->searchQuery($query)->orderBy('id')->pluck('id')->all());
    }
}
