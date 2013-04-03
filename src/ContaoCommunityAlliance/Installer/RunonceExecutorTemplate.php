<?php

/*
 * This file is auto generated via Composer.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ContaoCommunityAlliance\Installer;

use System;

/*
 * Run once executor generated from template.
 */

class RunonceExecutor extends System
{
	public function __construct()
	{
		parent::__construct();
	}

	public function run()
	{
		$runonces = TEMPLATE_RUNONCE_ARRAY;

		foreach ($runonces as $runonce) {
			try {
				require_once(TL_ROOT . DIRECTORY_SEPARATOR . $runonce);
			}
			catch (\Exception $e) {
				// first trigger an error to write this into the log file
				trigger_error(
					$e->getMessage() . "\n" . $e->getTraceAsString(),
					E_USER_ERROR
				);
				// now log into the system log
				$this->log(
					$e->getMessage() . "\n" . $e->getTraceAsString(),
					'RunonceExecutor run()',
					'ERROR'
				);
			}
		}
	}
}

$executor = new RunonceExecutor();
$executor->run();
