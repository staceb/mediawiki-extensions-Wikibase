<?php

namespace Wikibase\Lib\Test;

use DataValues\GlobeCoordinateValue;
use DataValues\StringValue;
use DataValues\TimeValue;
use Language;
use ValueFormatters\FormatterOptions;
use ValueFormatters\ValueFormatter;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\EntityFactory;
use Wikibase\Lib\SnakFormatter;
use Wikibase\Lib\OutputFormatSnakFormatterFactory;
use Wikibase\Lib\WikibaseSnakFormatterBuilders;
use Wikibase\Lib\WikibaseValueFormatterBuilders;
use Wikibase\PropertyNoValueSnak;
use Wikibase\PropertyValueSnak;

/**
 * @covers Wikibase\Lib\WikibaseSnakFormatterBuilders
 *
 * @since 0.5
 *
 * @group ValueFormatters
 * @group DataValueExtensions
 * @group WikibaseLib
 * @group Wikibase
 *
 * @licence GNU GPL v2+
 * @author Daniel Kinzler
 */
class WikibaseSnakFormatterBuildersTest extends \PHPUnit_Framework_TestCase {

	/**
	 * @param string $propertyType The property data type to use for all properties.
	 * @param EntityId $entityId   The Id of an entity to use for all entity lookups
	 *
	 * @return WikibaseSnakFormatterBuilders
	 */
	public function newBuilders( $propertyType, EntityId $entityId ) {
		$typeLookup = $this->getMock( 'Wikibase\Lib\PropertyDataTypeLookup' );
		$typeLookup->expects( $this->any() )
			->method( 'getDataTypeIdForProperty' )
			->will( $this->returnValue( $propertyType ) );

		$entity = EntityFactory::singleton()->newEmpty( $entityId->getEntityType() );
		$entity->setId( $entityId );
		$entity->setLabel( 'en', 'Label for ' . $entityId->getPrefixedId() );

		$entityLookup = $this->getMock( 'Wikibase\EntityLookup' );
		$entityLookup->expects( $this->any() )
			->method( 'getEntity' )
			->will( $this->returnValue( $entity ) );

		$lang = Language::factory( 'en' );

		$valueFormatterBuilders = new WikibaseValueFormatterBuilders( $entityLookup, $lang );
		return new WikibaseSnakFormatterBuilders( $valueFormatterBuilders, $typeLookup );
	}

	/**
	 * @covers WikibaseSnakFormatterBuilders::getSnakFormatterBuildersForFormats
	 */
	public function testGetSnakFormatterBuildersForFormats() {
		$builders = $this->newBuilders( 'string', new ItemId( 'Q5' ) );

		$buildersForFormats = $builders->getSnakFormatterBuildersForFormats();

		$requiredFormats = array(
			SnakFormatter::FORMAT_PLAIN,
			SnakFormatter::FORMAT_WIKI,
			SnakFormatter::FORMAT_HTML,
			SnakFormatter::FORMAT_HTML_WIDGET,
		);

		foreach ( $requiredFormats as $format ) {
			$this->assertArrayHasKey( $format, $buildersForFormats );
		}

		foreach ( $buildersForFormats as $builder ) {
			$this->assertTrue( is_callable( $builder ), 'callable' );
		}
	}

	/**
	 * @dataProvider buildDispatchingSnakFormatterProvider
	 * @covers WikibaseSnakFormatterBuilders::buildDispatchingSnakFormatter
	 */
	public function testBuildDispatchingSnakFormatter( $format, $options, $type, $snak, $expected ) {
		$builders = $this->newBuilders( $type, new ItemId( 'Q5' ) );
		$factory = new OutputFormatSnakFormatterFactory( $builders->getSnakFormatterBuildersForFormats() );

		$formatter = $builders->buildDispatchingSnakFormatter(
			$factory,
			$format,
			$options
		);

		$text = $formatter->formatSnak( $snak );
		$this->assertEquals( $expected, $text );
	}

	public function buildDispatchingSnakFormatterProvider() {
		$options = new FormatterOptions( array(
			ValueFormatter::OPT_LANG => 'en',
		) );

		return array(
			'plain url' => array(
				SnakFormatter::FORMAT_PLAIN,
				$options,
				'url',
				new PropertyValueSnak( 7, new StringValue( 'http://acme.com/' ) ),
				'http://acme.com/'
			),
			'wikitext no value' => array(
				SnakFormatter::FORMAT_WIKI,
				$options,
				'string',
				new PropertyNoValueSnak( 7 ),
				wfMessage( 'wikibase-snakview-snaktypeselector-novalue' )->text()
			),
			'html string' => array(
				SnakFormatter::FORMAT_HTML,
				$options,
				'string',
				new PropertyValueSnak( 7, new StringValue( 'I <3 Wikibase' ) ),
				'I &lt;3 Wikibase'
			),
			'widget item label (with entity lookup)' => array(
				SnakFormatter::FORMAT_HTML_WIDGET,
				$options,
				'wikibase-item',
				new PropertyValueSnak( 7, new EntityIdValue( new ItemId( 'Q5' ) ) ),
				'Label for Q5' // compare mock object created in newBuilders()
			),
		);
	}

}
