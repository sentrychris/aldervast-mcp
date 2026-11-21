<?php

declare(strict_types=1);

namespace Aldervast\Tools;

/** JSON formatting shared by the tools and the scripts that print their output. */
final class Json
{
    /** Formats any value as indented JSON, with slashes and accented letters left as they are, so that it's easy to read. */
    public static function pretty(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
