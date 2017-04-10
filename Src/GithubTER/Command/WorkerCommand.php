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
 * Main Command. Executes the parser or the tagger.
 *
 * @author Philipp Bergsmann <p.bergsmann@opendo.at>
 * @author Georg Ringer
 * @package GithubTER
 */
namespace GithubTER\Command;

use Github\Client;
use GithubTER\Domain\Model\Version;
use Pheanstalk\Pheanstalk;
use Symfony\Component\Console;
use GithubTER\Service;
use GithubTER\Domain\Model;
use GithubTER\Configuration;

class WorkerCommand extends BaseCommand {
	/**
	 * @var Pheanstalk
	 */
	protected $beanstalk;

	/**
	 * @var Service\Download\DownloadInterface
	 */
	protected $downloadService;

	/**
	 * @var Client
	 */
	protected $github;

	/**
	 * @var Service\T3xExtractor
	 */
	protected $t3xExtractor;

	/**
	 * @var array
	 */
	protected $existingRepositories = array();

	/**
	 * Connects to the beanstalk server
	 *
	 * @param Console\Input\InputInterface $input
	 * @param Console\Output\OutputInterface $output
	 * @return void
	 */
	protected function initialize(Console\Input\InputInterface $input, Console\Output\OutputInterface $output) {
		parent::initialize($input, $output);

		$this->beanstalk = new Pheanstalk($this->configurationManager->get('Services.Beanstalkd.Server'));
		$this->downloadService = new Service\Download\Curl();
		$this->t3xExtractor = new Service\T3xExtractor();
		$this->github = new Client();
		$this->github->authenticate(
			$this->configurationManager->get('Services.Github.AuthToken'),
			'',
			Client::AUTH_HTTP_TOKEN
		);
	}

	/**
	 * Main method. Forwards the call to the executing methods.
	 *
	 * @param Console\Input\InputInterface $input
	 * @param Console\Output\OutputInterface $output
	 * @return int|null|void
	 */
	protected function execute(Console\Input\InputInterface $input, Console\Output\OutputInterface $output) {
		if ($input->getOption('parse') === TRUE) {
			$output->writeln('Starting parser (file: ' . $this->input->getArgument('extensionlist') . ')');
			$extensions = $this->input->getArgument('extensions');
			if (!empty($extensions)) {
				$output->writeln(sprintf(TAB . 'including the extensions "%s".', $extensions));
			}
			$this->parse($extensions);
		}

		if ($input->getOption('tag')) {
			$this->tag();
		}

		if ($input->getOption('clearqueue')) {
			$this->clearqueue();
		}
	}

	/**
	 * Parses the extensions.xml and fills the job-queue. Checks if a version is tagged on Github and excludes it.
	 *
	 * @param string $extensionList
	 * @return void
	 */
	protected function parse($extensionList = '') {
		$mapper = new \GithubTER\Mapper\ExtensionMapper();
		$mapper->loadExtensionList($this->input->getArgument('extensionlist'));
		$mapper->run($extensionList);
		$mappedResult = $mapper->getMappedResult();

		foreach ($mappedResult as $extension) {
			/** @var $extension \GithubTER\Domain\Model\Extension */

			$existingTags = array();
			try {
				$repository = $this->github->api('repository')->show('typo3-ter', $extension->getKey());
				$this->existingRepositories[$repository['name']] = $repository['ssh_url'];

				$tags = $this->github->api('git')->tags()->all('typo3-ter', $extension->getKey());
				foreach ($tags as $tag) {
					$existingTags[] = trim($tag['ref'], 'refs/tags/');
				}
			} catch (\Exception $e) {
				if (array_key_exists($extension->getKey(), $this->existingRepositories) === FALSE) {
					try {
						$createdRepository = $this->github->api('repository')->create($extension->getKey(), '', 'http://typo3.org/extensions/repository/view/' . $extension->getKey(), TRUE, 'typo3-ter');
						$this->existingRepositories[$extension->getKey()] = $createdRepository['ssh_url'];
					} catch (\Exception $e) {
					}

				}
			}

			$extension->setRepositoryPath($this->existingRepositories[$extension->getKey()]);

			$versions = $extension->getVersions();

			$versions->rewind();
			while ($versions->valid()) {
				$object = $versions->current();
				$versions->next();

				if (in_array($object->getNumber(), $existingTags)) {
					$this->output->writeln('Version ' . $object->getNumber() . ' is already tagged');
					$extension->removeVersion($object);
				} elseif ($object->getReviewState() == -1) {
					$this->output->writeln('Version ' . $object->getNumber() . ' is new and insure');
				}
			}

			if (count($extension->getVersions()) > 0) {
				foreach ($versions as $version) {
					$this->output->writeln('Version ' . $version->getNumber() . ' is taken into account');
				}
				$this->beanstalk->putInTube('extensions', gzcompress(serialize($extension), 9));
			} else {
				$this->output->writeln('Extension ' . $extension->getKey() . ' is ignored, all versions tagged already.');
			}
		}
	}

	/**
	 * Downloads the T3X-files, inits the GIT-repository, pushes and tags the release.
	 *
	 * @return void
	 */
	protected function tag() {
		$this->output->writeln(array(
			'Starting the tagger',
			'Waiting for a job'
		));

		/** @var $job \Pheanstalk_Job */
		$job = $this->beanstalk->watch('extensions')->reserve();

		/** @var $extension Model\Extension */
		$extension = unserialize(gzuncompress($job->getData()));
		$this->output->writeln('Starting job ' . $job->getId() . ': "' . $extension->getKey() . '"');

		$extensionDir = $this->configurationManager->get('TempDir') . '/Extension/' . $extension->getKey() . '/';

		/** @var Version $extensionVersion */
		foreach ($extension->getVersions() as $extensionVersion) {
			if (is_dir($extensionDir)) {
				$this->output->writeln('Removing directory ' . $extensionDir);
				exec('rm -Rf ' . escapeshellarg($extensionDir));
			}

			$this->output->writeln('Creating directory ' . $extensionDir);
			mkdir($extensionDir, 0777, TRUE);

			$this->output->writeln('Initializing GIT-Repository with origin: ' . $extension->getRepositoryPath());
			exec(
				'cd ' . escapeshellarg($extensionDir)
				. ' && git init'
				. ' && git remote add origin ' . $extension->getRepositoryPath()
				. ' && git config user.name "TYPO3-TER Bot"'
				. ' && git config user.email "typo3ter-bot@ringerge.org"'
			);

			try {
				$this->github->api('repository')->commits()->all('typo3-ter', $extension->getKey(), array());
				$this->output->writeln('Commit found -> pulling');
				exec('cd ' . escapeshellarg($extensionDir) . ' && git pull -q origin master');
				$this->recursiveRemove($extensionDir);

			} catch (\Exception $e) {
				$this->output->writeln('No Commit found');
			}

			$t3xPath = $extensionDir . $extension->getKey() . '.t3x';
			$this->output->writeln('Downloading version ' . $extensionVersion->getNumber());

			$downloadedExtension = @file_get_contents($this->configurationManager->get('Services.TER.ExtensionDownloadUrl') . $extension->getKey() . '/' . $extensionVersion->getNumber() . '/t3x/');
			if ($downloadedExtension === FALSE) {
				$this->output->writeln(sprintf('ERROR: Version "%s" of extension "%s" could not be downloaded!', $extensionVersion->getNumber(), $extension->getKey()));
			} else {
				file_put_contents($t3xPath, $downloadedExtension);
				$this->t3xExtractor->setT3xFileName($t3xPath);
				$this->t3xExtractor->setExtensionDir($extensionDir);
				$this->t3xExtractor->setExtensionVersion($extensionVersion);
				$this->t3xExtractor->extract();
				unlink($t3xPath);

				$this->output->writeln('Generate custom README.md');
				$readmeWriter = new Service\ReadmeWriter($extension, $extensionVersion);
				$readmeWriter->write();

				$this->output->writeln('Committing, tagging and pushing version ' . $extensionVersion->getNumber());
				exec(
					'cd ' . escapeshellarg($extensionDir)
					. ' && git add -A'
					. ' && git commit -m "Import of Version ' . $extensionVersion->getNumber() . '"'
					. ' && git tag -a -m "Version ' . $extensionVersion->getNumber() . '" ' . $extensionVersion->getNumber()
					. ' && git push --tags origin master'
				);

			}
		}

		$this->output->writeln('Finished job (ID: ' . $job->getId() . ')');
		$this->beanstalk->delete($job);
	}

	/**
	 * Fetches all jobs from the queue and deletes them
	 *
	 * @return void
	 */
	protected function clearqueue() {
		while ($job = $this->beanstalk->watch('extensions')->reserve()) {
			$this->output->writeln('Deleting job #' . $job->getId());
			$this->beanstalk->delete($job);
		}
		$this->output->writeln('Finished clearing the queue');
	}

	protected function recursiveRemove($dir) {
		$structure = glob(rtrim($dir, "/") . '/*');
		if (is_array($structure)) {
			foreach ($structure as $file) {
				if ($file != '.git') {
					if (is_dir($file)) {
						$this->recursiveRemove($file);
					} elseif (is_file($file)) {
						unlink($file);
					}
				}
			}
		}

    @rmdir($dir);
}
}

?>