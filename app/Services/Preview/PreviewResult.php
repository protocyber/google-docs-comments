<?php

namespace App\Services\Preview;

final class PreviewResult
{
    /**
     * @param  list<string>  $fonts
     * @param  list<array>  $threads  CommentIndex::threads()
     */
    public function __construct(
        public readonly string $html,
        public readonly array $fonts,
        public readonly array $threads,
        public readonly string $title,
        public readonly ?string $revisionId,
        public readonly int $tabCount,
        public readonly ?string $commentsError,
        public readonly bool $canEdit = false,
        public readonly bool $canComment = false,
    ) {}

    /**
     * One request per family: a combined request fails entirely when one
     * family is unknown to Google Fonts.
     *
     * @return list<string>
     */
    public function fontUrls(): array
    {
        return array_map(
            fn (string $f) => 'https://fonts.googleapis.com/css2?family='.str_replace(' ', '+', $f).'&display=swap',
            $this->fonts,
        );
    }
}
