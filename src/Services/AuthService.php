<?php
/**
 * This file is part of bigperson/exchange1c package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Bigperson\Exchange1C\Services;

use Bigperson\Exchange1C\Config;
use Bigperson\Exchange1C\Exceptions\Exchange1CException;
use Illuminate\Contracts\Session\Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Illuminate\Session\Store as SessionStore;

/**
 * Class AuthService.
 */
class AuthService
{
    public const SESSION_KEY = 'cml_import';

    /**
     * @var Request
     */
    private $request;
    /**
     * @var Config
     */
    private $config;

    /**
     * @var null|SessionInterface|Session
     */
    private $session;

    /**
     * AuthService constructor.
     *
     * @param Request $request
     * @param Config  $config
     */
    public function __construct(Request $request, Config $config)
    {
        $this->request = $request;
        $this->setSession();
        $this->config = $config;
    }

    /**
     * @throws Exchange1CException
     *
     * @return string
     */
    public function checkAuth()
    {
        if (
            $this->request->server->get('PHP_AUTH_USER') === $this->config->getLogin() &&
            $this->request->server->get('PHP_AUTH_PW') === $this->config->getPassword()
        ) {
            $this->session->save();
            $response = "success\n";
            $response .= "laravel_session\n";
            $response .= $this->session->getId()."\n";
            $response .= $this->session->getId();
            #$response .= 'timestamp='.time();
            if ($this->session instanceof SessionInterface) {
                $this->session->set(self::SESSION_KEY.'_auth', $this->config->getLogin());
            } elseif ($this->session instanceof Session) {
                $this->session->put(self::SESSION_KEY.'_auth', $this->config->getLogin());
            } else {
                throw new Exchange1CException(sprintf('Session is not insatiable interface %s or %s', SessionInterface::class, Session::class));
            }
        } else {
            $response = "failure\n";
        }

        return $response;
    }

    /**
     * @throws Exchange1CException
     */
    public function auth(): void
    {
        // пользовательская настройка авторизации
        $configAuth = $this->config->getAuth();

        if ($configAuth['custom']) {
            $method = $configAuth['callback'];
            if (!$method($this->config->getLogin(), $this->config->getPassword())) {
                throw new Exchange1CException('auth error');
            }
        } else {
            $login = $this->config->getLogin();
            $user = $this->session->get(self::SESSION_KEY.'_auth', null);

            if (!$user || $user != $login) {
                throw new Exchange1CException('auth error');
            }
        }
    }

    private function setSession(): void
    {
        if (!$this->request->hasSession()) {
            $session = app()->make(SessionStore::class);
            $session->start();
            $this->request->setLaravelSession($session);
        }

        $this->session = $this->request->getSession();
    }
}
