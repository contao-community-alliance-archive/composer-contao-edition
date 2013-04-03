<?php

/*
 * This file is part of Composer.
 *
 * (c) Christian Schiffler <c.schiffler@cyberspectrum.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ContaoCommunityAlliance\Downloader;

use Composer\Config;
use Composer\Downloader\DownloaderInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\Util\RemoteFilesystem;
use SoapClient;

/**
 * Contao legacy module downloader.
 *
 * @author Christian Schiffler <c.schiffler@cyberspectrum.de>
 */
class LegacyContaoDownloader implements DownloaderInterface
{
	protected $io;
	protected $config;
	protected $process;

	/**
	 * @var \Composer\Util\Filesystem
	 */
	protected $filesystem;

	protected $client;

	protected $outputProgress;

	public function __construct(IOInterface $io, Config $config, ProcessExecutor $process = null, Filesystem $fs = null)
	{
		$this->io = $io;
		$this->config = $config;
		$this->process = $process ?: new ProcessExecutor;
		$this->filesystem = $fs ?: new Filesystem;
	}

	/**
	 * Return the SOAP client to be used when interfacing with the legacy extension repository
	 */
	protected function getSOAPClient()
	{
		if (!is_null($this->client))
		{
			return $this->client;
		}

		$cfg = $this->config->get('contao');

		// load soap client in case wsdl file is defined
		$wsdl = trim(($cfg->repository_wsdl
			? $cfg->repository_wsdl
			: 'http://www.contao.org/services/repository.wsdl'));
		if ($wsdl == '') {
			throw new \Exception('no wsdl defined, can not connect to repository', 1);
		}

		try {
//			if (!REPOSITORY_SOAPCACHE) ini_set('soap.wsdl_cache_enabled', 0);
			// Buggy gzencode call in PHP 5.4.0-5.4.3 (thanks to borrible13th) (see Contao issue #4087)
			if (version_compare(PHP_VERSION, '5.4.0', '>=') && version_compare(PHP_VERSION, '5.4.4', '<')) {
				define('SOAP_COMPRESSION_FIXED', SOAP_COMPRESSION_DEFLATE);
			} else {
				define('SOAP_COMPRESSION_FIXED', SOAP_COMPRESSION_GZIP);
			}
			// TODO: use proxy
			if (isset($cfg->useProxy) && $cfg->useProxy) {
				$proxy_uri = parse_url($cfg->proxy_url);
				$this->client = new SoapClient($wsdl, array(
					'soap_version' => SOAP_1_2,
					'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_FIXED | 1,
					'proxy_host' => $proxy_uri['host'],
					'proxy_port' => $proxy_uri['port'],
					'proxy_login' => $proxy_uri['user'],
					'proxy_password' => $proxy_uri['pass']
				));
			}
			// Default client
			else {
				$this->client = new SoapClient($wsdl, array(
					'soap_version' => SOAP_1_2,
					'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_FIXED | 1
				));
			}
		} catch (\Exception $e) {
			throw new \InvalidArgumentException(sprintf('Connection to the repository server failed with message: %s', $e->getMessage()), 1);
		}

		return $this->client;
	}

	protected function getFileNames(PackageInterface $package)
	{
		$url = $package->getDistUrl();
		if (!$url) {
			throw new \InvalidArgumentException(sprintf('Package %s is missing url information', $package->getPrettyName()));
		}

		$urlinfo = parse_url($url);

		if ($urlinfo['scheme'] != 'legacy-contao-package') {
			throw new \InvalidArgumentException(sprintf('Package %s has invalid scheme', $package->getPrettyName()));
		}

		$version = explode('/', $urlinfo['path']);

		$options = new \stdClass();
		$options->name = $urlinfo['host'];
		$options->version = $version[1];

		$client = $this->getSOAPClient();

		$files = $client->getFileList($options);

		return $files;
	}

	function canonicalizePath($address)
	{
		$address = explode('/', $address);
		$keys = array_keys($address, '..');

		foreach($keys AS $keypos => $key)
		{
			array_splice($address, $key - ($keypos * 2 + 1), 2);
		}

		$address = str_replace('./', '', implode('/', $address));
		return $address;
	}

	protected function convertFilenames($files, $path)
	{
		$userfiles = $this->config->get('contao')->uploadPath;

		$path = rtrim($path, '/') . '/';

		$result = array();
		foreach ($files as $file)
		{
			$newfile = clone $file;
			$newfile->localpath = $path . str_replace(array('TL_ROOT/', 'TL_FILES/'), array('', $userfiles), $newfile->path);

			$result[] = $newfile;
		}

		return $result;
	}

	protected function downloadFile($file)
	{
		// use a remote filesystem here.
		$rfs = new RemoteFileSystem($this->io);

		$this->filesystem->ensureDirectoryExists(dirname($file->localpath));

		$rfs->copy($file->url, $file->url, $file->localpath, $this->outputProgress);
	}

	/**
	 * Returns installation source (either source or dist).
	 *
	 * @return string "source" or "dist"
	 */
	public function getInstallationSource()
	{
		return 'dist';
	}

	/**
	 * Downloads specific package into specific folder.
	 *
	 * @param PackageInterface $package package instance
	 * @param string           $path    download path
	 */
	public function download(PackageInterface $package, $path)
	{
		$this->io->write(sprintf('  - Installing <info>%s</info> (<comment>%s</comment>)', $package->getName(), VersionParser::formatVersion($package)));

		$this->filesystem->ensureDirectoryExists($path);

		$files = $this->getFileNames($package);
		$files = $this->convertFilenames($files, $path);

		foreach ($files as $file) {
			$this->downloadFile($file);
		}

		$this->io->write('');
	}


	/**
	 * Updates specific package in specific folder from initial to target version.
	 *
	 * @param PackageInterface $initial initial package
	 * @param PackageInterface $target  updated package
	 * @param string           $path    download path
	 */
	public function update(PackageInterface $initial, PackageInterface $target, $path)
	{
		$this->remove($initial, $path);
		$this->download($target, $path);
	}


	/**
	 * Check if directory exists and is empty (does only contain '..' and '.')
	 */
	protected function isEmptyDir($path)
	{
		return is_dir($path) && (($files = scandir($path)) && count($files) <= 2);
	}


	/**
	 * Removes specific package from specific folder.
	 *
	 * @param PackageInterface $package package instance
	 * @param string           $path    download path
	 */
	public function remove(PackageInterface $package, $path)
	{
		$this->io->write(sprintf('  - Removing <info>%s</info> (<comment>%s</comment>)', $package->getName(), VersionParser::formatVersion($package)));
		$files = $this->getFileNames($package);

		$files = $this->convertFilenames($files, $path);

		$directories = array();

		foreach ($files as $file) {
			$this->io->overwrite(sprintf('  - Removing <info>%s</info> (<comment>%s</comment>) <info>deleting: %s</info>', $package->getName(), VersionParser::formatVersion($package), $file->localpath));
			$this->filesystem->remove($file->localpath);

			$dir = dirname($file->localpath);
			$directories[$dir] = $dir;
		}

		// recursively clean up empty directories.
		while ($directories) {
			$dir = array_pop($directories);
			if ($this->isEmptyDir($dir)) {
				$this->io->overwrite(sprintf('  - Removing <info>%s</info> (<comment>%s</comment>) <info>removing empty directory: %s</info>', $package->getName(), VersionParser::formatVersion($package), $dir));

				$this->filesystem->removeDirectory($dir);
				$directories[] = dirname($dir);
			}
		}

		$this->io->write('');
	}


	/**
	 * Sets whether to output download progress information or not
	 *
	 * @param  bool                $outputProgress
	 * @return DownloaderInterface
	 */
	public function setOutputProgress($outputProgress)
	{
		$this->outputProgress = $outputProgress;
		return $this;
	}


}
