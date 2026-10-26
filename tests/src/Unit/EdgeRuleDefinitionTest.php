<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Unit;

use Drupal\bunny_net\EdgeRule\EdgeRuleDefinition;
use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Rule files convert to API bodies and back without losing anything.
 */
#[Group('bunny_net')]
#[CoversClass(EdgeRuleDefinition::class)]
final class EdgeRuleDefinitionTest extends UnitTestCase {

  /**
   * Names become numbers, defaults are filled in, the name is marked.
   */
  public function testToApi(): void {
    $definition = EdgeRuleDefinition::fromYaml($this->fixture('no-index-staging'), 'no-index-staging.yml');

    $this->assertSame([
      'ActionType' => 5,
      'ActionParameter1' => 'X-Robots-Tag',
      'ActionParameter2' => 'noindex',
      'ActionParameter3' => '',
      'TriggerMatchingType' => 1,
      'Triggers' => [
        ['Type' => 1, 'PatternMatchingType' => 0, 'PatternMatches' => ['staging.*'], 'Parameter1' => 'Host'],
      ],
      'ExtraActions' => [
        ['ActionType' => 3, 'ActionParameter1' => '0', 'ActionParameter2' => '', 'ActionParameter3' => ''],
      ],
      'Description' => 'Keep staging out of search engines [bunny_net:no-index-staging]',
      'Enabled' => TRUE,
    ], EdgeRuleDefinition::toApi($definition, 'no-index-staging'));
    $this->assertSame('abc', EdgeRuleDefinition::toApi($definition, 'x', 'abc')['Guid']);
  }

  /**
   * What bunny.net returns reads back as the file, nulls and all.
   */
  public function testRoundTrip(): void {
    $definition = EdgeRuleDefinition::fromYaml($this->fixture('no-index-staging'), 'no-index-staging.yml');
    $returned = EdgeRuleDefinition::toApi($definition, 'no-index-staging', 'abc');
    // bunny.net answers with nulls for empty parameters, and extra fields.
    $returned['ActionParameter3'] = NULL;
    $returned['ExtraActions'][0]['ActionParameter2'] = NULL;
    $returned['OrderIndex'] = 3;
    $returned['ReadOnly'] = FALSE;

    $this->assertSame($definition, EdgeRuleDefinition::fromApi($returned));
    $this->assertSame('no-index-staging', EdgeRuleDefinition::managedName($returned['Description']));
    $this->assertSame($definition, EdgeRuleDefinition::fromYaml(EdgeRuleDefinition::toYaml($definition), 'export'));
  }

  /**
   * Exported files leave out what is the default.
   */
  public function testToYamlIsShort(): void {
    $definition = EdgeRuleDefinition::fromYaml($this->fixture('block-probes'), 'block-probes.yml');

    $this->assertSame([
      'description' => 'Block WordPress probes',
      'action' => 'BlockRequest',
      'triggers' => [['type' => 'Url', 'patterns' => ['*wp-login.php', '*xmlrpc.php']]],
    ], EdgeRuleDefinition::toYaml($definition));
  }

  /**
   * Values bunny.net added after the toolkit was generated pass as numbers.
   */
  public function testUnknownValuesStayNumbers(): void {
    $definition = EdgeRuleDefinition::fromApi([
      'ActionType' => 999,
      'Triggers' => [['Type' => 0]],
      'Description' => 'New',
    ]);

    $this->assertSame(999, $definition['action']);
    $this->assertSame(999, EdgeRuleDefinition::toApi($definition, 'new')['ActionType']);
    $file = ['action' => 999, 'triggers' => [['type' => 'Url']]];
    $this->assertSame(999, EdgeRuleDefinition::fromYaml($file, 'f')['action']);
  }

  /**
   * Invalid files fail with the file and the key in the message.
   *
   * @param array<string, mixed> $data
   *   The file's contents.
   * @param string $message
   *   The start of the error message.
   */
  #[DataProvider('invalidFiles')]
  public function testInvalidFiles(array $data, string $message): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('rule.yml: ' . $message);
    EdgeRuleDefinition::fromYaml($data, 'rule.yml');
  }

  /**
   * Invalid rule files.
   *
   * @return array<string, array{array<string, mixed>, string}>
   *   The data and the expected message.
   */
  public static function invalidFiles(): array {
    $trigger = [['type' => 'Url', 'patterns' => ['*']]];
    $block = ['action' => 'BlockRequest', 'triggers' => $trigger];
    return [
      'no action' => [['triggers' => $trigger], 'action is required'],
      'no triggers' => [['action' => 'BlockRequest'], 'at least one trigger is required'],
      'unknown action' => [['action' => 'Block', 'triggers' => $trigger], 'action must be one of'],
      'unknown trigger type' => [['triggers' => [['type' => 'Path']]] + $block, 'triggers[0].type must be one of'],
      'typo in a key' => [['action' => 'BlockRequest', 'trigers' => $trigger], 'unknown key(s) trigers'],
      'too many parameters' => [['parameters' => ['a', 'b', 'c', 'd']] + $block, 'parameters takes at most 3 values'],
      'enabled as text' => [['enabled' => 'yes'] + $block, 'enabled must be true or false'],
    ];
  }

  /**
   * A fixture rule file.
   */
  private function fixture(string $name): mixed {
    return Yaml::decode((string) file_get_contents(__DIR__ . '/../../fixtures/rules/' . $name . '.yml'));
  }

}
