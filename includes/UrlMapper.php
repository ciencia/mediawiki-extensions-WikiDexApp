<?php

namespace MediaWiki\Extension\WikiDexApp;

use \Title;

/**
 * Maps titles and URLs
 *
 * @license MIT
 */
class UrlMapper {

	private static function appFragmentEncode( $fragment ) {
		// Caracteres que rawurlencode codifica pero que la app no codifica
		// Usado para URL de página. No se usa para obtener los estilos/scripts
		$replacements = [
			'%21' => '!',
			'%27' => '\'',
			'%28' => '(',
			'%29' => ')',
		];
		return str_replace( array_keys( $replacements ),
			array_values( $replacements ),
			rawurlencode( $fragment ) );
	}

	// URL de la app de 1 artículo (old)
	static function getAppPageUrl1( Title $title ) {
		global $wgScriptPath;
		// /api.php?action=parse&format=json&formatversion=2&prop=text|sections|categories&redirects=&page=Gu%C3%ADa%20de%20Detective%20Pikachu%2FP%C3%A1gina%201
		return sprintf(
			"{$wgScriptPath}/api.php?action=parse&format=json&formatversion=2&prop=text|sections|categories&redirects=&page=%s",
			self::appFragmentEncode( $title->getPrefixedText() ) );
	}

	// URL de la app de 1 artículo (actual)
	static function getAppPageUrlWikiDexPage1( Title $title ) {
		global $wgScriptPath;
		// /api.php?action=wikidexpage&format=json&formatversion=2&title=Gu%C3%ADa%20de%20Detective%20Pikachu%2FP%C3%A1gina%201
		return sprintf(
			"{$wgScriptPath}/api.php?action=wikidexpage&format=json&formatversion=2&title=%s",
			self::appFragmentEncode( $title->getPrefixedText() ) );
	}

	// URL de la app para obtener la protección (no debería seguir siendo usado)
	static function getProtectionUrl1( Title $title ) {
		global $wgScriptPath;
		// /api.php?action=query&format=json&prop=info&inprop=protection&titles=WikiDex
		return sprintf(
			"{$wgScriptPath}/api.php?action=query&format=json&prop=info&inprop=protection&titles=%s",
			self::appFragmentEncode( $title->getPrefixedURL() ) );
	}

	// URL de la app para obtener los CSS de estilos individuales (old 2)
	static function getAppStylesUrl1( Title $title ) {
		global $wgScriptPath;
		// /api.php?action=query&format=json&formatversion=2&maxlag=&prop=revisions&titles=MediaWiki:App/estilosnocturnos.css&rvprop=content
		return sprintf(
			"{$wgScriptPath}/api.php?action=query&format=json&formatversion=2&maxlag=&prop=revisions&titles=%s&rvprop=content",
			self::appFragmentEncode( $title->getPrefixedURL() ) );
	}

	// URL de la app para obtener los CSS de todos los estilos (old)
	static function getAppStylesUrlBundle1() {
		global $wgScriptPath;
		// /api.php?action=query&format=json&formatversion=2&prop=revisions&titles=MediaWiki:App/estilos.css|MediaWiki:App/estilosdiurnos.css|MediaWiki:App/estilosnocturnos.css|MediaWiki:App/scripts.js&rvprop=content&rvslots=*
		return "{$wgScriptPath}/api.php?action=query&format=json&formatversion=2&prop=revisions&titles=MediaWiki:App/estilos.css|MediaWiki:App/estilosdiurnos.css|MediaWiki:App/estilosnocturnos.css|MediaWiki:App/scripts.js&rvprop=content&rvslots=*";
	}

	// URL de la app para obtener los CSS de todos los estilos (actual)
	static function getAppStylesUrlBundle2() {
		global $wgScriptPath;
		// /api.php?action=wikidexappassetbundle&format=json&formatversion=2
		return "{$wgScriptPath}/api.php?action=wikidexappassetbundle&format=json&formatversion=2";
	}
}
