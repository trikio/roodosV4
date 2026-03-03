<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ManticoreSearchService;
use Exception;

class TestManticoreConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manticore:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test ManticoreSearch connection and list indices';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing connection to ManticoreSearch at ' . env('MANTICORE_HOST') . ':' . env('MANTICORE_PORT'));

        try {
            $manticore = new ManticoreSearchService();
            $this->info('✓ Connection successful!');

            $this->info('');
            $this->info('Listing indices...');

            $indices = $manticore->listIndices();
            $indicesArray = iterator_to_array($indices);

            if (empty($indicesArray)) {
                $this->warn('No indices found.');
            } else {
                $this->info('');
                $this->table(
                    ['Index', 'Type'],
                    array_map(function($index) {
                        return [
                            $index['Table'] ?? $index['Index'] ?? 'Unknown',
                            $index['Type'] ?? 'Unknown'
                        ];
                    }, $indicesArray)
                );
            }

            return 0;
        } catch (Exception $e) {
            $this->error('✗ Connection failed: ' . $e->getMessage());
            return 1;
        }
    }
}
