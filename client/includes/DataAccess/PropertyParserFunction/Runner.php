<?php

namespace Wikibase\DataAccess\PropertyParserFunction;

use InvalidArgumentException;
use Language;
use Parser;
use Status;
use ValueFormatters\FormatterOptions;
use Wikibase\Client\WikibaseClient;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\SiteLink;
use Wikibase\LanguageFallbackChainFactory;
use Wikibase\Lib\PropertyLabelNotResolvedException;
use Wikibase\Lib\SnakFormatter;
use Wikibase\Lib\Store\EntityLookup;
use Wikibase\Lib\Store\SiteLinkLookup;
use Wikibase\PropertyLabelResolver;

/**
 * Handler of the {{#property}} parser function.
 *
 * @since 0.4
 *
 * @licence GNU GPL v2+
 * @author Katie Filbert < aude.wiki@gmail.com >
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 * @author Daniel Kinzler
 * @author Liangent < liangent@gmail.com >
 */
class Runner {

	/**
	 * @var EntityLookup
	 */
	private $entityLookup;

	/**
	 * @var PropertyLabelResolver
	 */
	private $propertyLabelResolver;

	/**
	 * @var RendererFactory
	 */
	private $rendererFactory;

	/**
	 * @var SiteLinkLookup
	 */
	private $siteLinkLookup;

	/**
	 * @var string
	 */
	private $siteId;

	/**
	 * @param EntityLookup $entityLookup
	 * @param PropertyLabelResolver $propertyLabelResolver
	 * @param RendererFactory $rendererFactory
	 * @param SiteLinkLookup $siteLinkLookup
	 * @param string $siteId
	 */
	public function __construct(
		EntityLookup $entityLookup,
		PropertyLabelResolver $propertyLabelResolver,
		RendererFactory $rendererFactory,
		SiteLinkLookup $siteLinkLookup,
		$siteId
	) {
		$this->entityLookup = $entityLookup;
		$this->propertyLabelResolver = $propertyLabelResolver;
		$this->rendererFactory = $rendererFactory;
		$this->siteLinkLookup = $siteLinkLookup;
		$this->siteId = $siteId;
	}

	/**
	 * Check whether variants are used in this parser run.
	 *
	 * @param Parser $parser
	 *
	 * @return bool
	 */
	public function isParserUsingVariants( Parser $parser ) {
		$parserOptions = $parser->getOptions();
		return $parser->OutputType() === Parser::OT_HTML && !$parserOptions->getInterfaceMessage()
			&& !$parserOptions->getDisableContentConversion();
	}

	/**
	 * Post-process rendered array (variant text) into wikitext to be used in pages.
	 *
	 * @param string[] $textArray
	 *
	 * @return string
	 */
	public function processRenderedArray( array $textArray ) {
		// We got arrays, so they must have already checked that variants are being used.
		$text = '';
		foreach ( $textArray as $variantCode => $variantText ) {
			$text .= "$variantCode:$variantText;";
		}
		if ( $text !== '' ) {
			$text = '-{' . $text . '}-';
		}

		return $text;
	}

	/**
	 * @param EntityId $entityId
	 * @param string $propertyLabel property label or ID (pXXX)
	 * @param Language $language
	 *
	 * @return string
	 */
	public function renderInLanguage( EntityId $entityId, $propertyLabel, Language $language ) {
		$renderer = $this->rendererFactory->newFromLanguage( $language );

		try {
			$status = $renderer->renderForEntityId( $entityId, $propertyLabel );
		} catch ( PropertyLabelNotResolvedException $ex ) {
			$status = $this->getStatusForException( $propertyLabel, $ex->getMessage() );
		} catch ( InvalidArgumentException $ex ) {
			$status = $this->getStatusForException( $propertyLabel, $ex->getMessage() );
		}

		if ( !$status->isGood() ) {
			$error = $status->getMessage()->inLanguage( $language )->text();
			return '<p class="error wikibase-error">' . $error . '</p>';
		}

		return $status->getValue();
	}

	/**
	 * @param string $propertyLabel
	 * @param string $message
	 *
	 * @return Status
	 */
	private function getStatusForException( $propertyLabel, $message ) {
		return Status::newFatal(
			'wikibase-property-render-error',
			$propertyLabel,
			$message
		);
	}

	/**
	 * @param EntityId $entityId
	 * @param string $propertyLabel property label or ID (pXXX)
	 * @param string[] $variants Variant codes
	 *
	 * @return string[], key by variant codes
	 */
	public function renderInVariants( EntityId $entityId, $propertyLabel, array $variants ) {
		$textArray = array();

		foreach ( $variants as $variantCode ) {
			$variantLanguage = Language::factory( $variantCode );
			$variantText = $this->renderInLanguage( $entityId, $propertyLabel, $variantLanguage );
			// LanguageConverter doesn't handle empty strings correctly, and it's more difficult
			// to fix the issue there, as it's using empty string as a special value.
			// Also keeping the ability to check a missing property with {{#if: }} is another reason.
			if ( $variantText !== '' ) {
				$textArray[$variantCode] = $variantText;
			}
		}

		return $textArray;
	}

	/**
	 * @param Parser $parser
	 * @param string $propertyLabel property label or ID (pXXX)
	 *
	 * @return string Wikitext
	 */
	public function runPropertyParserFunction( Parser $parser, $propertyLabel ) {
		wfProfileIn( __METHOD__ );

		// @todo use id provided as argument, if arbitrary access allowed
		$itemId = $this->getItemIdForConnectedPage( $parser );

		// @todo handle when site link is not there, such as site link / entity has been deleted...
		if ( $itemId === null ) {
			wfProfileOut( __METHOD__ );
			return '';
		}

		$rendered = $this->renderForEntityId( $parser, $itemId, $propertyLabel );
		$result = $this->buildResult( $rendered );

		wfProfileOut( __METHOD__ );
		return $result;
	}

	/**
	 * @param Parser $parser
	 * @param EntityId $entityId
	 * @param string $propertyLabel
	 *
	 * @return string
	 */
	private function renderForEntityId( Parser $parser, EntityId $entityId, $propertyLabel ) {
		$targetLanguage = $parser->getTargetLanguage();

		if ( $this->isParserUsingVariants( $parser ) && $parser->getConverterLanguage()->hasVariants() ) {
			$renderedVariantsArray = $this->renderInVariants(
				$entityId,
				$propertyLabel,
				$parser->getConverterLanguage()->getVariants()
			);

			return $this->processRenderedArray( $renderedVariantsArray );
		} else {
			return $this->renderInLanguage( $entityId, $propertyLabel, $targetLanguage );
		}
	}

	/**
	 * @param Parser $parser
	 *
	 * @return ItemId|null
	 */
	private function getItemIdForConnectedPage( Parser $parser ) {
		$title = $parser->getTitle();
		$siteLink = new SiteLink( $this->siteId, $title->getFullText() );
		$itemId = $this->siteLinkLookup->getEntityIdForSiteLink( $siteLink );

		return $itemId;
	}

	/**
	 * @param string $rendered
	 *
	 * @return array
	 */
	private function buildResult( $rendered ) {
		$result = array(
			$rendered,
			'noparse' => false, // parse wikitext
			'nowiki' => false,  // formatters take care of escaping as needed
		);

		return $result;
	}

	/**
	 * @since 0.4
	 *
	 * @param Parser $parser
	 * @param string $propertyLabel property label or ID (pXXX)
	 *
	 * @return array
	 */
	public static function render( Parser $parser, $propertyLabel ) {
		wfProfileIn( __METHOD__ );

		$runner = WikibaseClient::getDefaultInstance()->getPropertyParserFunctionRunner();
		$result = $runner->runPropertyParserFunction( $parser, $propertyLabel );

		wfProfileOut( __METHOD__ );
		return $result;
	}

}
