<?php
/**
 * Copyright (c) 2013 Bart Visscher <bartv@thisnet.nl>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

class Test_AppConfig extends PHPUnit_Framework_TestCase {
	public function testGetApps()
	{
		$statementMock = $this->getMock('\Doctrine\DBAL\Statement', array(), array(), '', false);
		$statementMock->expects($this->exactly(2))
			->method('fetchColumn')
			->will($this->onConsecutiveCalls('foo', false));
		$connectionMock = $this->getMock('\OC\DB\Connection', array(), array(), '', false);
		$connectionMock->expects($this->once())
			->method('executeQuery')
			->with($this->equalTo('SELECT DISTINCT `appid` FROM `*PREFIX*appconfig`'))
			->will($this->returnValue($statementMock));

		$appconfig = new OC\AppConfig($connectionMock);
		$apps = $appconfig->getApps();
		$this->assertEquals(array('foo'), $apps);
	}

	public function testGetKeys()
	{
		$statementMock = $this->getMock('\Doctrine\DBAL\Statement', array(), array(), '', false);
		$statementMock->expects($this->exactly(2))
			->method('fetchColumn')
			->will($this->onConsecutiveCalls('foo', false));
		$connectionMock = $this->getMock('\OC\DB\Connection', array(), array(), '', false);
		$connectionMock->expects($this->once())
			->method('executeQuery')
			->with($this->equalTo('SELECT `configkey` FROM `*PREFIX*appconfig` WHERE `appid` = ?'),
				$this->equalTo(array('bar')))
			->will($this->returnValue($statementMock));

		$appconfig = new OC\AppConfig($connectionMock);
		$keys = $appconfig->getKeys('bar');
		$this->assertEquals(array('foo'), $keys);
	}

	public function testGetValue()
	{
		$connectionMock = $this->getMock('\OC\DB\Connection', array(), array(), '', false);
		$connectionMock->expects($this->exactly(2))
			->method('fetchAssoc')
			->with($this->equalTo('SELECT `configvalue` FROM `*PREFIX*appconfig` WHERE `appid` = ? AND `configkey` = ?'),
				$this->equalTo(array('bar', 'red')))
			->will($this->onConsecutiveCalls(array('configvalue'=>'foo'), null));

		$appconfig = new OC\AppConfig($connectionMock);
		$value = $appconfig->getValue('bar', 'red');
		$this->assertEquals('foo', $value);
		$value = $appconfig->getValue('bar', 'red', 'def');
		$this->assertEquals('def', $value);
	}

	public function testHasKey()
	{
		$statementMock = $this->getMock('\Doctrine\DBAL\Statement', array(), array(), '', false);
		$statementMock->expects($this->exactly(3))
			->method('fetchColumn')
			->will($this->onConsecutiveCalls('foo', false, false));
		$connectionMock = $this->getMock('\OC\DB\Connection', array(), array(), '', false);
		$connectionMock->expects($this->exactly(2))
			->method('executeQuery')
			->with($this->equalTo('SELECT `configkey` FROM `*PREFIX*appconfig` WHERE `appid` = ?'),
				$this->equalTo(array('bar')))
			->will($this->returnValue($statementMock));

		$appconfig = new OC\AppConfig($connectionMock);
		$this->assertTrue($appconfig->hasKey('bar', 'foo'));
		$this->assertFalse($appconfig->hasKey('bar', 'foo'));
	}

	public function testSetValue()
	{
		$statementMock = $this->getMock('\Doctrine\DBAL\Statement', array(), array(), '', false);
		$statementMock->expects($this->exactly(4))
			->method('fetchColumn')
			->will($this->onConsecutiveCalls('foo', false, 'foo', false));
		$connectionMock = $this->getMock('\OC\DB\Connection', array(), array(), '', false);
		$connectionMock->expects($this->exactly(2))
			->method('executeQuery')
			->with($this->equalTo('SELECT `configkey` FROM `*PREFIX*appconfig` WHERE `appid` = ?'),
				$this->equalTo(array('bar')))
			->will($this->returnValue($statementMock));
		$connectionMock->expects($this->once())
			->method('insert')
			->with($this->equalTo('*PREFIX*appconfig'),
				$this->equalTo(
					array(
						'appid' => 'bar',
						'configkey' => 'moo',
						'configvalue' => 'v1',
					)
				));
		$connectionMock->expects($this->once())
			->method('update')
			->with($this->equalTo('*PREFIX*appconfig'),
				$this->equalTo(
					array(
						'configvalue' => 'v2',
					)),
				$this->equalTo(
					array(
						'appid' => 'bar',
						'configkey' => 'foo',
					)
				));

		$appconfig = new OC\AppConfig($connectionMock);
		$appconfig->setValue('bar', 'moo', 'v1');
		$appconfig->setValue('bar', 'foo', 'v2');
	}

	public function testDeleteKey()
	{
		$connectionMock = $this->getMock('\OC\DB\Connection', array(), array(), '', false);
		$connectionMock->expects($this->once())
			->method('delete')
			->with($this->equalTo('*PREFIX*appconfig'),
				$this->equalTo(
					array(
						'appid' => 'bar',
						'configkey' => 'foo',
					)
				));

		$appconfig = new OC\AppConfig($connectionMock);
		$appconfig->deleteKey('bar', 'foo');
	}

	public function testDeleteApp()
	{
		$connectionMock = $this->getMock('\OC\DB\Connection', array(), array(), '', false);
		$connectionMock->expects($this->once())
			->method('delete')
			->with($this->equalTo('*PREFIX*appconfig'),
				$this->equalTo(
					array(
						'appid' => 'bar',
					)
				));

		$appconfig = new OC\AppConfig($connectionMock);
		$appconfig->deleteApp('bar');
	}

	public function testGetValues()
	{
		$statementMock = $this->getMock('\Doctrine\DBAL\Statement', array(), array(), '', false);
		$statementMock->expects($this->exactly(4))
			->method('fetch')
			->with(\PDO::FETCH_ASSOC)
			->will($this->onConsecutiveCalls(
				array('configvalue' =>'bar', 'configkey' => 'x'),
				false,
				array('configvalue' =>'foo', 'appid' => 'y'),
				false
			));
		$connectionMock = $this->getMock('\OC\DB\Connection', array(), array(), '', false);
		$connectionMock->expects($this->at(0))
			->method('executeQuery')
			->with($this->equalTo('SELECT `configvalue`, `configkey` FROM `*PREFIX*appconfig` WHERE `appid` = ?'),
				$this->equalTo(array('foo')))
			->will($this->returnValue($statementMock));
		$connectionMock->expects($this->at(1))
			->method('executeQuery')
			->with($this->equalTo('SELECT `configvalue`, `appid` FROM `*PREFIX*appconfig` WHERE `configkey` = ?'),
				$this->equalTo(array('bar')))
			->will($this->returnValue($statementMock));

		$appconfig = new OC\AppConfig($connectionMock);
		$values = $appconfig->getValues('foo', false);
		//$this->assertEquals(array('x'=> 'bar'), $values);
		$values = $appconfig->getValues(false, 'bar');
		//$this->assertEquals(array('y'=> 'foo'), $values);
		$values = $appconfig->getValues(false, false);
		//$this->assertEquals(false, $values);
		$values = $appconfig->getValues('x', 'x');
		//$this->assertEquals(false, $values);
	}
}
