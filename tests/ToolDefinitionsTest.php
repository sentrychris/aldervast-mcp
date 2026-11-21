<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks the definition of every tool in src/Tools.php: the name, description and input schema
 * that the model gets.
 *
 * A mistake in a definition can make a client reject the tool, or make the model call it with the
 * wrong input. These rules apply to every tool, so a new tool is checked as soon as it is added to
 * Tools::ALL.
 */
final class ToolDefinitionsTest extends TestCase
{
    /** One case per tool, named after the tool's class, so that a failure names the tool. */
    public static function tools(): array
    {
        $cases = [];
        foreach (Tools::ALL as $tool) {
            $cases[$tool] = [$tool];
        }

        return $cases;
    }

    #[DataProvider('tools')]
    public function test_definition_name_matches_the_tool_name(string $tool): void
    {
        $this->assertSame($tool::NAME, $tool::definition()['name']);
    }

    #[DataProvider('tools')]
    public function test_definition_has_a_description_for_the_model(string $tool): void
    {
        // The model reads the description to decide when to use the tool, so it can't be short.
        $this->assertGreaterThan(40, mb_strlen($tool::definition()['description']));
    }

    #[DataProvider('tools')]
    public function test_input_schema_is_a_json_object(string $tool): void
    {
        $schema = $tool::definition()['input_schema'];

        $this->assertSame('object', $schema['type']);
        // "properties" must be a JSON object ({}), even when the tool takes no input.
        $this->assertStringStartsWith('{', json_encode($schema['properties']));
    }

    #[DataProvider('tools')]
    public function test_every_input_has_a_type_and_a_description(string $tool): void
    {
        $properties = (array) $tool::definition()['input_schema']['properties'];
        // A tool with no inputs has nothing to check below, so first check the list itself.
        $this->assertIsArray($properties);

        foreach ($properties as $input => $spec) {
            $this->assertArrayHasKey('type', $spec, "input \"{$input}\" has no type");
            $this->assertNotEmpty($spec['description'] ?? '', "input \"{$input}\" has no description");
        }
    }

    #[DataProvider('tools')]
    public function test_every_required_input_is_defined(string $tool): void
    {
        $schema = $tool::definition()['input_schema'];
        $defined = array_keys((array) $schema['properties']);
        // JSON Schema needs "required" to be a list of input names. Many tools have none.
        $this->assertIsList($schema['required'] ?? []);

        foreach ($schema['required'] ?? [] as $required) {
            $this->assertContains($required, $defined, "\"{$required}\" is required but not defined");
        }
    }
}
