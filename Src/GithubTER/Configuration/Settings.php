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
 * Returns the Node-Tree of the expected configuration.
 *
 * @author Philipp Bergsmann <p.bergsmann@opendo.at>
 * @package GithubTer
 */
namespace GithubTER\Configuration;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class Settings implements ConfigurationInterface{

	/**
	 * Generates the configuration tree builder.
	 *
	 * @return \Symfony\Component\Config\Definition\Builder\TreeBuilder The tree builder
	 */
	public function getConfigTreeBuilder() {
		$treeBuilder = new TreeBuilder();
		$rootNode = $treeBuilder->root('Settings');

		$rootNode->children()
				->scalarNode('TempDir')
					->defaultValue('Temp')
				->end()
				->arrayNode('Services')
					->children()
						->arrayNode('Beanstalkd')
							->children()
								->scalarNode('Server')
									->isRequired()
									->cannotBeEmpty()
								->end()
							->end()
						->end()
						->arrayNode('Github')
							->children()
								->scalarNode('AuthToken')
									->isRequired()
									->cannotBeEmpty()
								->end()
								->scalarNode('UserName')
								->end()
								->scalarNode('UserNameIsOrganization')
								->end()
							->end()
						->end()
						->arrayNode('TER')
							->children()
								->scalarNode('ExtensionListUrl')
								->end()
								->scalarNode('ExtensionDownloadUrl')
								->end()
							->end()
						->end();

		return $treeBuilder;
	}
}
?>