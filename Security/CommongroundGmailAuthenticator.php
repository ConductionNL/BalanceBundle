<?php

// src/Security/TokenAuthenticator.php

/*
 * This authenticator authenticates against DigiSpoof
 *
 */

namespace Conduction\CommonGroundBundle\Security;

use Conduction\CommonGroundBundle\Security\User\CommongroundUser;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;

class CommongroundGmailAuthenticator extends AbstractGuardAuthenticator
{
    private $em;
    private $params;
    private $commonGroundService;
    private $csrfTokenManager;
    private $router;
    private $urlGenerator;

    public function __construct(EntityManagerInterface $em, ParameterBagInterface $params, CommonGroundService $commonGroundService, CsrfTokenManagerInterface $csrfTokenManager, RouterInterface $router, UrlGeneratorInterface $urlGenerator, SessionInterface $session)
    {
        $this->em = $em;
        $this->params = $params;
        $this->commonGroundService = $commonGroundService;
        $this->csrfTokenManager = $csrfTokenManager;
        $this->router = $router;
        $this->urlGenerator = $urlGenerator;
        $this->session = $session;
    }

    /**
     * Called on every request to decide if this authenticator should be
     * used for the request. Returning false will cause this authenticator
     * to be skipped.
     */
    public function supports(Request $request)
    {
        return 'app_user_gmail' === $request->attributes->get('_route')
            && $request->isMethod('GET') && $request->query->get('code');
    }

    /**
     * Called on every request. Return whatever credentials you want to
     * be passed to getUser() as $credentials.
     */
    public function getCredentials(Request $request)
    {
        $code = $request->query->get('code');

        $application = $this->commonGroundService->cleanUrl(['component'=>'wrc', 'type'=>'applications', 'id'=>$this->params->get('app_id')]);
        $providers = $this->commonGroundService->getResourceList(['component' => 'uc', 'type' => 'providers'], ['type' => 'gmail', 'application' => $this->params->get('app_id')])['hydra:member'];
        $provider = $providers[0];

        $backUrl = $request->query->get('backUrl', false);
        if ($backUrl) {
            $this->session->set('backUrl', $backUrl);
        }

        $redirect = $request->getUri();
        $redirect = substr($redirect, 0, strpos($redirect, '?'));

        $body = [
            'client_id'         => $provider['configuration']['app_id'],
            'client_secret'     => $provider['configuration']['secret'],
            'redirect_uri'      => $redirect,
            'code'              => $code,
            'grant_type'        => 'authorization_code',
        ];

        $client = new Client([
            // Base URI is used with relative requests
            'base_uri' => 'https://oauth2.googleapis.com',
            // You can set any number of default request options.
            'timeout'  => 2.0,
        ]);

        $response = $client->request('POST', '/token', [
            'form_params'  => $body,
            'content_type' => 'application/x-www-form-urlencoded',
        ]);

        $accessToken = json_decode($response->getBody()->getContents(), true);

        $json = base64_decode(explode('.', $accessToken['id_token'])[1]);
        $json = json_decode($json, true);

        $credentials = [
            'username'      => $json['email'],
            'email'         => $json['email'],
            'givenName'     => $json['given_name'],
            'familyName'    => $json['family_name'],
            'id'            => $json['sub'],
        ];

        if (isset($json['phoneNumber']['value'])) {
            $credentials['telephone'] = $json['phoneNumber']['value'];
        }

        $request->getSession()->set(
            Security::LAST_USERNAME,
            $credentials['username']
        );

        return $credentials;
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        $application = $this->commonGroundService->cleanUrl(['component'=>'wrc', 'type'=>'applications', 'id'=>$this->params->get('app_id')]);
        $providers = $this->commonGroundService->getResourceList(['component' => 'uc', 'type' => 'providers'], ['type' => 'gmail', 'application' => $this->params->get('app_id')])['hydra:member'];
        $tokens = $this->commonGroundService->getResourceList(['component' => 'uc', 'type' => 'tokens'], ['token' => $credentials['id'], 'provider.name' => $providers[0]['name']])['hydra:member'];

        if (!$tokens || count($tokens) < 1) {
            $users = $this->commonGroundService->getResourceList(['component'=>'uc', 'type'=>'users'], ['username'=> $credentials['username']], true, false, true, false, false);
            $users = $users['hydra:member'];

            // User dosnt exist
            if (count($users) < 1) {
                if (isset($credentials['telephone'])) {
                    $telephone = [];
                    $telephone['name'] = $credentials['telephone'];
                    $telephone['telephone'] = $credentials['telephone'];
                }

                //create email
                $emailObect = [];
                $emailObect['name'] = $credentials['email'];
                $emailObect['email'] = $credentials['email'];

                //create person
                $person = [];
                $person['givenName'] = $credentials['givenName'];
                $person['familyName'] = $credentials['familyName'];
                $person['emails'] = [$emailObect];
                if (isset($credentials['telephone'])) {
                    $person['telephones'] = [$telephone];
                }

                $person = $this->commonGroundService->createResource($person, ['component' => 'cc', 'type' => 'people']);

                //create user
                $user = [];
                $user['username'] = $credentials['username'];
                $user['password'] = $credentials['id'];
                $user['person'] = $person['@id'];
                $user = $this->commonGroundService->createResource($user, ['component' => 'uc', 'type' => 'users']);
            } else {
                $user = $users[0];
            }

            //create token
            $token = [];
            $token['token'] = $credentials['id'];
            $token['user'] = 'users/'.$user['id'];
            $token['provider'] = 'providers/'.$providers[0]['id'];
            $token = $this->commonGroundService->createResource($token, ['component' => 'uc', 'type' => 'tokens']);

            $token = $this->commonGroundService->getResourceList(['component' => 'uc', 'type' => 'tokens'], ['token' => $credentials['id'], 'provider.name' => $providers[0]['name']])['hydra:member'];
        } else {
            $token = $tokens[0];
            // Deze $urls zijn een hotfix voor niet werkende @id's op de cgb cgs
            $userUlr = $this->commonGroundService->cleanUrl(['component'=>'uc', 'type'=>'users', 'id'=>$token['user']['id']]);
            $user = $this->commonGroundService->getResource($userUlr);
        }

        $person = $this->commonGroundService->getResource($user['person']);

        $log = [];
        $log['address'] = $_SERVER['REMOTE_ADDR'];
        $log['method'] = 'Gmail';
        $log['status'] = '200';
        $log['application'] = $application;

        $this->commonGroundService->saveResource($log, ['component' => 'uc', 'type' => 'login_logs']);

        if (!in_array('ROLE_USER', $user['roles'])) {
            $user['roles'][] = 'ROLE_USER';
        }
        array_push($user['roles'], 'scope.chin.checkins.read');

        if (isset($user['organization'])) {
            return new CommongroundUser($user['username'], $credentials['id'], $person['name'], null, $user['roles'], $user['person'], $user['organization'], 'gmail');
        } else {
            return new CommongroundUser($user['username'], $credentials['id'], $person['name'], null, $user['roles'], $user['person'], null, 'gmail');
        }
    }

    public function checkCredentials($credentials, UserInterface $user)
    {
        $application = $this->commonGroundService->cleanUrl(['component'=>'wrc', 'type'=>'applications', 'id'=>$this->params->get('app_id')]);
        $providers = $this->commonGroundService->getResourceList(['component' => 'uc', 'type' => 'providers'], ['type' => 'gmail', 'application' => $this->params->get('app_id')])['hydra:member'];
        $tokens = $this->commonGroundService->getResourceList(['component' => 'uc', 'type' => 'tokens'], ['token' => $credentials['id'], 'provider.name' => $providers[0]['name']])['hydra:member'];

        if (!$tokens || count($tokens) < 1) {
            return;
        }

        // no adtional credential check is needed in this case so return true to cause authentication success
        return true;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        $backUrl = $this->session->get('backUrl', false);

        $this->session->remove('backUrl');

        if ($backUrl) {
            $this->session->set('checkingProvider', 'gmail');

            return new RedirectResponse($backUrl);
        }
        //elseif(isset($application['defaultConfiguration']['configuration']['userPage'])){
        //    return new RedirectResponse('/'.$application['defaultConfiguration']['configuration']['userPage']);
        //}
        else {
            return new RedirectResponse($this->router->generate('app_default_index'));
        }
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        return new RedirectResponse($this->router->generate('app_user_gmail'));
    }

    /**
     * Called when authentication is needed, but it's not sent.
     */
    public function start(Request $request, AuthenticationException $authException = null)
    {
        if ($this->params->get('app_subpath') && $this->params->get('app_subpath') != 'false') {
            return new RedirectResponse('/'.$this->params->get('app_subpath').$this->router->generate('app_user_digispoof', []));
        } else {
            return new RedirectResponse($this->router->generate('app_user_digispoof', [], UrlGeneratorInterface::RELATIVE_PATH));
        }
    }

    public function supportsRememberMe()
    {
        return true;
    }

    protected function getLoginUrl()
    {
        if ($this->params->get('app_subpath') && $this->params->get('app_subpath') != 'false') {
            return '/'.$this->params->get('app_subpath').$this->router->generate('app_user_digispoof', [], UrlGeneratorInterface::RELATIVE_PATH);
        } else {
            return $this->router->generate('app_user_digispoof', [], UrlGeneratorInterface::RELATIVE_PATH);
        }
    }
}
