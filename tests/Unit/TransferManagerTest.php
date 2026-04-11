<?php

declare(strict_types=1);

namespace HyperFields\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HyperFields\Transfer\Manager;

class TransferManagerTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('sanitize_key')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testModuleExportImportDiffFlow(): void
    {
        $manager = new Manager();

        $manager->registerModule(
            'options',
            exporter: static fn (array $context): array => ['value' => $context['value'] ?? 'x'],
            importer: static fn (array $payload, array $context): array => ['imported' => $payload['value'] ?? null, 'ctx' => $context['ctx'] ?? null],
            differ: static fn (array $payload, array $context): array => ['changed' => ($payload['value'] ?? null) !== ($context['expected'] ?? null)]
        );

        $bundle = $manager->export([], ['value' => 'abc']);
        $this->assertSame('hyperfields_transfer_bundle', $bundle['type']);
        $this->assertSame('abc', $bundle['modules']['options']['value']);

        $diff = $manager->diff($bundle, ['expected' => 'def']);
        $this->assertTrue($diff['success']);
        $this->assertTrue($diff['modules']['options']['changed']);

        $import = $manager->import($bundle, ['ctx' => 'ok']);
        $this->assertTrue($import['success']);
        $this->assertSame('abc', $import['modules']['options']['imported']);
        $this->assertSame('ok', $import['modules']['options']['ctx']);
    }
}
