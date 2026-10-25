<?php

namespace App\Analytics\Tally;

/**
 * Visits an AI answer probably sent, never certain: a direct visit (no
 * referrer, no campaign tag) that landed on a page an assistant's user
 * fetcher fetched for someone's question up to 15 minutes before. And,
 * for the visits an assistant is known to have sent (a citation), whether
 * its fetcher read pages first, and the very page they landed on.
 *
 * Requests come in time order.
 */
final class LikelyFromAi
{
    public const int WINDOW = 900;

    /**
     * The user fetchers by the assistant whose answers they serve.
     *
     * @var array<string, string>
     */
    public const array FETCHERS = [
        '~^ChatGPT-User~i' => 'chatgpt.com',
        '~^Claude-(?:User|Web)~i' => 'claude.ai',
        '~^Perplexity-User~i' => 'perplexity.ai',
        '~^(?:Gemini-User|Google-Agent|GoogleAgent-Mariner|Google-GeminiNotebook|Google-NotebookLM)~i' => 'gemini.google.com',
        '~^MistralAI-User~i' => 'mistral.ai',
        '~^BingPreview~i' => 'copilot.microsoft.com',
        '~^meta-externalfetcher~i' => 'meta.ai',
        '~^Amzn-User~i' => 'amazon.com',
    ];

    /**
     * The recent fetches of each path: [time, assistant, followed].
     *
     * @var array<string, list<array{0: int, 1: string, 2: bool}>>
     */
    private array $fetches = [];

    /**
     * When each assistant last fetched anything.
     *
     * @var array<string, int>
     */
    private array $lastFetch = [];

    public static function assistantOf(string $fetcher): string
    {
        foreach (self::FETCHERS as $pattern => $assistant) {
            if (preg_match($pattern, $fetcher) === 1) {
                return $assistant;
            }
        }

        return $fetcher;
    }

    /**
     * A user fetcher's request, for someone's question.
     */
    public function fetched(string $path, string $fetcher, int $time): void
    {
        $assistant = self::assistantOf($fetcher);
        $this->fetches[$path] = array_values(array_filter($this->fetches[$path] ?? [], fn (array $fetch) => $fetch[0] >= $time - self::WINDOW));
        $this->fetches[$path][] = [$time, $assistant, false];
        $this->lastFetch[$assistant] = $time;
    }

    /**
     * A direct visit's first page: the assistant that probably sent it, and
     * whether its fetch was followed by a visit for the first time.
     *
     * @return ?array{0: string, 1: bool}
     */
    public function direct(string $path, int $time): ?array
    {
        $fetches = $this->fetches[$path] ?? [];

        for ($index = count($fetches) - 1; $index >= 0; $index--) {
            if ($fetches[$index][0] >= $time - self::WINDOW && $fetches[$index][0] <= $time) {
                $first = ! $fetches[$index][2];
                $this->fetches[$path][$index][2] = true;

                return [$fetches[$index][1], $first];
            }
        }

        return null;
    }

    /**
     * A visit an assistant sent, by its first page: whether its fetcher
     * read anything in the window before, and whether it read this page.
     *
     * @return array{0: bool, 1: bool}
     */
    public function cited(string $assistant, string $path, int $time): array
    {
        $readFirst = ($this->lastFetch[$assistant] ?? PHP_INT_MIN) >= $time - self::WINDOW;
        $readPage = array_filter($this->fetches[$path] ?? [], fn (array $fetch) => $fetch[1] === $assistant
            && $fetch[0] >= $time - self::WINDOW && $fetch[0] <= $time) !== [];

        return [$readFirst, $readPage];
    }
}
