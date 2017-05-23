<?php

namespace Wikifab\Widget\Search;

use Category;
use Hooks;
use HtmlArmor;
use MediaWiki\Linker\LinkRenderer;
use SearchResult;
use SpecialSearch;
use Title;
use MediaWiki\Widget\Search\FullSearchResultWidget;

/**
 * Renders a 'full' multi-line search result with metadata.
 *
 *  The Title
 *  some *highlighted* *text* about the search result
 *  5KB (651 words) - 12:40, 6 Aug 2016
 */
class WikifabFullSearchResultWidget extends FullSearchResultWidget {


	/**
	 * @param SearchResult $result The result to render
	 * @param string $terms Terms to be highlighted (@see SearchResult::getTextSnippet)
	 * @param int $position The result position, including offset
	 * @return string HTML
	 */
	public function render( SearchResult $result, $terms, $position ) {
		// If the page doesn't *exist*... our search index is out of date.
		// The least confusing at this point is to drop the result.
		// You may get less results, but... on well. :P
		if ( $result->isBrokenTitle() || $result->isMissingRevision() ) {
			return '';
		}

		$link = $this->generateMainLinkHtml( $result, $terms, $position );
		// If page content is not readable, just return ths title.
		// This is not quite safe, but better than showing excerpts from
		// non-readable pages. Note that hiding the entry entirely would
		// screw up paging (really?).
		if ( !$result->getTitle()->userCan( 'read', $this->specialPage->getUser() ) ) {
			return "<li>{$link}</li>";
		}

		$redirect = $this->generateRedirectHtml( $result );
		$section = $this->generateSectionHtml( $result );
		$category = $this->generateCategoryHtml( $result );
		$date = $this->specialPage->getLanguage()->userTimeAndDate(
				$result->getTimestamp(),
				$this->specialPage->getUser()
				);
		list( $file, $desc, $thumb ) = $this->generateFileHtml( $result );
		$snippet = $result->getTextSnippet( $terms );
		if ( $snippet ) {
			$extract = "<div class='searchresult'>$snippet</div>";
		} else {
			$extract = '';
		}

		if ( $thumb === null ) {
			// If no thumb, then the description is about size
			$desc = $this->generateSizeHtml( $result );

			// Let hooks do their own final construction if desired.
			// FIXME: Not sure why this is only for results without thumbnails,
			// but keeping it as-is for now to prevent breaking hook consumers.
			$html = null;
			$score = '';
			$related = '';
			if ( !Hooks::run( 'ShowSearchHit', [
					$this->specialPage, $result, $terms,
					&$link, &$redirect, &$section, &$extract,
					&$score, &$size, &$date, &$related, &$html
			] ) ) {
				return $html;
			}
		}

		return $this->renderCard($result, $terms);

		// All the pieces have been collected. Now generate the final HTML
		$joined = "{$link} {$redirect} {$category} {$section} {$file}";
		$meta = $this->buildMeta( $desc, $date );

		if ( $thumb === null ) {
			$html =
			"<div class='mw-search-result-heading'> {$joined}</div>" .
			"{$extract} {$meta}";
		} else {
			$html =
			"<table class='searchResultImage'>" .
			"<tr>" .
			"<td style='width: 120px; text-align: center; vertical-align: top'>" .
			$thumb .
			"</td>" .
			"<td style='vertical-align: top'>" .
			"{$joined} {$extract} {$meta}" .
			"</td>" .
			"</tr>" .
			"</table>";
		}

		return "<li>{$html}</li>";
	}


	/**
	 * retreived all page content
	 *
	 */
	protected function getPageDetails($result) {
		global $sfgFormPrinter;

		$mTitle = $result->getTitle();

		$page = WikiPage::factory( $mTitle );

		$preloadContent = $page->getContent()->getWikitextForTransclusion();
		$text = $page->getContent();
		$creator = $page->getCreator();


		// remplace template :
		$preloadContent  = str_replace('{{Tuto Details', '{{Tuto SearchResult', $preloadContent);


		// get the form content
		$formTitle = Title::makeTitleSafe( SF_NS_FORM, 'Template:Tuto_Details' );

		$data = WfTutorialUtils::getArticleData( $preloadContent);

		if( ! $data ) {
			return '';
		}

		$data['title'] =$mTitle->getText();
		$data['creatorId'] = $creator->getId();
		$data['creatorUrl'] = $creator->getUserPage()->getLinkURL();
		$data['creatorName'] = $creator->getName();

		$avatar = new wAvatar( $data['creatorId'], 'm' );
		$data['creatorAvatar'] = $avatar->getAvatarURL();

		$data['creator'] = $creator->getRealName();
		$data['url'] = $mTitle->getLinkURL();

		return $this->formatResult($data);

	}

	protected function renderCard($result, $terms ) {
		$this->wikifabSearchResultFormatter = new \WikifabSearchResultFormatter();


		$this->wikifabSearchResultFormatter->init($this);
		return $this->wikifabSearchResultFormatter->showHit( $result, $terms );
	}
}