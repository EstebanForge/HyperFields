<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit\Compatibility;

use HyperFields\Compatibility\CompatibilityMigrator;
use HyperFields\Compatibility\Store\StoreInterface;

class CompatibilityMigratorTest extends \PHPUnit\Framework\TestCase
{
    public function testDryRunReportsChangesAndMissingKeys(): void
    {
        $source = new InMemoryStore([
            'old_one' => 'value-1',
        ]);
        $target = new InMemoryStore([
            'new_one' => 'existing',
        ]);

        $result = CompatibilityMigrator::dryRun($source, $target, [
            'old_one' => 'new_one',
            'old_two' => 'new_two',
        ]);

        $this->assertCount(1, $result['changes']);
        $this->assertSame('old_one', $result['changes'][0]['from']);
        $this->assertSame('new_one', $result['changes'][0]['to']);
        $this->assertSame('existing', $result['changes'][0]['old']);
        $this->assertSame('value-1', $result['changes'][0]['new']);
        $this->assertSame(['old_two'], $result['missing']);
    }

    public function testMigrateAndRestore(): void
    {
        $source = new InMemoryStore([
            'old_one' => 'value-1',
            'old_two' => 'value-2',
        ]);
        $target = new InMemoryStore([
            'new_one' => 'existing-1',
            'new_two' => 'existing-2',
        ]);

        $migrate = CompatibilityMigrator::migrate($source, $target, [
            'old_one' => 'new_one',
            'old_two' => 'new_two',
        ]);

        $this->assertTrue($migrate['success']);
        $this->assertSame(['new_one', 'new_two'], $migrate['written']);
        $this->assertSame('value-1', $target->get('new_one'));
        $this->assertSame('value-2', $target->get('new_two'));

        $restored = CompatibilityMigrator::restore($target, $migrate['backup']);
        $this->assertTrue($restored);
        $this->assertSame('existing-1', $target->get('new_one'));
        $this->assertSame('existing-2', $target->get('new_two'));
    }
}

final class InMemoryStore implements StoreInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = []) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set(string $key, mixed $value): bool
    {
        $this->data[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        if (!array_key_exists($key, $this->data)) {
            return false;
        }

        unset($this->data[$key]);

        return true;
    }

    public function all(): array
    {
        return $this->data;
    }
}
