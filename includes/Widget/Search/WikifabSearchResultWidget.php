<?php

namespace Wikifab\Widget\Search;

use HtmlArmor;
use MediaWiki\Linker\LinkRenderer;
use SearchResult;
use SpecialSearch;
use Title;
use MediaWiki\Widget\Search\SimpleSearchResultWidget;

/**
 * Renders a simple one-line result
 */
class WikifabSimpleSearchResultWidget extends SimpleSearchResultWidget {

	/**
	 * @param SearchResult $result The result to render
	 * @param string $terms Terms to be highlighted (@see SearchResult::getTextSnippet)
	 * @param int $position The result position, including offset
	 * @return string HTML
	 */
	public function render( SearchResult $result, $terms, $position ) {
		die('Render Widget');
		$title = $result->getTitle();
		$titleSnippet = $result->getTitleSnippet();
		if ( $titleSnippet ) {
			$titleSnippet = new HtmlArmor( $titleSnippet );
		} else {
			$titleSnippet = null;
		}

		$link = $this->linkRenderer->makeLink( $title, $titleSnippet );

		$redirectTitle = $result->getRedirectTitle();
		$redirect = '';
		if ( $redirectTitle !== null ) {
			$redirectText = $result->getRedirectSnippet();
			if ( $redirectText ) {
				$redirectText = new HtmlArmor( $redirectText );
			} else {
				$redirectText = null;
			}
			$redirect =
			"<span class='searchalttitle'>" .
			$this->specialSearch->msg( 'search-redirect' )->rawParams(
					$this->linkRenderer->makeLink( $redirectTitle, $redirectText )
					)->text() .
					"</span>";
		}

		return "Result : <li>{$link} {$redirect}</li>";
	}
}