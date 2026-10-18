<?php

namespace Tests\Unit;

use App\Services\ManticoreSearchService;
use Foolz\SphinxQL\Drivers\Mysqli\Connection;
use Foolz\SphinxQL\SphinxQL;
use Tests\TestCase;

class ManticoreQuorumMatchTest extends TestCase
{
    private function compileMatch(string $searchQuery): string
    {
        // The default escape() needs a live mysqli link; identity is enough to inspect the MATCH expression.
        $connection = new class extends Connection {
            public function escape(string $value): string
            {
                return $value;
            }
        };

        $service = new class extends ManticoreSearchService {
            public function quorum(SphinxQL $query, array $columns, ?string $searchQuery): SphinxQL
            {
                return $this->applyQuorumMatch($query, $columns, $searchQuery);
            }
        };

        $query = (new SphinxQL($connection))->select('*')->from('car_cl');
        $service->quorum($query, ['title', 'details'], $searchQuery);

        return $query->compile()->getCompiled();
    }

    public function test_plain_query_uses_quorum_operator(): void
    {
        $sql = $this->compileMatch('Toyota Corolla');

        $this->assertStringContainsString('(@(title,details) "toyota corolla"/1)', $sql);
    }

    public function test_quotes_and_operators_are_escaped(): void
    {
        $sql = $this->compileMatch('toyota "corolla @details -x');

        $this->assertStringContainsString('"toyota \"corolla \@details \-x"/1', $sql);
    }

    public function test_empty_query_adds_no_match(): void
    {
        $this->assertStringNotContainsString('MATCH', $this->compileMatch('   '));
    }
}
