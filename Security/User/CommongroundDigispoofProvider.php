<?php

// src/Security/User/CommongroundUserProvider.php

namespace Conduction\CommonGroundBundle\Security\User;

use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class CommongroundDigispoofProvider implements UserProviderInterface
{
    private $params;
    private $commonGroundService;

    public function __construct(ParameterBagInterface $params, CommonGroundService $commonGroundService)
    {
        $this->params = $params;
        $this->commonGroundService = $commonGroundService;
    }

    public function loadUserByUsername($username)
    {
        return $this->fetchUser($username);
    }

    public function refreshUser(UserInterface $user)
    {
        if (!$user instanceof CommongroundUser) {
            throw new UnsupportedUserException(
                sprintf('Instances of "%s" are not supported.', get_class($user))
            );
        }

        $username = $user->getUsername();

        return $this->fetchUser($username);
    }

    public function supportsClass($class)
    {
        return CommongroundUser::class === $class;
    }

    private function fetchUser($username)
    {

        // Aan de hand van BSN persoon ophalen uit haal centraal
        $users = $this->commonGroundService->getResourceList(['component'=>'brp', 'type'=>'ingeschrevenpersonen'], ['burgerservicenummer'=> $username], true)['hydra:member'];

        if (!$users || count($users) < 1) {
            return;
        }

        $user = $users[0];

        if (!isset($user['roles'])) {
            $user['roles'] = [];
        }

        if (!in_array('ROLE_USER', $user['roles'])) {
            $user['roles'][] = 'ROLE_USER';
        }

        return new CommongroundUser($user['burgerservicenummer'], $user['id'], null, $user['roles'], $user['naam'], null, 'person');
    }
}