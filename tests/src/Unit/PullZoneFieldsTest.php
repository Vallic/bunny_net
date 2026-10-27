<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Unit;

use Drupal\bunny_net\PullZone\PullZoneFields;
use Drupal\bunny_net\PullZone\PullZoneSettings;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Valicm\BunnyToolkit\Api\Core\Enum\PullZoneLogFormat;

/**
 * Pull zone settings are checked against the toolkit's update model.
 */
#[Group('bunny_net')]
#[CoversClass(PullZoneFields::class)]
#[CoversClass(PullZoneSettings::class)]
final class PullZoneFieldsTest extends UnitTestCase {

  /**
   * The fields come from the toolkit, with their types and enums.
   */
  public function testFields(): void {
    $fields = PullZoneFields::all();

    $this->assertGreaterThan(100, count($fields));
    $this->assertSame(['type' => 'bool', 'enum' => NULL], $fields['EnableCookieVary']);
    $this->assertSame(['type' => 'list', 'enum' => NULL], $fields['CookieVaryParameters']);
    $this->assertSame(['type' => 'int', 'enum' => NULL], $fields['OriginRetries']);
    $this->assertSame(['type' => 'string', 'enum' => NULL], $fields['OriginUrl']);
    $this->assertSame(['type' => 'enum', 'enum' => PullZoneLogFormat::class], $fields['LogFormat']);
  }

  /**
   * Valid values pass, enum names become API values.
   */
  public function testToApi(): void {
    $this->assertTrue(PullZoneSettings::toApi('EnableCookieVary', TRUE));
    $this->assertSame(['my_role'], PullZoneSettings::toApi('CookieVaryParameters', ['my_role']));
    $this->assertSame(PullZoneLogFormat::Json->value, PullZoneSettings::toApi('LogFormat', 'Json'));
    $this->assertSame(99, PullZoneSettings::toApi('LogFormat', 99));
    $this->assertSame('Json', PullZoneSettings::fromApi('LogFormat', PullZoneLogFormat::Json->value));
    $this->assertSame(99, PullZoneSettings::fromApi('LogFormat', 99));
  }

  /**
   * Invalid settings are refused with what is wrong.
   */
  #[DataProvider('invalidProvider')]
  public function testInvalid(string $key, mixed $value, string $message): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($message);
    PullZoneSettings::toApi($key, $value);
  }

  /**
   * Invalid settings and their messages.
   *
   * @return array<string, array{string, mixed, string}>
   *   Key, value and message.
   */
  public static function invalidProvider(): array {
    return [
      'unknown' => ['EnableCookieVaryy', TRUE, 'EnableCookieVaryy is not a pull zone setting. Did you mean EnableCookieVary?'],
      'bool' => ['EnableCookieVary', 'yes', 'EnableCookieVary must be true or false.'],
      'int' => ['OriginRetries', '1', 'OriginRetries must be a whole number.'],
      'list' => ['CookieVaryParameters', 'my_role', 'CookieVaryParameters must be a list.'],
      'enum' => ['LogFormat', 'Yaml', 'LogFormat must be one of'],
      'credential' => ['AWSSigningSecret', 'x', 'AWSSigningSecret holds a credential'],
    ];
  }

}
