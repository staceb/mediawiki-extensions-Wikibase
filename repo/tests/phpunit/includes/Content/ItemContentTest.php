<?php

namespace Wikibase\Repo\Tests\Content;

use DataValues\StringValue;
use Diff\DiffOp\Diff\Diff;
use Diff\DiffOp\DiffOpAdd;
use Diff\DiffOp\DiffOpRemove;
use InvalidArgumentException;
use Title;
use Wikibase\Content\EntityInstanceHolder;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityRedirect;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Diff\EntityDiff;
use Wikibase\DataModel\Services\Lookup\InMemoryDataTypeLookup;
use Wikibase\DataModel\Services\Lookup\PropertyDataTypeLookup;
use Wikibase\DataModel\SiteLink;
use Wikibase\DataModel\SiteLinkList;
use Wikibase\DataModel\Snak\PropertyNoValueSnak;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\EntityContent;
use Wikibase\ItemContent;
use Wikibase\Repo\Content\EntityContentDiff;
use Wikibase\Repo\Content\ItemHandler;
use Wikibase\Repo\Search\Elastic\Fields\ItemFieldDefinitions;
use Wikibase\Repo\WikibaseRepo;

/**
 * @covers Wikibase\ItemContent
 * @covers Wikibase\EntityContent
 *
 * @group Wikibase
 * @group WikibaseItem
 * @group WikibaseContent
 *
 * @group Database
 *
 * @license GPL-2.0+
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 * @author Katie Filbert < aude.wiki@gmail.com >
 * @author Daniel Kinzler
 */
class ItemContentTest extends EntityContentTest {

	/**
	 * @return ItemId
	 */
	protected function getDummyId() {
		return new ItemId( 'Q100' );
	}

	/**
	 * @return string
	 */
	protected function getEntityType() {
		return Item::ENTITY_TYPE;
	}

	/**
	 * @param ItemId|null $itemId
	 *
	 * @throws InvalidArgumentException
	 * @return ItemContent
	 */
	protected function newEmpty( EntityId $itemId = null ) {
		$empty = new ItemContent( new EntityInstanceHolder( new Item() ) );

		if ( $itemId !== null ) {
			$empty->getItem()->setId( $itemId );
		}

		return $empty;
	}

	/**
	 * @param ItemId $itemId
	 * @param ItemId $targetId
	 *
	 * @return ItemContent
	 */
	private function newRedirect( ItemId $itemId, ItemId $targetId ) {
		$nsLookup = WikibaseRepo::getDefaultInstance()->getEntityNamespaceLookup();
		$itemNs = $nsLookup->getEntityNamespace( 'item' );

		$title = $this->getMock( Title::class );
		$title->expects( $this->any() )
			->method( 'getFullText' )
			->will( $this->returnValue( $targetId->getSerialization() ) );
		$title->expects( $this->any() )
			->method( 'getText' )
			->will( $this->returnValue( $targetId->getSerialization() ) );
		$title->expects( $this->any() )
			->method( 'isRedirect' )
			->will( $this->returnValue( false ) );
		$title->expects( $this->any() )
			->method( 'getNamespace' )
			->will( $this->returnValue( $itemNs ) );
		$title->expects( $this->any() )
			->method( 'equals' )
			->will( $this->returnCallback( function( Title $other ) use ( $targetId ) {
				// XXX: Ignores namespaces
				return $other->getText() === $targetId->getSerialization();
			} ) );
		$title->expects( $this->any() )
			->method( 'getLinkURL' )
			->will( $this->returnValue( 'http://foo.bar/' . $targetId->getSerialization() ) );

		return ItemContent::newFromRedirect( new EntityRedirect( $itemId, $targetId ), $title );
	}

	public function getTextForSearchIndexProvider() {
		$itemContent = $this->newEmpty();
		$itemContent->getEntity()->setLabel( 'en', "cake" );
		$itemContent->getEntity()->getSiteLinkList()->addNewSiteLink( 'dewiki', 'Berlin' );

		return array(
			array( $itemContent, "cake\nBerlin" ),
		);
	}

	public function providePageProperties() {
		$cases = parent::providePageProperties();

		$contentLinkStub = $this->newEmpty( $this->getDummyId() );
		$contentLinkStub->getEntity()->getSiteLinkList()->addNewSiteLink( 'enwiki', 'Foo' );

		$cases['sitelinks'] = array(
			$contentLinkStub,
			array( 'wb-claims' => 0, 'wb-sitelinks' => 1 )
		);

		// @todo this is needed in PropertyContentTest as well
		//       once we have statements in properties
		$contentWithClaim = $this->newEmpty( $this->getDummyId() );
		$snak = new PropertyNoValueSnak( 83 );
		$guid = '$testing$';
		$contentWithClaim->getEntity()->getStatements()->addNewStatement( $snak, null, null, $guid );

		$cases['claims'] = array(
			$contentWithClaim,
			array( 'wb-claims' => 1 )
		);

		return $cases;
	}

	/**
	 * @return EntityContent
	 */
	private function getItemContentWithClaim() {
		$itemContent = $this->newEmpty();
		$item = $itemContent->getItem();

		$item->getStatements()->addNewStatement(
			new PropertyNoValueSnak( new PropertyId( 'P11' ) ),
			null,
			null,
			'Whatever'
		);

		return $itemContent;
	}

	/**
	 * @return EntityContent
	 */
	private function getItemContentWithIdentifierClaims() {

		$item = new Item( new ItemId( 'Q2' ) );
		$snak = new PropertyValueSnak( new PropertyId( 'P11' ), new StringValue( 'Tehran' ) );
		$guid = $item->getId()->getSerialization() . '$D8404CDA-25E4-4334-AG93-A3290BCD9C0P';
		$item->getStatements()->addNewStatement( $snak, null, null, $guid );

		$itemContent = $this->getMockBuilder( ItemContent::class )
			->setConstructorArgs( [ new EntityInstanceHolder( $item ) ] )
			->setMethods( [ 'getContentHandler' ] )
			->getMock();

		$handler = $this->getItemHandler();
		$itemContent->expects( $this->any() )
			->method( 'getContentHandler' )
			->will( $this->returnValue( $handler ) );

		return $itemContent;
	}

	/**
	 * @return PropertyDataTypeLookup
	 */
	private function getPropertyDataTypeLookup() {
		$dataTypeLookup = new InMemoryDataTypeLookup();

		$dataTypeLookup->setDataTypeForProperty( new PropertyId( 'P11' ), 'external-id' );

		return $dataTypeLookup;
	}

	/**
	 * @return ItemHandler
	 */
	private function getItemHandler() {
		$wikibaseRepo = WikibaseRepo::getDefaultInstance();
		$itemFieldDefinitions = $this->getMockBuilder( ItemFieldDefinitions::class )
			->disableOriginalConstructor()
			->getMock();

		return new ItemHandler(
			$wikibaseRepo->getStore()->newEntityPerPage(),
			$wikibaseRepo->getStore()->getTermIndex(),
			$wikibaseRepo->getEntityContentDataCodec(),
			$wikibaseRepo->getEntityConstraintProvider(),
			$wikibaseRepo->getValidatorErrorLocalizer(),
			$wikibaseRepo->getEntityIdParser(),
			$wikibaseRepo->getStore()->newSiteLinkStore(),
			$wikibaseRepo->getEntityIdLookup(),
			$wikibaseRepo->getLanguageFallbackLabelDescriptionLookupFactory(),
			$itemFieldDefinitions,
			$this->getPropertyDataTypeLookup()
		);
	}

	/**
	 * @return EntityContent
	 */
	private function getItemContentWithSiteLink() {
		$itemContent = $this->newEmpty();
		$item = $itemContent->getItem();

		$item->setSiteLinkList( new SiteLinkList( array(
			new SiteLink( 'enwiki', 'Foo' )
		) ) );

		return $itemContent;
	}

	public function provideGetEntityPageProperties() {
		$cases = parent::provideGetEntityPageProperties();

		// expect wb-sitelinks => 0 for all inherited cases
		foreach ( $cases as &$case ) {
			$case[1]['wb-sitelinks'] = 0;
			$case[1]['wb-identifiers'] = 0;
		}

		$cases['redirect'] = array(
			ItemContent::newFromRedirect(
				new EntityRedirect( new ItemId( 'Q1' ), new ItemId( 'Q2' ) ),
				$this->getMock( Title::class )
			),
			array()
		);

		$cases['claims'] = array(
			$this->getItemContentWithClaim(),
			array(
				'wb-claims' => 1,
				'wb-identifiers' => 0,
				'wb-sitelinks' => 0,
			)
		);

		$cases['sitelinks'] = array(
			$this->getItemContentWithSiteLink(),
			array(
				'wb-claims' => 0,
				'wb-identifiers' => 0,
				'wb-sitelinks' => 1,
			)
		);

		$cases['identifiers'] = [
			$this->getItemContentWithIdentifierClaims(),
			[
				'wb-claims' => 1,
				'wb-identifiers' => 1,
				'wb-sitelinks' => 0,
			]
		];

		return $cases;
	}

	public function diffProvider() {
		$cases = parent::diffProvider();

		$q10 = new ItemId( 'Q10' );
		$empty = $this->newEmpty( $q10 );

		$spam = $this->newEmpty( $q10 );
		$spam->getEntity()->setLabel( 'en', 'Spam' );

		$redir = $this->newRedirect( $q10, new ItemId( 'Q17' ) );
		$redirTarget = 'Q17';

		$emptyToRedirDiff = new EntityContentDiff(
			new EntityDiff( array() ),
			new Diff( array(
				'redirect' => new DiffOpAdd( $redirTarget ),
			), true ),
			$this->getEntityType()
		);

		$spamToRedirDiff = new EntityContentDiff(
			new EntityDiff( array(
				'label' => new Diff(
						array( 'en' => new DiffOpRemove( 'Spam' ) )
					),
			) ),
			new Diff( array(
				'redirect' => new DiffOpAdd( $redirTarget ),
			), true ),
			$this->getEntityType()
		);

		$redirToSpamDiff = new EntityContentDiff(
			new EntityDiff( array(
				'label' => new Diff(
						array( 'en' => new DiffOpAdd( 'Spam' ) )
					),
			) ),
			new Diff( array(
				'redirect' => new DiffOpRemove( $redirTarget ),
			), true ),
			$this->getEntityType()
		);

		$cases['same redir'] = array( $redir, $redir, new EntityContentDiff(
			new EntityDiff(),
			new Diff(),
			$this->getEntityType()
		) );
		$cases['empty to redir'] = array( $empty, $redir, $emptyToRedirDiff );
		$cases['entity to redir'] = array( $spam, $redir, $spamToRedirDiff );
		$cases['redir to entity'] = array( $redir, $spam, $redirToSpamDiff );

		return $cases;
	}

	public function patchedCopyProvider() {
		$cases = parent::patchedCopyProvider();

		$q10 = new ItemId( 'Q10' );
		$empty = $this->newEmpty( $q10 );

		$spam = $this->newEmpty( $q10 );
		$spam->getEntity()->setLabel( 'en', 'Spam' );

		$redirTarget = 'Q17';
		$redir = $this->newRedirect( $q10, new ItemId( $redirTarget ) );

		$emptyToRedirDiff = new EntityContentDiff(
			new EntityDiff( array() ),
			new Diff( array(
				'redirect' => new DiffOpAdd( $redirTarget ),
			), true ),
			$this->getEntityType()
		);

		$spamToRedirDiff = new EntityContentDiff(
			new EntityDiff( array(
				'label' => new Diff(
						array( 'en' => new DiffOpRemove( 'Spam' ) )
					),
			) ),
			new Diff( array(
				'redirect' => new DiffOpAdd( $redirTarget ),
			), true ),
			$this->getEntityType()
		);

		$redirToSpamDiff = new EntityContentDiff(
			new EntityDiff( array(
				'label' => new Diff(
						array( 'en' => new DiffOpAdd( 'Spam' ) )
					),
			) ),
			new Diff( array(
				'redirect' => new DiffOpRemove( $redirTarget ),
			), true ),
			$this->getEntityType()
		);

		$cases['empty to redir'] = array( $empty, $emptyToRedirDiff, $redir );
		$cases['entity to redir'] = array( $spam, $spamToRedirDiff, $redir );
		$cases['redir to entity'] = array( $redir, $redirToSpamDiff, $spam );
		$cases['redir with entity clash'] = array( $spam, $emptyToRedirDiff, null );

		return $cases;
	}

	public function copyProvider() {
		$cases = parent::copyProvider();

		$redir = $this->newRedirect( new ItemId( 'Q5' ), new ItemId( 'Q7' ) );

		$cases['redirect'] = array( $redir );

		return $cases;
	}

	public function equalsProvider() {
		$cases = parent::equalsProvider();

		$redir = $this->newRedirect( new ItemId( 'Q5' ), new ItemId( 'Q7' ) );

		$labels1 = $this->newEmpty();
		$labels1->getEntity()->setLabel( 'en', 'Foo' );

		$cases['same redirect'] = array( $redir, $redir, true );
		$cases['redirect vs labels'] = array( $redir, $labels1, false );
		$cases['labels vs redirect'] = array( $labels1, $redir, false );

		return $cases;
	}

	public function testGetParserOutput_redirect() {
		$content = $this->newRedirect( new ItemId( 'Q5' ), new ItemId( 'Q123' ) );

		$title = Title::newFromText( 'Foo' );
		$parserOutput = $content->getParserOutput( $title );

		$html = $parserOutput->getText();

		$this->assertContains( '<div class="redirectMsg">', $html, 'redirect message' );
		$this->assertContains( '<a href="', $html, 'redirect target link' );
		$this->assertContains( 'Q123</a>', $html, 'redirect target label' );
	}

	public function provideGetEntityId() {
		$q11 = new ItemId( 'Q11' );
		$q12 = new ItemId( 'Q12' );

		$cases = array();
		$cases['entity id'] = array( $this->newEmpty( $q11 ), $q11 );
		$cases['redirect id'] = array( $this->newRedirect( $q11, $q12 ), $q11 );

		return $cases;
	}

	public function entityRedirectProvider() {
		$cases = parent::entityRedirectProvider();

		$cases['redirect'] = array(
			$this->newRedirect( new ItemId( 'Q11' ), new ItemId( 'Q12' ) ),
			new EntityRedirect( new ItemId( 'Q11' ), new ItemId( 'Q12' ) )
		);

		return $cases;
	}

	public function testIsEmpty_emptyItem() {
		$content = ItemContent::newFromItem( new Item() );
		$this->assertTrue( $content->isEmpty() );
	}

	public function testIsEmpty_nonEmptyItem() {
		$item = new Item();
		$item->setLabel( 'en', '~=[,,_,,]:3' );
		$content = ItemContent::newFromItem( $item );
		$this->assertFalse( $content->isEmpty() );
	}

}
