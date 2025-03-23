<?php

namespace MediaWiki\Extension\WikiDexApp\Api;

use \ApiBase;
use \ApiResult;
use \FauxRequest;
use \MediaWiki\MediaWikiServices;
use \MediaWiki\Revision\RevisionRecord;
use \MediaWiki\Revision\SlotRecord;
use \MediaWiki\ResourceLoader as RL;
use \Title;
use Wikimedia\Minify\JavaScriptMinifier;
use Wikimedia\Minify\CSSMin;

/**
 * A module that retrieves assets for the app
 *
 * @ingroup API
 * @ingroup Extensions
 */
class ApiWikiDexAppAssetBundle extends ApiBase {

	protected $mExtConfig;

	public function execute() {

		$result_array = [];

		$this->applyGlobalVarConfigModifications();

		$this->getSiteInfo( $result_array );
		$this->getPagesContent( $result_array, [
				'MediaWiki:App/estilos.css',
				'MediaWiki:App/estilosdiurnos.css',
				'MediaWiki:App/estilosnocturnos.css',
				// Legacy script
				'MediaWiki:App/scripts.js'
			]
		);

		// Set the cache mode
		$this->getMain()->setCacheMode( 'public' );

		$apiResult = $this->getResult();
		$apiResult->addValue( null, $this->getModuleName(), $result_array );
	}

	protected function getExamplesMessages() {
		return [
			'action=wikidexappassetbundle&format=json&formatversion=2'
				=> 'apihelp-wikidexappassetbundle-example-simple',
		];
	}

	public function getHelpUrls() {
		return 'https://github.com/ciencia/mediawiki-extensions-WikiDexApp/wiki/ApiWikiDexAppAssetBundle';
	}

	private function getSiteInfo( &$result_array ) {
		$config = $this->getConfig();

		$mainPage = Title::newMainPage();
		$result_array['mainpage'] = $mainPage->getPrefixedText();
		$result_array['base'] = wfExpandUrl( $mainPage->getFullURL(), PROTO_CURRENT );
		$result_array['mediawikiversion'] = MW_VERSION;
	}

	/**
	 * Fills the resulting array with the resources (css and js)
	 *
	 * @param array &$result_array Add resources to this array
	 * @param array $titles Resource titles
	 */
	private function getPagesContent( &$result_array, $titles ) {
		$pagesContent = [];
		$anonUser = MediaWikiServices::getInstance()->getUserFactory()->newAnonymous();
		foreach ( $titles as $title ) {
			$titleObj = Title::newFromText( $title );
			if ( $anonUser->definitelyCan( 'read', $titleObj ) ) {
				$pagesContent[] = $this->getContentPage( $titleObj );
			}
		}
		$result_array['resources'] = $pagesContent;
	}

	private function getContentPage( $titleObj ) {
		$vals = [];
		$vals['title'] = $titleObj->getPrefixedText();
		$chunks = [];
		if ( $vals['title'] == 'MediaWiki:App/scripts.js' ) {
			$chunks[] = $this->getStartupModuleScripts();
			$chunks[] = $this->getInitialModuleScripts();
			$vals['contentformat'] = 'text/javascript';
			$vals['contentmodel'] = 'javascript';
		} else {
			$revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
			$revision = $revisionStore->getRevisionByTitle( $titleObj );
			$slot = $revision->getSlot( SlotRecord::MAIN, RevisionRecord::RAW );
			$content = $slot->getContent();
			$model = $content->getModel();
			$format = $content->getDefaultFormat();
			// always include format and model.
			// Format is needed to deserialize, model is needed to interpret.
			$vals['contentformat'] = $format;
			$vals['contentmodel'] = $model;
			$text = $this->tryGetMinify( $content, $format );
			if ( $text !== false ) {
				$chunks[] = $text;
			}
		}
		$text = implode( "\n", $chunks );
		ApiResult::setContentValue( $vals, 'content', $text );
		return $vals;
	}

	private function tryGetMinify( $content, $format ) {
		$text = $content->serialize( $format );
		switch ( $format ) {
			case 'text/css':
				$text = CSSMin::minify( $text );
				break;
			case 'text/javascript':
				$text = JavaScriptMinifier::minify( $text );
				break;
		}
		return $text;
	}

	private function getStartupModuleScripts() {
		$js = $this->getModuleScripts( [ 'startup' ], [ 'raw' => '1', 'only' => 'scripts' ] ) . "\n";
		// HACK: Remove base scripts that are being force-loaded in the next request,
		// because RL blindly loads them again from load.php
		$resourceLoader = MediaWikiServices::getInstance()->getResourceLoader();
		$context = new RL\Context( $resourceLoader, new FauxRequest( [] ) );
		$find = $context->encodeJson( [ 'jquery', 'mediawiki.base' ] );
		$js = str_replace( $find, '[]', $js );
		return $js;
	}

	private function getInitialModuleScripts() {
		$js = $this->getModuleScripts( $this->getExtConfig( 'WikiDexAppDefaultRLModules' ), [ ] ) . "\n";
		$resourceLoader = MediaWikiServices::getInstance()->getResourceLoader();
		$context = new RL\Context( $resourceLoader, new FauxRequest( [] ) );
		return $js;
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

	private function getModuleScripts( $moduleNames, $options = [] ) {
		$text = '';
		$resourceLoader = MediaWikiServices::getInstance()->getResourceLoader();
		//$options['only'] = 'scripts';
		$context = new RL\Context( $resourceLoader, new FauxRequest( $options ) );
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

	private function applyGlobalVarConfigModifications() {
		$configToModify = $this->getExtConfig( 'WikiDexAppModifySettings' );
		foreach ( $configToModify as $varName => $varValue ) {
			$GLOBALS[$varName] = $varValue;
		}
	}
}
