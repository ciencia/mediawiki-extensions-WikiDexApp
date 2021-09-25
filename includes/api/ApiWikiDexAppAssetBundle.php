<?php

namespace MediaWiki\Extension\WikiDexApp\Api;

use \ApiBase;
use \ApiResult;
use \MediaWiki\MediaWikiServices;
use \MediaWiki\Permissions\UserAuthority;
use \MediaWiki\Revision\RevisionRecord;
use \MediaWiki\Revision\SlotRecord;
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

	public function execute() {

		$result_array = [];

		$this->getSiteInfo( $result_array );
		$this->getPagesContent( $result_array,
			[ 'MediaWiki:App/estilos.css', 'MediaWiki:App/estilosdiurnos.css', 'MediaWiki:App/estilosnocturnos.css', 'MediaWiki:App/scripts.js' ]
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
		return 'https://www.wikidex.net/wiki/Ayuda:API:wikidexappassetbundle';
	}

	private function getSiteInfo( &$result_array ) {
		$config = $this->getConfig();

		$mainPage = Title::newMainPage();
		$result_array['mainpage'] = $mainPage->getPrefixedText();
		$result_array['base'] = wfExpandUrl( $mainPage->getFullURL(), PROTO_CURRENT );
		$result_array['mediawikiversion'] = MW_VERSION;
	}

	private function getPagesContent( &$result_array, $titles ) {
		$pagesContent = [];
		$anonUser = MediaWikiServices::getInstance()->getUserFactory()->newAnonymous();
		$anonAuthority = new UserAuthority( $anonUser, MediaWikiServices::getInstance()->getPermissionManager() );
		foreach ( $titles as $title ) {
			$titleObj = Title::newFromText( $title );
			if ( $anonAuthority->authorizeRead( 'read', $titleObj ) ) {
				$pagesContent[] = $this->getContentPage( $titleObj );
			}
		}
		$result_array['pages'] = $pagesContent;
	}

	private function getContentPage( $title ) {
		$vals = [];
		$vals['title'] = $title->getPrefixedText();
		$revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
		$revision = $revisionStore->getRevisionByTitle( $title );
		$slot = $revision->getSlot( SlotRecord::MAIN, RevisionRecord::RAW );
		$content = $slot->getContent();
		$model = $content->getModel();
		$format = $content->getDefaultFormat();
		$text = $this->tryGetMinify( $content, $format );
		// always include format and model.
		// Format is needed to deserialize, model is needed to interpret.
		$vals['contentformat'] = $format;
		$vals['contentmodel'] = $model;
		if ( $text !== false ) {
			ApiResult::setContentValue( $vals, 'content', $text );
		}
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
}
