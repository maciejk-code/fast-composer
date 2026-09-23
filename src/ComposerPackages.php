<?php
namespace FastComposer;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\AliasPackage;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Repository\VcsRepository;
use Composer\Util\HttpDownloader;

/**
 * Turns a VCS repository into package data with Composer's own VcsRepository, reading from the
 * local mirror through MirrorDriver. The result is what Composer itself would offer for that
 * repository; Fast Composer only decides when and how the data is fetched.
 */
final class ComposerPackages
{
    private NullIO $io;
    private \Composer\Config $config;
    private HttpDownloader $httpDownloader;

    public function __construct(string $cwd)
    {
        if (!InProcessComposer::loadClasses()) {
            throw new \RuntimeException('Composer classes are not available');
        }
        $this->io = new NullIO();
        $this->config = Factory::createConfig($this->io, $cwd);
        $this->httpDownloader = new HttpDownloader($this->io, $this->config);
    }

    /** Version of the Composer whose logic produced the package data. */
    public static function composerVersion(): ?string
    {
        return self::available() ? \Composer\Composer::getVersion() : null;
    }

    public static function available(): bool
    {
        return InProcessComposer::loadClasses() && class_exists(VcsRepository::class);
    }

    /**
     * @param array $repoConfig the repository entry from the root composer.json
     * @param array{root:string,branches:array<string,string>,tags:array<string,string>,files:array<string,array{composer:?string,date:?int}>} $source
     * @return list<array> packages as Composer dumps them (aliases are recreated by the loader)
     */
    public function forRepository(array $repoConfig, array $source): array
    {
        $url = (string) $repoConfig['url'];
        MirrorDriver::serve($url, $source);
        try {
            // Repository options such as only/exclude/canonical are applied by Composer to the
            // snapshot repository entry itself (see Snapshot::writeFastComposer).
            $config = array_diff_key($repoConfig, array_flip(['only', 'exclude', 'canonical']));
            $config['type'] = 'fast-composer-mirror';
            $repository = new VcsRepository($config, $this->io, $this->config, $this->httpDownloader, null, null, ['fast-composer-mirror' => MirrorDriver::class]);

            $dumper = new ArrayDumper();
            $packages = [];
            foreach ($repository->getPackages() as $package) {
                if (!$package instanceof AliasPackage) {
                    $packages[] = $dumper->dump($package);
                }
            }
            return $packages;
        } finally {
            MirrorDriver::release($url);
        }
    }
}
