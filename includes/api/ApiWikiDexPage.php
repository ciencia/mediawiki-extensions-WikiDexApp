<?php

namespace MediaWiki\Extension\WikiDexApp\Api;

use \ApiBase;
use \ApiMessage;
use \ApiResult;
use \IDBAccessObject;
use \PoolCounterWorkViaCallback;
use \MediaWiki\MediaWikiServices;
use \MediaWiki\Revision\RevisionRecord;
use \MediaWiki\Revision\SlotRecord;
use \ParserOptions;
use \ParserOutput;
use \Status;
use \Title;
use \WikiMap;
use \WikiPage;

/**
 * A module that retrieves all the information of a page to be displayed
 * on the app.
 *
 * @ingroup API
 * @ingroup Extensions
 */
class ApiWikiDexPage extends ApiBase {
	use ApiWikiDexResourceLoaderTrait;

	public function execute() {
		$params = $this->extractRequestParams();

		$this->requireAtLeastOneParameter( $params, 'title' );

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

		if ( $titleObj->isRedirect() ) {
			$revisionLookup = MediaWikiServices::getInstance()->getRevisionLookup();
			// $titles
			$redirect = $revisionLookup->getRevisionByTitle( $titleObj )
				->getContent( SlotRecord::MAIN )
				->getRedirectTarget();
			$redirValues = [];

			if ( $redirect !== null ) {
				$redirValues[] = [
					'from' => $titleObj->getPrefixedText(),
					'to' => $redirect->getPrefixedText()
				];
				$titleObj = $redirect;
			}

			$result_array['redirects'] = $redirValues;
			ApiResult::setIndexedTagName( $redirValues, 'r' );

			// Since the page changed, update $pageObj
			$pageObj = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $titleObj );
		}
		$result_array['title'] = $titleObj->getPrefixedText();

		$pageObj->loadPageData();

		if ( !$titleObj->exists() ) {
			$this->dieWithError( 'apierror-missingtitle' );
		}

		// getParserOutput will save to Parser cache if able
		$pout = $this->getPageParserOutput( $pageObj );
		$text = $this->getHtml( $pout, $titleObj );

		ApiResult::setContentValue( $result_array, 'text', $text );
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
		$result_array['categories'] = $this->formatCategoryLinks(
			method_exists( $pout, 'getCategoryMap' ) ?
			$pout->getCategoryMap() : // 1.43
			$pout->getCategories() // 1.39 compat
		);
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
		];

		return $params;
	}

	protected function getExamplesMessages() {
		return [
			'action=wikidexpage&format=json&formatversion=2&' .
				'title=Gu%C3%ADa%20de%20Detective%20Pikachu%2FP%C3%A1gina%201'
				=> 'apihelp-wikidexpage-example-page',
		];
	}

	public function getHelpUrls() {
		return 'https://github.com/ciencia/mediawiki-extensions-WikiDexApp/wiki/ApiWikiDexPage';
	}

	private function getPoolKey(): string {
		$ip = $this->getRequest()->getIP() ?? '';
		$poolKey = WikiMap::getCurrentWikiId() . ':ApiWikiDexPage:a:' . $ip;
		return $poolKey;
	}

	private function getPageParserOutput( WikiPage $page ) {
		$worker = new PoolCounterWorkViaCallback( 'ApiWikiDexPage', $this->getPoolKey(),
			[
				'doWork' => static function () use ( $page ) {
					global $wgEnableParserLimitReporting;
					$options = ParserOptions::newFromAnon();
					// Without this option, the parser thinks it's not safe to cache...
					$options->enableLimitReport( $wgEnableParserLimitReporting );
					$options->setOption( 'wikidexapp', '1' );
					return $page->getParserOutput( $options );
				},
				'error' => function () {
					$this->dieWithError( 'apierror-concurrency-limit' );
				},
			]
		);
		return $worker->execute();
	}
}
