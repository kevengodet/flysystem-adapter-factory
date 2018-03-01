<?php

namespace Keven\Flysystem\Exception;

final class PackageRequired extends \UnexpectedValueException
{
    static public function fromAdapterAndPackageNames(string $adapterName, string $packageName): PackageRequired
    {
        return new self("Adapter '$adapterName' requires the package '$packageName' to be installed.");
    }
}
