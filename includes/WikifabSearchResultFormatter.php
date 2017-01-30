<?php

class WikifabSearchResultFormatter {

	private $titleMatches = null;
	private $textMatches = null;

	private $page ;

	private $out;

	private $template;

	public function init(&$page) {
		$this->page = $page;

		$this->template = $GLOBALS['egChameleonLayoutFileSearchResult'];
	}

	public function setTemplate($template) {
		$this->template = $template;
	}

	public function setTerm(&$term) {
		$this->term = $term;
	}

	public function setTitleMatches(&$matches) {
		$this->titleMatches = $matches;
	}

	public function setTextMatches(&$matches) {
		$this->textMatches = $matches;
	}

	public function render(&$out) {
		$this->out = $out;

		if ( $this->textMatches instanceof Status ) {
			$this->displayNoResultStatusMessage($this->out, $textStatus);
			return;
		}

		if ( $this->titleMatches ) {
			$titleMatchesNum = $this->titleMatches->numRows();
			$numTitleMatches = $this->titleMatches->getTotalHits();
		}
		if ( $this->textMatches ) {
			$textMatchesNum = $this->textMatches->numRows();
			$numTextMatches = $this->textMatches->getTotalHits();
		}
		$num = $titleMatchesNum + $textMatchesNum;
		$totalRes = $numTitleMatches + $numTextMatches;

		if ($num > 0) {
			$this->openResultsContainer();
			if ( $this->titleMatches) {
				$this->out->addHTML( $this->showMatches( $this->titleMatches ) );
			}
			// TODO : there is a little bug : if a result is in titleMatches an textMatches,
			// it will be displayed twice
			if ( $this->textMatches) {
				$this->out->addHTML( $this->showMatches( $this->textMatches ) );
			}
			$this->closeResultsContainer();
		}


		if ( $num === 0 ) {
			$this->displayNoResultMessage();
		}
	}

	public function displayNoResultStatusMessage($textStatus) {
		$this->out->addHTML( '<div class="error">' .
				$textStatus->getMessage( 'search-error' ) . '</div>' );
	}

	public function displayNoResultMessage( ) {
		$this->out->wrapWikiMsg( "<p class=\"mw-search-nonefound\">\n$1</p>",
				array( 'search-nonefound', wfEscapeWikiText( $this->term ) ) );
		//$this->showCreateLink( $title, $num, $titleMatches, $textMatches );
	}

	public function openResultsContainer() {
		$this->out->addHTML( "\n <!-- Begin of Results container-->\n");
		$this->out->addHTML( '<div class="container"><div class="row">');
	}
	public function closeResultsContainer() {
		$this->out->addHTML( '</div></div>');
		$this->out->addHTML( "\n <!-- End of Results container-->\n");
	}

	/**
	 * Show whole set of results
	 *
	 * @param SearchResultSet $matches
	 *
	 * @return string
	 */
	protected function showMatches( &$matches ) {
		global $wgContLang;

		$profile = new ProfileSection( __METHOD__ );
		$terms = $wgContLang->convertForSearchResult( $matches->termMatches() );

		$out = "";
		$result = $matches->next();
		while ( $result ) {
			$out .= $this->showHit( $result, $terms );
			$result = $matches->next();
		}

		// convert the whole thing to desired language variant
		$out = $wgContLang->convert( $out );

		return $out;
	}

	/**
	 * Format a single hit result
	 *
	 * @param SearchResult $result
	 * @param array $terms Terms to highlight
	 *
	 * @return string
	 */
	public function showHit( $result, $terms ) {

		$profile = new ProfileSection( __METHOD__ );

		if ( $result->isBrokenTitle() ) {
			return '';
		}

		$title = $result->getTitle();

		$titleSnippet = $result->getTitleSnippet( $terms );

		if ( $titleSnippet == '' ) {
			$titleSnippet = null;
		}

		$link_t = clone $title;

		wfRunHooks( 'ShowSearchHitTitle',
			array( &$link_t, &$titleSnippet, $result, $terms, $this ) );

		$link = Linker::linkKnown(
			$link_t,
			$titleSnippet
		);

		//If page content is not readable, just return the title.
		//This is not quite safe, but better than showing excerpts from non-readable pages
		//Note that hiding the entry entirely would screw up paging.
		if ( $this->page && !$title->userCan( 'read', $this->page->getUser() ) ) {
			return "<li>{$link}</li>\n";
		}

		// If the page doesn't *exist*... our search index is out of date.
		// The least confusing at this point is to drop the result.
		// You may get less results, but... oh well. :P
		if ( $result->isMissingRevision() ) {
			return '';
		}

		return $this->getPageDetails($result);

		// format redirects / relevant sections
		$redirectTitle = $result->getRedirectTitle();
		$redirectText = $result->getRedirectSnippet( $terms );
		$sectionTitle = $result->getSectionTitle();
		$sectionText = $result->getSectionSnippet( $terms );
		$redirect = '';

		if ( !is_null( $redirectTitle ) ) {
			if ( $redirectText == '' ) {
				$redirectText = null;
			}

			$redirect = "<span class='searchalttitle'>" .
				$this->msg( 'search-redirect' )->rawParams(
					Linker::linkKnown( $redirectTitle, $redirectText ) )->text() .
				"</span>";
		}

		$section = '';

		if ( !is_null( $sectionTitle ) ) {
			if ( $sectionText == '' ) {
				$sectionText = null;
			}

			$section = "<span class='searchalttitle'>" .
				$this->page->msg( 'search-section' )->rawParams(
					Linker::linkKnown( $sectionTitle, $sectionText ) )->text() .
				"</span>";
		}

		// format text extract
		$extract = "<div class='searchresult'>" . $result->getTextSnippet( $terms ) . "</div>";

		$lang = $this->page->getLanguage();

		// format description
		$byteSize = $result->getByteSize();
		$wordCount = $result->getWordCount();
		$timestamp = $result->getTimestamp();
		$size = $this->page->msg( 'search-result-size', $lang->formatSize( $byteSize ) )
			->numParams( $wordCount )->escaped();

		if ( $title->getNamespace() == NS_CATEGORY ) {
			$cat = Category::newFromTitle( $title );
			$size = $this->page->msg( 'search-result-category-size' )
				->numParams( $cat->getPageCount(), $cat->getSubcatCount(), $cat->getFileCount() )
				->escaped();
		}

		$date = $lang->userTimeAndDate( $timestamp, $this->page->getUser() );

		$fileMatch = '';
		// Include a thumbnail for media files...
		if ( $title->getNamespace() == NS_FILE ) {
			$img = $result->getFile();
			$img = $img ?: wfFindFile( $title );
			if ( $result->isFileMatch() ) {
				$fileMatch = "<span class='searchalttitle'>" .
					$this->page->msg( 'search-file-match' )->escaped() . "</span>";
			}
			if ( $img ) {
				$thumb = $img->transform( array( 'width' => 120, 'height' => 120 ) );
				if ( $thumb ) {
					$desc = $this->page->msg( 'parentheses' )->rawParams( $img->getShortDesc() )->escaped();
					// Float doesn't seem to interact well with the bullets.
					// Table messes up vertical alignment of the bullets.
					// Bullets are therefore disabled (didn't look great anyway).
					return "<li>" .
						'<table class="searchResultImage">' .
						'<tr>' .
						'<td style="width: 120px; text-align: center; vertical-align: top;">' .
						$thumb->toHtml( array( 'desc-link' => true ) ) .
						'</td>' .
						'<td style="vertical-align: top;">' .
						"{$link} {$redirect} {$section} {$fileMatch}" .
						$extract .
						"<div class='mw-search-result-data'>{$desc} - {$date}</div>" .
						'</td>' .
						'</tr>' .
						'</table>' .
						"</li>\n";
				}
			}
		}

		$html = null;

		$score = '';
		if ( wfRunHooks( 'ShowSearchHit', array(
			$this, $result, $terms,
			&$link, &$redirect, &$section, &$extract,
			&$score, &$size, &$date, &$related,
			&$html
		) ) ) {
			$html = "<li><div class='mw-search-result-heading'>" .
				"{$link} {$redirect} {$section} {$fileMatch}</div> {$extract}\n" .
				"<div class='mw-search-result-data'>{$size} - {$date}</div>" .
				"</li>\n";
		}

		return $html;
	}


	/**
	 * retreived all page content
	 *
	 */
	public function getPageDetails($result) {
		global $sfgFormPrinter;

		$mTitle = $result->getTitle();

		$page = WikiPage::factory( $mTitle );

		$preloadContent = $page->getContent()->getWikitextForTransclusion();
		$text = $page->getText();
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

	public function formatResult($content) {
		$wgScriptPath = $GLOBALS['wgScriptPath'];
		$out = file_get_contents($this->template);
		$content['ROOT_URL'] = $wgScriptPath . '/';

		foreach ($content as $key => $value) {

			if($key == 'Main_Picture') {
				// image
				$file = wfFindFile( $value );
				if($file) {
					$fileUrl = $file->getUrl();
					// if possible, we use thumbnail
					$params = ['width' => 400];

					$mto = $file->transform( $params );
					if ( $mto && !$mto->isError() ) {
						// thumb Ok, change the URL to point to a thumbnail.
						$fileUrl = wfExpandUrl( $mto->getUrl(), PROTO_RELATIVE );
					}
					$out = str_replace("{{" . $key . "::url}}", $fileUrl, $out);
				}
			}
			$out = str_replace("{{" . $key . "}}", $value, $out);
		}
		return $out;
	}

}