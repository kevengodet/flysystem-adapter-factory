<?php

namespace Keven\Flysystem\Exception;

final class AdapterNotSupported extends \UnexpectedValueException
{
    static public function fromName(string $adapterName): AdapterNotSupported
    {
        return new self("Adapter '$adapterName' is not supported.");
    }
}
