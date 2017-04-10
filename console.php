#! /usr/bin/env php
<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2012 Philipp Bergsmann <p.bergsmann@opendo.at>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/


/**
 * Main CLI-Script. Executes the commands.
 *
 * @author Philipp Bergsmann <p.bergsmann@opendo.at>
 * @package GithubTER
 */
require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console as Console;

define('TAB', chr(9));
define('LF', chr(10));
define('CR', chr(13));
define('CRLF', CR . LF);

$application = new Console\Application('Github TYPO3 Extension Repository (TER) Mirror', '0.1.0');
$application->add(new GithubTER\Command\ExtensionListCommand('extensionlist'))
			->addOption(
				'update',
				'u',
				Console\Input\InputOption::VALUE_NONE,
				'updates extensions XML-file'
			)
			->addOption(
				'info',
				'i',
				Console\Input\InputOption::VALUE_NONE,
				'shows last-update time'
			);
$application->add(new GithubTER\Command\WorkerCommand('worker'))
			->addOption(
				'parse',
				'p',
				Console\Input\InputOption::VALUE_NONE,
				'Parses the extension list and fills the queue'
			)
			->addArgument(
				'extensions',
				NULL,
				'List of extensions?',
				''
			)
			->addOption(
				'tag',
				't',
				Console\Input\InputOption::VALUE_NONE,
				'Asks for a job, downloads the ext and tags it'
			)
			->addOption(
				'clearqueue',
				'c',
				Console\Input\InputOption::VALUE_NONE,
				'Clears all the jobs from the queue'
			)
			->addArgument(
				'extensionlist',
				NULL,
				'Path to the extensions.xml-file',
				getcwd() . '/Temp/extensions.xml'
			)
			;
$application->run();
?>