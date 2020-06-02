<?php

use PHPUnit\Framework\TestCase;
use mium\GoogleDrive\Adapters\GoogleDriveAdapter;

/**
 * UnitTests to test the GoogleDriveAdapter
 * 
 * @author umschlag
 */
class GoogleDriveTests extends TestCase
{
    // @todo write more tests
    
    /**
     * The Adpater to test
     * 
     * @var GoogleDriveAdapter|null
     */
    public $adapter = null;
    
    /**
     * Stuff to do for every test case
     */
    public function setUp() : void
    {
        parent::setUp();
    }
    
    /**
     * Just a dummy test
     */
    public function testNotReally()
    {
        $this->assertTrue(true, 'I should not fail');
    }
}