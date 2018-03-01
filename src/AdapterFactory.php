<?php

namespace Keven\Flysystem;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Azure\AzureAdapter;
use MicrosoftAzure\Storage\Common\ServicesBuilder;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use Barracuda\Copy\API as CopyAPI;
use League\Flysystem\Copy\CopyAdapter;
use Spatie\Dropbox\Client as DropboxClient;
use Spatie\FlysystemDropbox\DropboxAdapter;
use League\Flysystem\GridFS\GridFSAdapter;
use League\Flysystem\Adapter\Ftp as FtpAdapter;
use League\Flysystem\Memory\MemoryAdapter;
use OpenCloud\OpenStack;
use OpenCloud\Rackspace;
use OpenCloud\ObjectStore\Service as ObjectStoreService;
use League\Flysystem\Rackspace\RackspaceAdapter;
use League\Flysystem\Sftp\SftpAdapter;
use League\Flysystem\Replicate\ReplicateAdapter;
use Sabre\DAV\Client as DAVClient;
use League\Flysystem\WebDAV\WebDAVAdapter;
use League\Flysystem\Phpcr\PhpcrAdapter;
use Jackalope\RepositoryFactoryDoctrineDBAL;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\DriverManager;
use League\Flysystem\ZipArchive\ZipArchiveAdapter;
use League\Flysystem\Adapter\NullAdapter;
use Keven\Flysystem\Exception\AdapterNotSupported;
use Keven\Flysystem\Exception\PackageRequired;
use Keven\Flysystem\Exception\InvalidUri;
use Keven\Instantiator\Instantiator;

final class AdapterFactory
{
    private const HOST_PLACEHOLDER = '__HOST_PLACEHOLDER__';

    /** @var Instantiator */
    private $instantiator;

    public function __construct(Instantiator $instantiator = null)
    {
        $this->instantiator = $instantiator ?: new Instantiator;
    }

    /**
     * @throws InvalidUri
     * @throws AdapterNotSupported
     * @throws PackageRequired
     */
    public function createFromUri(string $uri): AdapterInterface
    {
        if (false !== strpos($uri, ':///')) {
            $uri = str_replace(':///', '://'.self::HOST_PLACEHOLDER.'/', $uri);
        }

        $parts = parse_url($uri);

        if (false === $parts) {
            throw InvalidUri::fromUri($uri);
        }

        if (!isset($parts['scheme'])) {
            throw InvalidUri::fromUri($uri);
        }

        if (isset($parts['query'])) {
            parse_str($parts['query'], $config);
        } else {
            $config = [];
        }

        $config['adapter'] = $parts['scheme'];

        if (isset($parts['host']) && $parts['host'] != self::HOST_PLACEHOLDER) {
            $config['endpoint'] = $parts['host'];
        }

        if (isset($parts['path'])) {
            $config['root'] = $parts['path'];
            $config['prefix'] = $parts['path'];
        }

        return $this->create($config);
    }

    /**
     * @throws AdapterNotSupported
     * @throws PackageRequired
     */
    public function create(array $config): AdapterInterface
    {
        switch ($config['adapter'] ?? 'local') {
            case 'local':
                return $this->instantiator->instantiate(Local::class, array_merge(['root' => '/'], $config));

            case 'azure':
                if (!class_exists(AzureAdapter::class)) {
                    throw PackageRequired::fromAdapterAndPackageNames('azure', 'league/flysystem-azure');
                }

                $endpoint = sprintf(
                    'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s',
                    $config['account-name'] ?? '',
                    $config['api-key'] ?? ''
                );
                $blobRestProxy = ServicesBuilder::getInstance()->createBlobService($endpoint);

                return new AzureAdapter($blobRestProxy, 'my-container');

            case 's3':
                if (!class_exists(AwsS3Adapter::class)) {
                    throw PackageRequired::fromAdapterAndPackageNames('s3', 'league/flysystem-aws-s3-v3');
                }

                $config['client'] = S3Client::factory($config);

                return $this->instantiator->instantiate(AwsS3Adapter::class, $config);

            case 'copy':
                if (!class_exists(CopyAdapter::class)) {
                    throw PackageRequired::fromAdapterAndPackageNames('copy', 'league/flysystem-copy');
                }

                $config['client'] = $this->instantiator->instantiate(CopyAPI::class, $config);

                return $this->instantiator->instantiate(CopyAdapter::class, $config);

            case 'dropbox':
                if (!class_exists(DropboxAdapter::class)) {
                    throw PackageRequired::fromAdapterAndPackageNames('dropbox', 'spatie/flysystem-dropbox');
                }

                $config['client'] = $this->instantiator->instantiate(DropboxClient::class, $config);

                return $this->instantiator->instantiate(DropboxAdapter::class, $config);

            case 'ftp':
                return $this->instantiator->instantiate(FtpAdapter::class, $config);

            case 'gridfs':
                if (!class_exists(GridFSAdapter::class)) {
                    throw PackageRequired::fromAdapterAndPackageNames('gridfs', 'league/flysystem-gridfs');
                }

                $gridFs = (new \MongoClient)->selectDB($config['dbName'] ?? '')->getGridFS();

                return new GridFSAdapter($gridFs);

            case 'memory':
                if (!class_exists(MemoryAdapter::class)) {
                    throw PackageRequired::fromAdapterAndPackageNames('memory', 'league/flysystem-memory');
                }

                return new MemoryAdapter;

            case 'null':
                return new NullAdapter;

            case 'rackspace':
                if (!class_exists(RackspaceAdapter::class)) {
                    throw PackageRequired::fromAdapterAndPackageNames('rackspace', 'league/flysystem-rackspace');
                }

                $config['client'] = $this->instantiator->instantiate(OpenStack::class, array_merge([
                    'url' => Rackspace::UK_IDENTITY_ENDPOINT,
                ], $config));

                $config['container]'] = $this->instantiator
                                             ->instantiate(ObjectStoreService::class, $config)
                                             ->getContainer($config['container'] ?? '');

                return $this->instantiator->instantiate(RackspaceAdapter::class, $config);

            case 'sftp':
                if (!class_exists(SftpAdapter::class)) {
                    throw PackageRequired::fromAdapterAndPackageNames('sftp', 'league/flysystem-sftp');
                }

                return $this->instantiator->instantiate(SftpAdapter::class, $config);

            case 'replicate':
                if (!class_exists(ReplicateAdapter::class)) {
                    throw PackageRequired::fromAdapterAndPackageNames('sftp', 'league/flysystem-replicate-adapter');
                }

                $source = $this->create($config['source']);
                $replica = $this->create($config['replica']);

                return new League\Flysystem\Replicate\ReplicateAdapter($source, $replica);

            case 'webdav':
                if (!class_exists(WebDAVAdapter::class)) {
                    throw PackageRequired::fromAdapterAndPackageNames('webdav', 'league/flysystem-webdav');
                }

                $config['client'] = $this->instantiator->instantiate(DAVClient::class, $config);

                return $this->instantiator->instantiate(WebDAVAdapter::class, $config);

            case 'phpcr':
                if (!class_exists(PhpcrAdapter::class)) {
                    throw PackageRequired::fromAdapterAndPackageNames('phpcr', 'league/flysystem-phpcr');
                }

                if (!class_exists(RepositoryFactoryDoctrineDBAL::class)) {
                    throw PackageRequired::fromAdapterAndPackageNames('phpcr', 'jackalope/jackalope-doctrine-dbal');
                }

                // Not implemented yet
                throw AdapterNotSupported::fromName('phpcr');

            case 'zip':
                if (!class_exists(ZipArchiveAdapter::class)) {
                    throw PackageRequired::fromAdapterAndPackageNames('zip', 'league/flysystem-ziparchive');
                }

                return $this->instantiator->instantiate(ZipArchiveAdapter::class, $config);

            default:
                throw AdapterNotSupported::fromName($config['adapter']);
        }
    }
}
