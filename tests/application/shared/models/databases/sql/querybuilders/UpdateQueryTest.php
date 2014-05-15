<?php
/**
 * Copyright (C) 2014 David Young
 *
 * Tests the update query
 */
namespace RDev\Application\Shared\Models\Databases\SQL\QueryBuilders;

class UpdateQueryTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Tests adding more columns
     */
    public function testAddingMoreColumns()
    {
        $query = new UpdateQuery("users", "", array("name" => "david"));
        $query->addColumnValues(array("email" => "bar@foo.com"));
        $this->assertEquals("UPDATE users SET name = ?, email = ?", $query->getSQL());
        $this->assertEquals(array(
            array("david", \PDO::PARAM_STR),
            array("bar@foo.com", \PDO::PARAM_STR)
        ), $query->getParameters());
    }

    /**
     * Tests a basic query
     */
    public function testBasicQuery()
    {
        $query = new UpdateQuery("users", "", array("name" => "david"));
        $this->assertEquals("UPDATE users SET name = ?", $query->getSQL());
        $this->assertEquals(array(
            array("david", \PDO::PARAM_STR)
        ), $query->getParameters());
    }

    /**
     * Tests all the methods in a single, complicated query
     */
    public function testEverything()
    {
        $query = new UpdateQuery("users", "u", array("name" => "david"));
        $query->addColumnValues(array("email" => "bar@foo.com"))
            ->where("u.id = ?", "emails.userid = u.id", "emails.email = ?")
            ->orWhere("u.name = ?")
            ->andWhere("subscriptions.userid = u.id", "subscriptions.type = 'customer'")
            ->addUnnamedPlaceholderValues(array(array(18175, \PDO::PARAM_INT), "foo@bar.com", "dave"));
        $this->assertEquals("UPDATE users AS u SET name = ?, email = ? WHERE (u.id = ?) AND (emails.userid = u.id) AND (emails.email = ?) OR (u.name = ?) AND (subscriptions.userid = u.id) AND (subscriptions.type = 'customer')", $query->getSQL());
        $this->assertEquals(array(
            array("david", \PDO::PARAM_STR),
            array("bar@foo.com", \PDO::PARAM_STR),
            array(18175, \PDO::PARAM_INT),
            array("foo@bar.com", \PDO::PARAM_STR),
            array("dave", \PDO::PARAM_STR)
        ), $query->getParameters());
    }

    /**
     * Tests adding a "WHERE" clause
     */
    public function testWhere()
    {
        $query = new UpdateQuery("users", "", array("name" => "david"));
        $query->where("id = ?")
            ->addUnnamedPlaceholderValue(18175, \PDO::PARAM_INT);
        $this->assertEquals("UPDATE users SET name = ? WHERE (id = ?)", $query->getSQL());
        $this->assertEquals(array(
            array("david", \PDO::PARAM_STR),
            array(18175, \PDO::PARAM_INT)
        ), $query->getParameters());
    }
} 