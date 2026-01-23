<?php

declare(strict_types=1);

namespace FlagKit\Tests;

use FlagKit\EvaluationContext;
use PHPUnit\Framework\TestCase;

class EvaluationContextTest extends TestCase
{
    public function testWithUserIdSetsUserId(): void
    {
        $context = (new EvaluationContext())->withUserId('user-123');

        $this->assertEquals('user-123', $context->userId);
    }

    public function testWithAttributeAddsAttribute(): void
    {
        $context = (new EvaluationContext())->withAttribute('plan', 'premium');

        $this->assertEquals('premium', $context->get('plan')?->getString());
    }

    public function testWithAttributesAddsMultipleAttributes(): void
    {
        $context = (new EvaluationContext())->withAttributes([
            'plan' => 'premium',
            'beta' => true,
        ]);

        $this->assertEquals('premium', $context->get('plan')?->getString());
        $this->assertTrue($context->get('beta')?->getBool());
    }

    public function testMergeCombinesContexts(): void
    {
        $base = (new EvaluationContext())
            ->withUserId('user-1')
            ->withAttribute('plan', 'free');

        $override = (new EvaluationContext())
            ->withUserId('user-2')
            ->withAttribute('beta', true);

        $merged = $base->merge($override);

        $this->assertEquals('user-2', $merged->userId);
        $this->assertEquals('free', $merged->get('plan')?->getString());
        $this->assertTrue($merged->get('beta')?->getBool());
    }

    public function testMergeWithNullReturnsOriginal(): void
    {
        $context = (new EvaluationContext())->withUserId('user-1');

        $merged = $context->merge(null);

        $this->assertSame($context, $merged);
    }

    public function testStripPrivateAttributesRemovesPrivateAttributes(): void
    {
        $context = (new EvaluationContext())
            ->withAttribute('_privateKey', 'secret')
            ->withAttribute('publicKey', 'visible');

        $stripped = $context->stripPrivateAttributes();

        $this->assertNull($stripped->get('_privateKey'));
        $this->assertEquals('visible', $stripped->get('publicKey')?->getString());
    }

    public function testIsEmptyReturnsTrueForEmptyContext(): void
    {
        $context = new EvaluationContext();

        $this->assertTrue($context->isEmpty());
    }

    public function testIsEmptyReturnsFalseWithUserId(): void
    {
        $context = (new EvaluationContext())->withUserId('user-1');

        $this->assertFalse($context->isEmpty());
    }

    public function testToArrayIncludesUserIdAndAttributes(): void
    {
        $context = (new EvaluationContext())
            ->withUserId('user-123')
            ->withAttribute('plan', 'premium');

        $array = $context->toArray();

        $this->assertEquals('user-123', $array['userId']);
        $this->assertArrayHasKey('attributes', $array);
    }

    public function testBuilderCreatesContextWithAllTypes(): void
    {
        $context = EvaluationContext::builder()
            ->userId('user-123')
            ->attribute('active', true)
            ->attribute('name', 'Test')
            ->attribute('age', 25)
            ->attribute('score', 99.5)
            ->build();

        $this->assertEquals('user-123', $context->userId);
        $this->assertTrue($context->get('active')?->getBool());
        $this->assertEquals('Test', $context->get('name')?->getString());
        $this->assertEquals(25, $context->get('age')?->getInt());
        $this->assertEquals(99.5, $context->get('score')?->getNumber());
    }
}
