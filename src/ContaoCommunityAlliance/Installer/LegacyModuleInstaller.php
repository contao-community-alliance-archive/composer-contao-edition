<?php

/*
 * This file is part of Composer.
 *
 * (c) Christian Schiffler <c.schiffler@cyberspectrum.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ContaoCommunityAlliance\Installer;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Composer;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Script\Event;

/**
 * Contao legacy module installer.
 *
 * @author Christian Schiffler <c.schiffler@cyberspectrum.de>
 */
class LegacyModuleInstaller extends LibraryInstaller
{
	static protected $runonces = array();

	/**
	 * Initializes library installer.
	 *
	 * @param IOInterface $io
	 * @param Composer    $composer
	 * @param string      $type
	 */
	public function __construct(IOInterface $io, Composer $composer, $type = 'library')
	{
		parent::__construct($io, $composer, $type);

		$this->vendorDir = rtrim($composer->getConfig()->get('contao-root'), '/');
		$this->binDir = $this->vendorDir;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getInstallPath(PackageInterface $package)
	{
		$prefix = substr($package->getPrettyName(), 0, 14);
		if ('contao-legacy/' !== $prefix) {
			throw new \InvalidArgumentException(sprintf(
				'Unable to install legacy extension "%s", legacy extensions '
				.'should always start their package name with '
				.'"contao-legacy/"',
				$package->getPrettyName()
				)
			);
		}

		return $this->getPackageBasePath($package) . '/';
	}

	protected function getPackageBasePath(PackageInterface $package)
	{
		$this->initializeVendorDir();
		return ($this->vendorDir ? $this->vendorDir : '');
	}

	protected function installCode(PackageInterface $package)
	{
		parent::installCode($package);
	}

	protected function updateCode(PackageInterface $initial, PackageInterface $target)
	{
		parent::updateCode($initial, $target);
	}

	protected function removeCode(PackageInterface $package)
	{
		parent::removeCode($package);
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports($packageType)
	{
		return 'legacy-contao-module' === $packageType;
	}
}