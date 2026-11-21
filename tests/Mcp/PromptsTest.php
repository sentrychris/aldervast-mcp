<?php

declare(strict_types=1);

namespace Aldervast\Tests\Mcp;

use Aldervast\Mcp\Prompts;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Prompts, the MCP prompts. They need no wiki pages: a prompt is only text with the
 * arguments filled in. McpServerTest checks the same methods through the server.
 */
final class PromptsTest extends TestCase
{
    /** The text of the prompt's one message. */
    private function text(string $name, array $arguments): string
    {
        $prompt = Prompts::get($name, $arguments);
        $this->assertCount(1, $prompt['messages']);
        $this->assertSame('user', $prompt['messages'][0]['role']);
        $this->assertSame('text', $prompt['messages'][0]['content']['type']);

        return $prompt['messages'][0]['content']['text'];
    }

    public function test_the_list_has_each_prompt_with_its_arguments(): void
    {
        $prompts = Prompts::list();

        $this->assertSame(['plan_build', 'plan_levelling'], array_column($prompts, 'name'));
        $this->assertSame(['class', 'level', 'playstyle'], array_column($prompts[0]['arguments'], 'name'));
        $this->assertSame([true, false, false], array_column($prompts[0]['arguments'], 'required'));
        $this->assertSame([['name' => 'level', 'description' => 'The character\'s level, from 1 to 60.', 'required' => true]], $prompts[1]['arguments']);
        foreach ($prompts as $prompt) {
            $this->assertNotSame('', $prompt['title']);
            $this->assertNotSame('', $prompt['description']);
        }
    }

    public function test_getting_a_prompt_returns_its_description_and_one_message_from_the_user(): void
    {
        $prompt = Prompts::get('plan_levelling', ['level' => '12']);

        $this->assertSame(Prompts::list()[1]['description'], $prompt['description']);
        $this->assertSame(['description', 'messages'], array_keys($prompt));
    }

    public function test_plan_build_with_only_a_class_mentions_no_level_or_playstyle(): void
    {
        $text = $this->text('plan_build', ['class' => '  Warden ']);

        $this->assertStringStartsWith("I play a Warden in Aldervast. Plan a build for me.\n\n", $text);
        $this->assertStringContainsString('which options in each part suit my class. Read the full entries', $text);
        $this->assertStringNotContainsString('level', $text);
        $this->assertStringNotContainsString('How I like to play', $text);
        // The text tells the model to use the tools and not to guess.
        $this->assertStringContainsString('rather than answering from memory', $text);
        $this->assertStringContainsString('cite its wiki URL', $text);
    }

    /**
     * The whole text, word for word. The model reads it, so a change to the wording changes what
     * the model does. This test makes every such change a deliberate one.
     */
    public function test_plan_build_with_a_level_and_playstyle_is_exactly_this_text(): void
    {
        $this->assertSame(
            'I play a Shade in Aldervast at level 25. How I like to play: solo, safe. Plan a build for me.' . "\n\n"
            . 'Look everything up with the aldervast tools rather than answering from memory. Start with my '
            . 'class\'s wiki entry. Then find out from the wiki which parts a build is made of (list_category '
            . 'with no category shows every category) and which options in each part suit my class and level. '
            . 'Read the full entries with get_wiki_entry before you recommend them.' . "\n\n"
            . 'For each choice, say in a sentence why it suits me, and cite its wiki URL. If the wiki doesn\'t '
            . 'say something that the build needs, say so rather than guessing.',
            $this->text('plan_build', ['class' => 'Shade', 'level' => ' 25 ', 'playstyle' => ' solo, safe '])
        );
    }

    public function test_a_blank_playstyle_is_left_out(): void
    {
        $this->assertStringStartsWith(
            'I play a Shade in Aldervast. Plan a build for me.',
            $this->text('plan_build', ['class' => 'Shade', 'level' => '', 'playstyle' => '  '])
        );
    }

    public function test_plan_levelling_is_exactly_this_text_with_the_level_filled_in(): void
    {
        $this->assertSame(
            'My character is level 25 in Aldervast. What should I do next?' . "\n\n"
            . 'Call levelling_route with level 25, and use get_wiki_entry for anything whose details matter to '
            . 'the plan. Then give me a short plan in three parts: quests to do now, dungeons to run, and where '
            . 'to go. Put main story quests first. Cite the wiki URL for each item, and base the plan only on '
            . 'what the tools return.',
            $this->text('plan_levelling', ['level' => '25'])
        );
    }

    public function test_a_level_sent_as_a_json_number_works_too(): void
    {
        $this->assertStringStartsWith('My character is level 7 ', $this->text('plan_levelling', ['level' => 7]));
    }

    public function test_the_lowest_and_highest_levels_are_allowed(): void
    {
        $this->assertStringStartsWith('My character is level 1 ', $this->text('plan_levelling', ['level' => '1']));
        $this->assertStringStartsWith('My character is level 60 ', $this->text('plan_levelling', ['level' => '60']));
    }

    public static function badArguments(): array
    {
        return [
            'unknown prompt' => ['delete_everything', [], 'Unknown prompt: delete_everything'],
            'missing class' => ['plan_build', [], 'Missing argument: class'],
            'blank class' => ['plan_build', ['class' => '   '], 'Missing argument: class'],
            'missing level' => ['plan_levelling', [], 'Missing argument: level'],
            'level 0' => ['plan_levelling', ['level' => '0'], 'level must be a whole number from 1 to 60'],
            'level 61' => ['plan_levelling', ['level' => '61'], 'level must be a whole number from 1 to 60'],
            'level in words' => ['plan_levelling', ['level' => 'ten'], 'level must be a whole number from 1 to 60'],
            'level with a decimal point' => ['plan_levelling', ['level' => '2.5'], 'level must be a whole number from 1 to 60'],
            'bad optional level' => ['plan_build', ['class' => 'Shade', 'level' => '-3'], 'level must be a whole number from 1 to 60'],
        ];
    }

    #[DataProvider('badArguments')]
    public function test_an_unknown_prompt_or_a_bad_argument_is_an_error(string $name, array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Prompts::get($name, $arguments);
    }
}
