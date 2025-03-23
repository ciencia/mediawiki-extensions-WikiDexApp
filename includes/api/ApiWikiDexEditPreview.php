<?php

namespace MediaWiki\Extension\WikiDexApp\Api;

use \ApiBase;
use \ApiMessage;
use \ApiResult;
use \ContentHandler;
use \Content;
use \IDBAccessObject;
use \Linker;
use \PoolCounterWorkViaCallback;
use \MediaWiki\Content\Renderer\ContentParseParams;
use \MediaWiki\MediaWikiServices;
use \MediaWiki\Revision\RevisionRecord;
use \MediaWiki\Revision\SlotRecord;
use \MWContentSerializationException;
use \ParserOptions;
use \ParserOutput;
use \Status;
use \Title;
use \WikiMap;

/**
 * Displays an edit preview from the App to render how the page will look
 * from the app.
 *
 * @ingroup API
 * @ingroup Extensions
 */
class ApiWikiDexEditPreview extends ApiBase {
	use ApiWikiDexResourceLoaderTrait;

	public function execute() {
		global $wgEnableParserLimitReporting;

		$params = $this->extractRequestParams();

		$this->requireAtLeastOneParameter( $params, 'title' );
		$this->requireAtLeastOneParameter( $params, 'text' );

		$titleObj = Title::newFromText( $params['title'] );
		if ( !$titleObj || $titleObj->isExternal() ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['title'] ) ] );
		}
		if ( !$titleObj->canExist() ) {
			$this->dieWithError( 'apierror-pagecannotexist' );
		}

		$this->applyGlobalVarConfigModifications();

		// Current user not needed
		$anonUser = MediaWikiServices::getInstance()->getUserFactory()->newAnonymous();
		if ( !$anonUser->definitelyCan( 'read', $titleObj ) ) {
			$this->dieWithError(
				[ 'apierror-cannotviewtitle', wfEscapeWikiText( $titleObj->getPrefixedText() ) ]
			);
		}

		$pageObj = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $titleObj );
		$apiResult = $this->getResult();
		$result_array = [];
		$result_array['title'] = $titleObj->getPrefixedText();

		$pageObj->loadPageData();

		$content = null;
		try {
			$content = ContentHandler::makeContent( $params['text'], $titleObj );
		} catch ( MWContentSerializationException $ex ) {
			$this->dieWithException( $ex, [
				'wrap' => ApiMessage::create( 'apierror-contentserializationexception', 'parseerror' )
			] );
		}

		$popts = ParserOptions::newFromAnon();
		$popts->setIsPreview( true );
		$popts->setIsSectionPreview( $params['sectionpreview'] );
		// Without this option, the parser thinks it's not safe to cache...
		$popts->enableLimitReport( $wgEnableParserLimitReporting );
		$popts->setOption( 'wikidexapp', '1' );

		// getParserOutput will save to Parser cache if able
		$pout = $this->getContentParserOutput( $content, $titleObj, $popts );
		$text = $this->getHtml( $pout, $titleObj );
		ApiResult::setContentValue( $result_array, 'text', $text );

		if ( $params['summary'] !== null ) {
			$result_array['parsedsummary'] = Linker::formatComment( $params['summary'], $titleObj, false );
			$result_array[ApiResult::META_BC_SUBELEMENTS][] = 'parsedsummary';
		}

		$displayTitle = $pout->getPageProperty( 'displaytitle' );
		if ( $displayTitle ) {
			$result_array['displaytitle'] = $displayTitle;
		}
		$pp = $pout->getPageProperties();
		if ( isset( $pp['noeditsection'] ) ) {
			$result_array['noeditsection'] = true;
		}
		if ( isset( $pp[ 'notoc' ] ) ) {
			$result_array['notoc'] = true;
		}
		$result_array['sections'] = $pout->getSections();
		ApiResult::setIndexedTagName( $result_array['sections'], 's' );
		$result_array['categories'] = $this->formatCategoryLinks( $pout->getCategoryMap() );
		ApiResult::setIndexedTagName( $result_array['categories'], 'cl' );

		// Edit permissions
		$user = $this->getUser();
		$result_array['userCanEdit'] = $user->definitelyCan(
			$titleObj->exists() ? 'edit' : 'create',
			$titleObj
		);

		// Set the cache mode
		$this->getMain()->setCacheMode( 'anon-public-user-private' );

		$apiResult->addValue( null, $this->getModuleName(), $result_array );
	}

	public function getAllowedParams() {
		$params = [
			'title' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'text' => [
				ApiBase::PARAM_TYPE => 'text',
			],
			'summary' => null,
			'sectionpreview' => false,
		];

		return $params;
	}

	protected function getExamplesMessages() {
		return [
			'action=wikidexeditpreview&formatversion=2&' .
				'title=Gu%C3%ADa%20de%20Detective%20Pikachu%2FP%C3%A1gina%201&' .
				'text={{Project:Sandbox}}'
				=> 'apihelp-wikidexeditpreview-example-page',
		];
	}

	public function getHelpUrls() {
		return 'https://github.com/ciencia/mediawiki-extensions-WikiDexApp/wiki/ApiWikiDexEditPreview';
	}

	public function mustBePosted() {
		return true;
	}

	private function getPoolKey(): string {
		$ip = $this->getRequest()->getIP() ?? '';
		$poolKey = WikiMap::getCurrentWikiId() . ':ApiWikiDexEditPreview:a:' . $ip;
		return $poolKey;
	}

	private function getContentParserOutput( Content $content, Title $title, ParserOptions $popts ) {
		$worker = new PoolCounterWorkViaCallback( 'ApiWikiDexEditPreview', $this->getPoolKey(),
			[
				'doWork' => static function () use ( $content, $title, $popts ) {
					$cpoParams = new ContentParseParams( $title, $revId, $popts, true );
					return $content->getContentHandler()->getParserOutput( $content, $cpoParams );
				},
				'error' => function () {
					$this->dieWithError( 'apierror-concurrency-limit' );
				},
			]
		);
		return $worker->execute();
	}
}
