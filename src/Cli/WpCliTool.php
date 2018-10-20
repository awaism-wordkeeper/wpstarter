<?php declare(strict_types=1);
/*
 * This file is part of the WP Starter package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WeCodeMore\WpStarter\Cli;

use WeCodeMore\WpStarter\Config\Config;
use WeCodeMore\WpStarter\Util\Io;
use WeCodeMore\WpStarter\Util\Paths;
use WeCodeMore\WpStarter\Util\UrlDownloader;

class WpCliTool implements PhpTool
{
    const ENV = [
        'WP_CLI_CACHE_DIR',
        'WP_CLI_DISABLE_AUTO_CHECK_UPDATE',
    ];

    /**
     * @var bool
     */
    private $downloadEnabled;

    /**
     * @var UrlDownloader
     */
    private $urlDownloader;

    /**
     * @param Config $config
     * @param UrlDownloader $urlDownloader
     */
    public function __construct(Config $config, UrlDownloader $urlDownloader)
    {
        $this->downloadEnabled = (bool)$config[Config::INSTALL_WP_CLI]->unwrapOrFallback(true);
        $this->urlDownloader = $urlDownloader;
    }

    /**
     * @return string
     */
    public function niceName(): string
    {
        return 'WP CLI';
    }

    /**
     * @return string
     */
    public function packageName(): string
    {
        return 'wp-cli/wp-cli';
    }

    /**
     * @return string
     */
    public function pharUrl(): string
    {
        $ver = $this->minVersion();

        return $this->downloadEnabled
            ? "https://github.com/wp-cli/wp-cli/releases/download/v{$ver}/wp-cli-{$ver}.phar"
            : '';
    }

    /**
     * @param Paths $paths
     * @return string
     */
    public function pharTarget(Paths $paths): string
    {
        $candidates = glob($paths->root('/wp-cli-*.phar')) ?: [];
        array_unshift($candidates, $paths->root('/wp-cli.phar'));

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param string $packageVendorPath
     * @return string
     */
    public function filesystemBootstrap(string $packageVendorPath): string
    {
        return rtrim($packageVendorPath, '\\/') . '/php/boot-fs.php';
    }

    /**
     * @return string
     */
    public function minVersion(): string
    {
        return '2.0.1';
    }

    /**
     * @param string $pharPath
     * @param Io $io
     * @return bool
     */
    public function checkPhar(string $pharPath, Io $io): bool
    {
        list($algorithm, $hashUrl) = $this->hashAlgorithmUrl($io);

        $hash = $this->urlDownloader->fetch($hashUrl);

        if (!$hash) {
            $io->writeError("Failed to download {$algorithm} hash from {$hashUrl}.");
            $io->writeError($this->urlDownloader->error());

            return false;
        }

        if (hash($algorithm, file_get_contents($pharPath)) !== $hash) {
            $io->writeError("{$algorithm} hash check failed for downloaded WP CLI phar.");

            return false;
        }

        return true;
    }

    /**
     * @param Paths $paths
     * @param \ArrayAccess $env
     * @return array
     */
    public function processEnvVars(Paths $paths, \ArrayAccess $env): array
    {
        $args = [
            'WP_CLI_CONFIG_PATH' => $paths->root(),
            'WP_CLI_DISABLE_AUTO_CHECK_UPDATE' => '1',
            'WP_CLI_CACHE_DIR' => $env['WP_CLI_CACHE_DIR'],
            'WP_CLI_PACKAGES_DIR' => $env['WP_CLI_CACHE_DIR'],
            'WP_CLI_STRICT_ARGS_MODE' => $env['WP_CLI_CACHE_DIR'],
        ];

        return array_filter($args);
    }

    /**
     * @param Io $io
     * @return array
     */
    private function hashAlgorithmUrl(Io $io): array
    {
        if (in_array('sha512', hash_algos(), true)) {
            return ['sha512', $this->pharUrl() . '.sha512'];
        }

        $io->writeComment(
            'NOTICE: SHA-512 algorithm is not available on the system,'
            . ' WP Starter will use the less secure MD5 to check WP CLI phar integrity.'
        );

        return ['md5', $this->pharUrl() . '.md5'];
    }
}