<?php

namespace MediaWiki\Extension\WikiDexApp\Api;

use \ApiResult;
use \FauxRequest;
use \Html;
use \MediaWiki\MediaWikiServices;
use \ParserOutput;
use \ResourceLoader;
use \ResourceLoaderContext;
use \Title;

trait ApiWikiDexResourceLoaderTrait {

	/** @var Config */
	protected $mExtConfig;

	/** @var ResourceLoader */
	protected $mResourceLoader;

	protected function getHtml( ParserOutput $parserOutput, Title $title ) {
		$chunks = [];

		$resourceLoader = $this->getResourceLoader();
		$context = new ResourceLoaderContext( $resourceLoader, new FauxRequest() );

		$script = <<<JAVASCRIPT
document.documentElement.className = "client-js";
JAVASCRIPT;

		$confJson = $context->encodeJson( $this->getJSVars( $parserOutput, $title ) );
		$script .= <<<JAVASCRIPT
RLCONF = {$confJson};
JAVASCRIPT;

		$script .= <<<JAVASCRIPT
RLSTATE = {};
JAVASCRIPT;

		$chunks[] = Html::inlineScript( $script );

		$modules = $parserOutput->getModuleStyles();
		if ( count( $modules ) > 0 ) {
			$chunks[] = $this->getModuleStyles( $modules );
		}

		$chunks[] = $parserOutput->getText( [
			'allowTOC' => false,
			'enableSectionEditLinks' => true,
		] );

		$modules = $parserOutput->getModules();

		if ( count( $modules ) > 0 ) {
			$chunks[] = $this->getModuleScripts( $modules );
		}

		return implode( "\n", $chunks );
	}

	/**
	 * Get a ResourceLoader instance
	 *
	 * @return ResourceLoader
	 */
	private function getResourceLoader() {
		if ( $this->mResourceLoader === null ) {
			// Lazy-initialise as needed
			$this->mResourceLoader = MediaWikiServices::getInstance()->getResourceLoader();
		}
		return $this->mResourceLoader;
	}

	/**
	 * Returns a configuration setting for this extension
	 *
	 * @param string $name Name of the configuration setting
	 * @return mixed configuration value
	 */
	private function getExtConfig( $name ) {
		if ( $this->mExtConfig == null ) {
			$this->mExtConfig = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'wikidexapp' );
		}
		return $this->mExtConfig->get( $name );
	}

	/**
	 * Returns a list of modules loaded by default, that shouldn't be loaded on specific pages
	 * @return array
	 */
	private function getStartupModules() {
		return $this->getExtConfig( 'WikiDexAppDefaultRLModules' );
	}

	/**
	 * Returns a list of allowed modules (other than the ones loaded by default)
	 * @return array
	 */
	private function getAllowedModules() {
		return $this->getExtConfig( 'WikiDexAppAllowedRLModules' );
	}

	/**
	 * Get an array containing the variables to be set in mw.config in JavaScript.
	 * @return array
	 */
	public function getJSVars( ParserOutput $parserOutput, Title $title ) {
		$services = MediaWikiServices::getInstance();

		$ns = $title->getNamespace();
		$nsInfo = $services->getNamespaceInfo();
		$canonicalNamespace = $nsInfo->exists( $ns )
			? $nsInfo->getCanonicalName( $ns )
			: $title->getNsText();

		$lang = $title->getPageViewLanguage();

		// Pre-process information
		$separatorTransTable = $lang->separatorTransformTable();
		$separatorTransTable = $separatorTransTable ?: [];
		$compactSeparatorTransTable = [
			implode( "\t", array_keys( $separatorTransTable ) ),
			implode( "\t", $separatorTransTable ),
		];
		$digitTransTable = $lang->digitTransformTable();
		$digitTransTable = $digitTransTable ?: [];
		$compactDigitTransTable = [
			implode( "\t", array_keys( $digitTransTable ) ),
			implode( "\t", $digitTransTable ),
		];

		$user = $this->getUser();

		// Internal variables for MediaWiki core
		$vars = [
			// @internal For jquery.tablesorter
			'wgSeparatorTransformTable' => $compactSeparatorTransTable,
			'wgDigitTransformTable' => $compactDigitTransTable,
			'wgDefaultDateFormat' => $lang->getDefaultDateFormat(),
			'wgMonthNames' => $lang->getMonthNamesArray(),
		];

		// Start of supported and stable config vars (for use by extensions/gadgets).
		$vars += [
			'wgCanonicalNamespace' => $canonicalNamespace,
			'wgNamespaceNumber' => $title->getNamespace(),
			'wgPageName' => $title->getPrefixedDBkey(),
			'wgTitle' => $title->getText(),
			'wgIsArticle' => true,
			'wgIsRedirect' => $title->isRedirect(),
			'wgAction' => 'view',
			'wgUserName' => null,
			'wgUserGroups' => [],
			'wgCategories' => $this->getCategories( $parserOutput ),
			'wgPageContentLanguage' => $lang->getCode(),
			'wgPageContentModel' => $title->getContentModel(),
		];
		// End of stable config vars

		// Merge in variables from addJsConfigVars last
		return array_merge( $vars, $parserOutput->getJsConfigVars() );
	}

	/**
	 * Get categories from parserOutput
	 */
	private function getCategories( $parserOutput ) {
		$categories = $parserOutput->getCategoryNames() ?? [];
		$fmtCategories = [];
		foreach ( $categories as $category ) {
			$fmtCategories[] = strtr( $category, '_', ' ' );
		}
		return $fmtCategories;
	}

	/**
	 * Get RL module scripts
	 */
	private function getModuleScripts( $moduleNames ) {
		$finalModuleNames = [];
		$resourceLoader = $this->getResourceLoader();
		$context = new ResourceLoaderContext( $resourceLoader, new FauxRequest() );
		foreach( $moduleNames as $moduleName ) {
			if ( !in_array( $moduleName, $this->getAllowedModules() ) ) {
				continue;
			}
			$deps = $this->getModuleDeps( $moduleName, $context );
			if ( count( array_intersect( $deps, $this->getAllowedModules() ) ) > 0 ) {
				// Depends on a module not allowed
				continue;
			}
			$finalModuleNames = array_merge( $finalModuleNames, [ $moduleName ], $deps );
		}

		sort( $finalModuleNames );
		$finalModuleNames = array_unique( $finalModuleNames );
		// Remove already loaded modules
		$startupModules = $this->getStartupModules();
		$finalModuleNames = array_filter( $finalModuleNames, function( $moduleName ) use ( $startupModules ) {
			return !in_array( $moduleName, $startupModules );
		} );

		if ( count( $finalModuleNames ) === 0 ) {
			return '';
		}

		$html = ResourceLoader::makeInlineScript(
			$this->getModuleOutput( $finalModuleNames )
		);
		return $html;
	}

	/**
	 * Get module dependencies (recursive). Doesn't return this module itself
	 *
	 * @return array Module dependencies
	 */
	private function getModuleDeps( $moduleName, ResourceLoaderContext $context ) {
		$deps = [];
		$resourceLoader = $this->getResourceLoader();
		$module = $resourceLoader->getModule( $moduleName );
		$dependencies = $module->getDependencies( $context );
		foreach ( $dependencies as $dep ) {
			$deps = array_merge( $deps, [ $dep ], $this->getModuleDeps( $dep, $context ) );
		}
		return $deps;
	}

	private function getModuleStyles( $moduleNames ) {
		$finalModuleNames = [];
		$resourceLoader = $this->getResourceLoader();
		$context = new ResourceLoaderContext( $resourceLoader, new FauxRequest() );
		foreach( $moduleNames as $moduleName ) {
			if ( !in_array( $moduleName, $this->getAllowedModules() ) ) {
				continue;
			}
			$deps = $this->getModuleDeps( $moduleName, $context );
			if ( count( array_intersect( $deps, $this->getAllowedModules() ) ) > 0 ) {
				// Depends on a module not allowed
				continue;
			}
			$finalModuleNames = array_merge( $finalModuleNames, [ $moduleName ], $deps );
		}

		sort( $finalModuleNames );
		$finalModuleNames = array_unique( $finalModuleNames );
		// Remove already loaded modules
		$startupModules = $this->getStartupModules();
		$finalModuleNames = array_filter( $finalModuleNames, function( $moduleName ) use ( $startupModules ) {
			return !in_array( $moduleName, $startupModules );
		} );

		if ( count( $finalModuleNames ) === 0 ) {
			return '';
		}

		$html = Html::inlineStyle(
			$this->getModuleOutput( $finalModuleNames, [ 'only' => 'styles' ] )
		);
		return $html;
	}

	private function getModuleOutput( $moduleNames, $options = [] ) {
		$text = '';
		$resourceLoader = $this->getResourceLoader();
		$context = new ResourceLoaderContext( $resourceLoader, new FauxRequest( $options ) );
		$modules = [];
		foreach ( $moduleNames as $name ) {
			$module = $resourceLoader->getModule( $name );
			if ( $module ) {
				// Do not allow private modules to be loaded from the web.
				// This is a security issue, see T36907.
				if ( $module->getGroup() === 'private' ) {
					continue;
				}
				$modules[$name] = $module;
			}
		}
		try {
			// Preload for getCombinedVersion() and for batch makeModuleResponse()
			$resourceLoader->preloadModuleInfo( array_keys( $modules ), $context );
		} catch ( Exception $e ) {
			$resourceLoader->outputErrorAndLog( $e, 'Preloading module info failed: {exception}' );
		}

		// Generate a response
		$text = $resourceLoader->makeModuleResponse( $context, $modules );

		return $text;
	}

	protected function applyGlobalVarConfigModifications() {
		$configToModify = $this->getExtConfig( 'WikiDexAppModifySettings' );
		foreach ( $configToModify as $varName => $varValue ) {
			$GLOBALS[$varName] = $varValue;
		}
	}

	/**
	 * Method copied from ApiParse
	 */
	private function formatCategoryLinks( $links ) {
		$result = [];

		if ( !$links ) {
			return $result;
		}

		// Fetch hiddencat property
		$linkBatchFactory = MediaWikiServices::getInstance()->getLinkBatchFactory();
		$lb = $linkBatchFactory->newLinkBatch();
		$lb->setArray( [ NS_CATEGORY => $links ] );
		$db = $this->getDB();
		$res = $db->select( [ 'page', 'page_props' ],
			[ 'page_title', 'pp_propname' ],
			$lb->constructSet( 'page', $db ),
			__METHOD__,
			[],
			[ 'page_props' => [
				'LEFT JOIN', [ 'pp_propname' => 'hiddencat', 'pp_page = page_id' ]
			] ]
		);
		$hiddencats = [];
		foreach ( $res as $row ) {
			$hiddencats[$row->page_title] = isset( $row->pp_propname );
		}

		$linkCache = MediaWikiServices::getInstance()->getLinkCache();

		foreach ( $links as $link => $sortkey ) {
			$entry = [];
			// Unsupported because it's not needed
			//$entry['sortkey'] = '';
			// array keys will cast numeric category names to ints, so cast back to string
			ApiResult::setContentValue( $entry, 'category', (string)$link );
			if ( !isset( $hiddencats[$link] ) ) {
				$entry['missing'] = true;

				// We already know the link doesn't exist in the database, so
				// tell LinkCache that before calling $title->isKnown().
				$title = Title::makeTitle( NS_CATEGORY, $link );
				$linkCache->addBadLinkObj( $title );
				if ( $title->isKnown() ) {
					$entry['known'] = true;
				}
			} elseif ( $hiddencats[$link] ) {
				$entry['hidden'] = true;
			}
			$result[] = $entry;
		}

		return $result;
	}
}
