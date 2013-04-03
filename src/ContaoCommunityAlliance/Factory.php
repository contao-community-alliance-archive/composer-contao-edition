<?php

/*
 * This file is part of Composer.
 *
 * (c) Christian Schiffler <c.schiffler@cyberspectrum.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ContaoCommunityAlliance;

use Composer\IO\IOInterface;
use Composer\Composer;
use Composer\Installer\InstallationManager;
use ContaoCommunityAlliance\Contao\Config;
use ContaoCommunityAlliance\Downloader\LegacyContaoDownloader;
use ContaoCommunityAlliance\Installer\ModuleInstaller;
use ContaoCommunityAlliance\Installer\LegacyModuleInstaller;


/**
 * Creates a configured instance of composer.
 *
 * @author Christian Schiffler <c.schiffler@cyberspectrum.de>
 */
class Factory extends \Composer\Factory
{
	/**
	 * @param  IO\IOInterface             $io
	 * @param  Config                     $config
	 * @return Downloader\DownloadManager
	 */
	public function createDownloadManager(IOInterface $io, \Composer\Config $config)
	{
		// wish there would be some better place, but we can't add in Composer\Factory.php at line 227.
		$config->merge(self::loadContaoConfig($config));

		$dm = parent::createDownloadManager($io, $config);
		$dm->setDownloader('legacy-contao-package', new LegacyContaoDownloader($io, $config));

		return $dm;
	}

	/**
	 * @param Installer\InstallationManager $im
	 * @param Composer                      $composer
	 * @param IO\IOInterface                $io
	 */
	protected function createDefaultInstallers(InstallationManager $im, Composer $composer, IOInterface $io)
	{
		parent::createDefaultInstallers($im, $composer, $io);
		$im->addInstaller(new ModuleInstaller($io, $composer, null));
		$im->addInstaller(new LegacyModuleInstaller($io, $composer, null));
	}

	protected static function loadContaoConfig($config)
	{
//		if (defined(TL_ROOT)) {
//			$configDir = TL_ROOT;
//		} else {
			$configDir = rtrim($config->get('contao-root'), DIRECTORY_SEPARATOR);
//		}

		if (!$configDir)
		{
			$message = 'no contao root provided, please define it in your composer.json.';
			throw new \InvalidArgumentException($message);
			return;
		}

		if (strstr($configDir, '..'))
		{
			$configDir = realpath(getcwd() . DIRECTORY_SEPARATOR . $configDir) . DIRECTORY_SEPARATOR;
		}

		$configDir .= DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;

		if (!is_file($configDir . DIRECTORY_SEPARATOR . 'config.php'))
		{
			trigger_error('invalid contao root provided: ' . $configDir, E_USER_WARNING);
			return;
		}

		$result = self::loadFile($configDir . 'config.php');
		$result = (object)array_merge($result, self::loadFile($configDir . 'localconfig.php'));

		return array('config' => array('contao' => $result));
	}


	protected static function loadFile($filename)
	{
		$strMode = 0;

		$content = file_get_contents($filename);

		$result = array();

		if(preg_match_all('/(\$GLOBALS\[\'TL_CONFIG\'\][^=]*=[^;]*;)/', $content, $matches)) {

			foreach ($matches[0] as $line) {
				$chunks = array_map('trim', explode('=', $line, 2));

				$sections = explode('][', str_replace(array('$GLOBALS[\'TL_CONFIG\'][', '"'), array('', '\''), $chunks[0]));

				$key = implode('.', array_map(function($e) {
					$e = trim($e);

					// chop off the string delimiter and the bracket.
					$e = substr($e, 1, -2);
					return $e;
				}, $sections));

				$value = null;
				// string?
				if ($chunks[1][0] == '\'')
				{
					$value = substr($chunks[1], 1, -2);
				} else {
					$value = substr($chunks[1], 0, -1);
					// bool?
					if (in_array($value, array('true', 'false')))
					{
						$value = ($value == 'true');
					}
				}
				$result[$key] = $value;
			}
		}
		return $result;
	}
}
