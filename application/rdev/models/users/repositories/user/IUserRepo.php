<?php
/**
 * Copyright (C) 2014 David Young
 *
 * Defines the user repository interface
 */
namespace RDev\Models\Users\Repositories\User;
use RDev\Models\ORM\Repositories;
use RDev\Models\ORM\Repositories\Exceptions as RepoExceptions;
use RDev\Models\Users;

interface IUserRepo extends Repositories\IRepo
{
    /**
     * Gets the user with the input username
     *
     * @param string $username The username to search for
     * @return Users\IUser|bool The user with the input username if successful, otherwise false
     */
    public function getByUsername($username);

    /**
     * Gets the user with the input username and hashed password
     *
     * @param string $username The username to search for
     * @param string $unhashedPassword The unhashed password to search for
     * @return Users\IUser|bool The user with the input username and password if successful, otherwise false
     */
    public function getByUsernameAndPassword($username, $unhashedPassword);
} 