<?php

declare(strict_types=1);

namespace FlagKit\Tests;

use FlagKit\Core\ContextBuilder;
use FlagKit\Core\ContextManager;
use FlagKit\Types\EvaluationContext;
use PHPUnit\Framework\TestCase;

class ContextManagerTest extends TestCase
{
    public function testSetContextStoresContext(): void
    {
        $manager = new ContextManager();
        $context = new EvaluationContext('user-123');

        $manager->setContext($context);

        $this->assertNotNull($manager->getContext());
        $this->assertEquals('user-123', $manager->getContext()->userId);
    }

    public function testClearContextRemovesContext(): void
    {
        $manager = new ContextManager();
        $manager->setContext(new EvaluationContext('user-123'));

        $manager->clearContext();

        $this->assertNull($manager->getContext());
    }

    public function testIdentifySetsUserIdAndAttributes(): void
    {
        $manager = new ContextManager();

        $manager->identify('user-123', ['email' => 'user@example.com']);

        $this->assertEquals('user-123', $manager->getUserId());
        $this->assertTrue($manager->isIdentified());
        $this->assertFalse($manager->isAnonymous());
    }

    public function testResetSetsAnonymousState(): void
    {
        $manager = new ContextManager();
        $manager->identify('user-123');

        $manager->reset();

        $this->assertTrue($manager->isAnonymous());
        $this->assertNull($manager->getUserId());
    }

    public function testResolveContextMergesGlobalAndLocal(): void
    {
        $manager = new ContextManager();
        $manager->setContext(new EvaluationContext('user-123', ['country' => \FlagKit\Types\FlagValue::from('US')]));

        $local = new EvaluationContext(null, ['plan' => \FlagKit\Types\FlagValue::from('premium')]);
        $resolved = $manager->resolveContext($local);

        $this->assertNotNull($resolved);
        $this->assertEquals('user-123', $resolved->userId);
        $this->assertEquals('US', $resolved->get('country')?->getString());
        $this->assertEquals('premium', $resolved->get('plan')?->getString());
    }

    public function testResolveContextStripsPrivateAttributes(): void
    {
        $manager = new ContextManager();
        $manager->setContext(new EvaluationContext('user-123', [
            'email' => \FlagKit\Types\FlagValue::from('user@example.com'),
            '_secret' => \FlagKit\Types\FlagValue::from('hidden'),
        ]));

        $resolved = $manager->resolveContext();

        $this->assertNull($resolved->get('_secret'));
        $this->assertNotNull($resolved->get('email'));
    }

    public function testGetMergedContextPreservesPrivateAttributes(): void
    {
        $manager = new ContextManager();
        $manager->setContext(new EvaluationContext('user-123', [
            '_secret' => \FlagKit\Types\FlagValue::from('hidden'),
        ]));

        $merged = $manager->getMergedContext();

        $this->assertNotNull($merged->get('_secret'));
    }

    public function testIsIdentifiedRequiresUserIdAndNotAnonymous(): void
    {
        $manager = new ContextManager();

        $this->assertFalse($manager->isIdentified());

        $manager->setContext(new EvaluationContext('user-123'));
        $this->assertTrue($manager->isIdentified());

        // With anonymous = true
        $manager->setContext(new EvaluationContext('user-123', [
            'anonymous' => \FlagKit\Types\FlagValue::from(true),
        ]));
        $this->assertFalse($manager->isIdentified());
    }

    public function testGetAttributeReturnsValue(): void
    {
        $manager = new ContextManager();
        $manager->setContext(new EvaluationContext('user-123', [
            'email' => \FlagKit\Types\FlagValue::from('user@example.com'),
        ]));

        $this->assertEquals('user@example.com', $manager->getAttribute('email')?->getString());
        $this->assertNull($manager->getAttribute('nonexistent'));
    }

    public function testSetAttributeAddsToContext(): void
    {
        $manager = new ContextManager();
        $manager->setContext(new EvaluationContext('user-123'));

        $manager->setAttribute('email', 'user@example.com');

        $this->assertEquals('user@example.com', $manager->getAttribute('email')?->getString());
    }

    public function testSetAttributesAddMultiple(): void
    {
        $manager = new ContextManager();
        $manager->setContext(new EvaluationContext('user-123'));

        $manager->setAttributes([
            'email' => 'user@example.com',
            'plan' => 'premium',
        ]);

        $this->assertEquals('user@example.com', $manager->getAttribute('email')?->getString());
        $this->assertEquals('premium', $manager->getAttribute('plan')?->getString());
    }

    public function testHasAttributeChecksExistence(): void
    {
        $manager = new ContextManager();
        $manager->setContext(new EvaluationContext('user-123', [
            'email' => \FlagKit\Types\FlagValue::from('user@example.com'),
        ]));

        $this->assertTrue($manager->hasAttribute('email'));
        $this->assertFalse($manager->hasAttribute('phone'));
    }

    public function testGetStatsReturnsCorrectData(): void
    {
        $manager = new ContextManager();

        $stats = $manager->getStats();
        $this->assertFalse($stats['hasContext']);
        $this->assertTrue($stats['isAnonymous']);

        $manager->identify('user-123');
        $stats = $manager->getStats();

        $this->assertTrue($stats['hasContext']);
        $this->assertTrue($stats['isIdentified']);
        $this->assertFalse($stats['isAnonymous']);
        $this->assertEquals('user-123', $stats['userId']);
    }

    public function testContextBuilderBuildsContext(): void
    {
        $context = ContextManager::builder()
            ->userId('user-123')
            ->email('user@example.com')
            ->name('John Doe')
            ->country('US')
            ->custom('plan', 'premium')
            ->privateAttribute('email')
            ->build();

        $this->assertEquals('user-123', $context->userId);
        $this->assertEquals('user@example.com', $context->get('email')?->getString());
        $this->assertEquals('John Doe', $context->get('name')?->getString());
        $this->assertEquals('US', $context->get('country')?->getString());
    }

    public function testContextBuilderApplyToManager(): void
    {
        $manager = new ContextManager();

        ContextManager::builder()
            ->userId('user-123')
            ->email('user@example.com')
            ->applyTo($manager);

        $this->assertEquals('user-123', $manager->getUserId());
        $this->assertEquals('user@example.com', $manager->getAttribute('email')?->getString());
    }

    public function testInitialContextInConstructor(): void
    {
        $initialContext = new EvaluationContext('user-123');
        $manager = new ContextManager($initialContext);

        $this->assertNotNull($manager->getContext());
        $this->assertEquals('user-123', $manager->getUserId());
    }
}
