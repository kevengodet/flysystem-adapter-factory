<?php

namespace Keven\Flysystem\Exception;

final class InvalidUri extends \UnexpectedValueException
{
    static public function fromUri(string $uri): InvalidUri
    {
        return new self("URI '$uri' is invalid and cannot be interpreted as a Flysystem adapter.");
    }
}
