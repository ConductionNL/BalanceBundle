<?php

// src/Security/User/WebserviceUser.php

namespace Conduction\CommonGroundBundle\Security\User;

use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class CommongroundUser implements UserInterface, EquatableInterface
{
    /* The username displayd */
    private $username;

    /* Provide UUID instead of normal password */
    private $password;

    /* Leave empty! */
    private $salt;

    /* Iether a BRP or CC person URI */
    private $roles;

    /* Always true */
    private $isActive;

    /* Iether a BRP or CC person URI */
    private $person;

    /* Iether a kvk or wrc organization OR cc organization URI */
    private $organization;

    /* Either user, organisation or application */
    private $type;

    public function __construct(string $username = '', string $password = '', string $salt = null, array $roles = [], $person = null, $organization = null, $type = null)
    {
        $this->username = $username;
        $this->password = $password;
        $this->salt = $salt;
        $this->roles = $roles;
        $this->person = $person;
        $this->organization = $organization;
        $this->isActive = true;
        $this->type = $type;
    }

    public function getRoles()
    {
        return $this->roles;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function getSalt()
    {
        return $this->salt;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getPerson()
    {
        return $this->person;
    }

    public function getOrganization()
    {
        return $this->organization;
    }

    public function getType()
    {
        return $this->type;
    }

    public function __toString()
    {
        return $this->getUsername();
    }

    public function isEnabled()
    {
        return $this->isActive;
    }

    public function eraseCredentials()
    {
    }

    // serialize and unserialize must be updated - see below
    public function serialize()
    {
        return serialize(array(
            $this->username,
            $this->password,
            $this->isActive,
            // see section on salt below
            // $this->salt,
        ));
    }

    public function unserialize($serialized)
    {
        list(
            $this->username,
            $this->password,
            $this->isActive,
            ) = unserialize($serialized);
    }

    public function isEqualTo(UserInterface $user)
    {
        return true;
    }
}
