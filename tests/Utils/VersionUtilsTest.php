<?php

declare(strict_types=1);

namespace FlagKit\Tests\Utils;

use FlagKit\Utils\VersionUtils;
use PHPUnit\Framework\TestCase;

class VersionUtilsTest extends TestCase
{
    // parseVersion tests

    public function testParseVersionValidSemver(): void
    {
        $result = VersionUtils::parseVersion('1.2.3');
        $this->assertNotNull($result);
        $this->assertEquals(1, $result['major']);
        $this->assertEquals(2, $result['minor']);
        $this->assertEquals(3, $result['patch']);
    }

    public function testParseVersionZeroVersion(): void
    {
        $result = VersionUtils::parseVersion('0.0.0');
        $this->assertNotNull($result);
        $this->assertEquals(0, $result['major']);
    }

    public function testParseVersionLowercaseVPrefix(): void
    {
        $result = VersionUtils::parseVersion('v1.2.3');
        $this->assertNotNull($result);
        $this->assertEquals(1, $result['major']);
    }

    public function testParseVersionUppercaseVPrefix(): void
    {
        $result = VersionUtils::parseVersion('V1.2.3');
        $this->assertNotNull($result);
        $this->assertEquals(1, $result['major']);
    }

    public function testParseVersionPrereleaseSuffix(): void
    {
        $result = VersionUtils::parseVersion('1.2.3-beta.1');
        $this->assertNotNull($result);
        $this->assertEquals(1, $result['major']);
        $this->assertEquals(2, $result['minor']);
        $this->assertEquals(3, $result['patch']);
    }

    public function testParseVersionBuildMetadata(): void
    {
        $result = VersionUtils::parseVersion('1.2.3+build.123');
        $this->assertNotNull($result);
        $this->assertEquals(3, $result['patch']);
    }

    public function testParseVersionLeadingWhitespace(): void
    {
        $result = VersionUtils::parseVersion('  1.2.3');
        $this->assertNotNull($result);
        $this->assertEquals(1, $result['major']);
    }

    public function testParseVersionTrailingWhitespace(): void
    {
        $result = VersionUtils::parseVersion('1.2.3  ');
        $this->assertNotNull($result);
        $this->assertEquals(1, $result['major']);
    }

    public function testParseVersionSurroundingWhitespace(): void
    {
        $result = VersionUtils::parseVersion('  1.2.3  ');
        $this->assertNotNull($result);
        $this->assertEquals(1, $result['major']);
    }

    public function testParseVersionVPrefixWithWhitespace(): void
    {
        $result = VersionUtils::parseVersion('  v1.0.0  ');
        $this->assertNotNull($result);
        $this->assertEquals(1, $result['major']);
    }

    public function testParseVersionEmptyString(): void
    {
        $this->assertNull(VersionUtils::parseVersion(''));
    }

    public function testParseVersionWhitespaceOnly(): void
    {
        $this->assertNull(VersionUtils::parseVersion('   '));
    }

    public function testParseVersionInvalid(): void
    {
        $this->assertNull(VersionUtils::parseVersion('invalid'));
    }

    public function testParseVersionPartial(): void
    {
        $this->assertNull(VersionUtils::parseVersion('1.2'));
    }

    public function testParseVersionNonNumeric(): void
    {
        $this->assertNull(VersionUtils::parseVersion('a.b.c'));
    }

    public function testParseVersionExceedsMax(): void
    {
        $this->assertNull(VersionUtils::parseVersion('1000000000.0.0'));
    }

    public function testParseVersionAtMaxBoundary(): void
    {
        $result = VersionUtils::parseVersion('999999999.999999999.999999999');
        $this->assertNotNull($result);
        $this->assertEquals(999999999, $result['major']);
    }

    // compareVersions tests

    public function testCompareVersionsEqual(): void
    {
        $this->assertEquals(0, VersionUtils::compareVersions('1.0.0', '1.0.0'));
    }

    public function testCompareVersionsEqualWithVPrefix(): void
    {
        $this->assertEquals(0, VersionUtils::compareVersions('v1.0.0', '1.0.0'));
    }

    public function testCompareVersionsALessThanBMajor(): void
    {
        $this->assertLessThan(0, VersionUtils::compareVersions('1.0.0', '2.0.0'));
    }

    public function testCompareVersionsALessThanBMinor(): void
    {
        $this->assertLessThan(0, VersionUtils::compareVersions('1.0.0', '1.1.0'));
    }

    public function testCompareVersionsALessThanBPatch(): void
    {
        $this->assertLessThan(0, VersionUtils::compareVersions('1.0.0', '1.0.1'));
    }

    public function testCompareVersionsAGreaterThanB(): void
    {
        $this->assertGreaterThan(0, VersionUtils::compareVersions('2.0.0', '1.0.0'));
    }

    public function testCompareVersionsInvalidReturnsZero(): void
    {
        $this->assertEquals(0, VersionUtils::compareVersions('invalid', '1.0.0'));
        $this->assertEquals(0, VersionUtils::compareVersions('1.0.0', 'invalid'));
    }

    // isVersionLessThan tests

    public function testIsVersionLessThanTrue(): void
    {
        $this->assertTrue(VersionUtils::isVersionLessThan('1.0.0', '1.0.1'));
        $this->assertTrue(VersionUtils::isVersionLessThan('1.0.0', '1.1.0'));
        $this->assertTrue(VersionUtils::isVersionLessThan('1.0.0', '2.0.0'));
    }

    public function testIsVersionLessThanFalse(): void
    {
        $this->assertFalse(VersionUtils::isVersionLessThan('1.0.0', '1.0.0'));
        $this->assertFalse(VersionUtils::isVersionLessThan('1.1.0', '1.0.0'));
    }

    public function testIsVersionLessThanInvalid(): void
    {
        $this->assertFalse(VersionUtils::isVersionLessThan('invalid', '1.0.0'));
    }

    // isVersionAtLeast tests

    public function testIsVersionAtLeastTrue(): void
    {
        $this->assertTrue(VersionUtils::isVersionAtLeast('1.0.0', '1.0.0'));
        $this->assertTrue(VersionUtils::isVersionAtLeast('1.1.0', '1.0.0'));
        $this->assertTrue(VersionUtils::isVersionAtLeast('2.0.0', '1.0.0'));
    }

    public function testIsVersionAtLeastFalse(): void
    {
        $this->assertFalse(VersionUtils::isVersionAtLeast('1.0.0', '1.0.1'));
    }

    // SDK scenarios

    public function testSDKBelowMinimum(): void
    {
        $sdkVersion = '1.0.0';
        $minVersion = '1.1.0';
        $this->assertTrue(VersionUtils::isVersionLessThan($sdkVersion, $minVersion));
    }

    public function testSDKAtMinimum(): void
    {
        $sdkVersion = '1.1.0';
        $minVersion = '1.1.0';
        $this->assertFalse(VersionUtils::isVersionLessThan($sdkVersion, $minVersion));
    }

    public function testServerVPrefixedResponse(): void
    {
        $sdkVersion = '1.0.0';
        $serverMin = 'v1.1.0';
        $this->assertTrue(VersionUtils::isVersionLessThan($sdkVersion, $serverMin));
    }
}
